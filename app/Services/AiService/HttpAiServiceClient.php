<?php

namespace App\Services\AiService;

use App\Contracts\AiServiceClient;
use App\DTOs\AiAnalysisResult;
use App\DTOs\AiHealthResult;
use App\DTOs\AiSearchResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HttpAiServiceClient implements AiServiceClient
{
    public function health(): AiHealthResult
    {
        try {
            $response = $this->client()
                ->timeout(10)
                ->retry(0)
                ->get('/health')->throw();

            return AiHealthResult::fromResponse($response->json());
        } catch (\Throwable $e) {
            Log::warning('AI health check failed', [
                'error' => $e->getMessage(),
            ]);

            return AiHealthResult::unavailable();
        }
    }

    public function analyzeArtwork(
        int $artworkId,
        string $title = '',
        string $description = '',
        array $existingTags = [],
        ?string $imagePath = null,
    ): AiAnalysisResult {
        $requestId = $this->requestId();
        $multipart = [
            ['name' => 'artwork_id', 'contents' => (string) $artworkId],
            ['name' => 'title', 'contents' => $title],
            ['name' => 'description', 'contents' => $description],
            ['name' => 'existing_tags', 'contents' => implode(',', $existingTags)],
        ];

        if ($imagePath && is_file($imagePath)) {
            $multipart[] = [
                'name' => 'image',
                'contents' => fopen($imagePath, 'r'),
                'filename' => basename($imagePath),
            ];
        }

        Log::info('AI analyze request', [
            'request_id' => $requestId,
            'artwork_id' => $artworkId,
            'has_image' => $imagePath !== null,
        ]);

        $response = $this->client()
            ->asMultipart()
            ->post('/api/v1/artworks/analyze', $multipart);

        $response->throw();

        return AiAnalysisResult::fromResponse($response->json());
    }

    public function indexArtwork(
        int $artworkId,
        array $metadata,
        string $searchableText,
        array $payload,
        string $contentHash = '',
        string $idempotencyKey = '',
    ): array {
        $requestId = $this->requestId();

        Log::info('AI index request', [
            'request_id' => $requestId,
            'artwork_id' => $artworkId,
        ]);

        $response = $this->idempotentClient()
            ->post('/api/v1/artworks/index', [
                'artwork_id' => $artworkId,
                'metadata' => $metadata,
                'searchable_text' => $searchableText,
                'payload' => $payload,
                'content_hash' => $contentHash,
                'idempotency_key' => $idempotencyKey,
            ]);

        $response->throw();

        return $response->json();
    }

    public function deleteArtworkIndex(int $artworkId): array
    {
        $requestId = $this->requestId();

        Log::info('AI delete index request', [
            'request_id' => $requestId,
            'artwork_id' => $artworkId,
        ]);

        $response = $this->idempotentClient()
            ->timeout(20)
            ->delete("/api/v1/artworks/{$artworkId}/index");

        $response->throw();

        return $response->json();
    }

    public function inspectArtworkIndex(int $artworkId, ?string $pointId = null): array
    {
        $data = $this->client()
            ->connectTimeout(3)
            ->timeout(10)
            ->get("/api/v1/artworks/{$artworkId}/index/inspect", $pointId === null ? [] : ['point_id' => $pointId])
            ->throw()
            ->json();

        if (! is_array($data)) {
            throw new \UnexpectedValueException('Invalid artwork index inspection response.');
        }

        return $data;
    }

    public function search(string $query, int $limit = 20, array $filters = []): AiSearchResult
    {
        $response = $this->client()
            ->timeout(15)
            ->retry(0)
            ->post('/api/v1/search', [
                'query' => $query,
                'limit' => $limit,
                'filters' => (object) $filters,
            ]);

        $response->throw();

        return AiSearchResult::fromResponse($response->json());
    }

    public function similar(int $artworkId, int $limit = 20, array $filters = []): AiSearchResult
    {
        $response = $this->client()
            ->timeout(15)
            ->retry(0)
            ->post('/api/v1/similar', [
                'artwork_id' => $artworkId,
                'limit' => $limit,
                'filters' => (object) $filters,
            ]);

        $response->throw();

        return AiSearchResult::fromResponse($response->json());
    }

    public function rerank(string $query, array $artworkIds, int $limit = 20): AiSearchResult
    {
        $url = config('ai.colbert_url');
        if (! is_string($url) || trim($url) === '') {
            throw new \LogicException('ColBERT URL is not configured.');
        }

        $timeout = max(0.1, min(0.8, (float) config('ai.colbert_timeout', 0.5)));
        $request = Http::baseUrl($url)
            ->acceptJson()
            ->withoutRedirecting()
            ->retry(1)
            ->connectTimeout(min(0.2, $timeout))
            ->timeout($timeout);

        // Let libcurl return at the deadline while its DNS resolver finishes in the background.
        if (defined('CURLOPT_QUICK_EXIT')) {
            $request->withOptions(['curl' => [CURLOPT_QUICK_EXIT => true]]);
        }

        $response = $request->post('/api/v1/rerank', [
            'query' => $query,
            'artwork_ids' => $artworkIds,
            'limit' => $limit,
        ]);

        $data = $response->throw()->json();
        if (! $response->successful()
            || ! is_array($data)
            || ! is_bool($data['reranked'] ?? null)
            || ! is_array($data['results'] ?? null)
            || ! array_is_list($data['results'])) {
            throw new \UnexpectedValueException('Invalid ColBERT response.');
        }

        foreach ($data['results'] as $hit) {
            $score = $hit['score'] ?? null;
            if ((! is_float($score) && ! is_int($score)) || ! is_finite($score)) {
                throw new \UnexpectedValueException('Invalid ColBERT score.');
            }
        }

        return AiSearchResult::fromResponse($data);
    }

    private function client(): PendingRequest
    {
        $request = Http::baseUrl(config('ai.service_url'))
            ->timeout(config('ai.timeout'))
            ->connectTimeout(config('ai.connect_timeout'))
            ->acceptJson();

        $apiKey = config('ai.api_key');
        if ($apiKey) {
            $request->withHeaders(['X-AI-Service-Key' => $apiKey]);
        }

        return $request;
    }

    private function idempotentClient(): PendingRequest
    {
        return $this->client()->retry(
            config('ai.retry_times'),
            config('ai.retry_sleep'),
        );
    }

    private function requestId(): string
    {
        return Str::uuid()->toString();
    }
}
