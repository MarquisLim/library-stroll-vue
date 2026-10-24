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

    public function search(string $query, int $limit = 20, array $filters = []): AiSearchResult
    {
        $response = $this->client()
            ->timeout(15)
            ->retry(0)
            ->post('/api/v1/search', [
                'query' => $query,
                'limit' => $limit,
                'filters' => $filters,
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
                'filters' => $filters,
            ]);

        $response->throw();

        return AiSearchResult::fromResponse($response->json());
    }

    public function rerank(string $query, array $artworkIds, int $limit = 20): AiSearchResult
    {
        $response = $this->client()
            ->retry(0)
            ->post('/api/v1/rerank', [
                'query' => $query,
                'artwork_ids' => $artworkIds,
                'limit' => $limit,
            ]);

        $response->throw();

        return AiSearchResult::fromResponse($response->json());
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
