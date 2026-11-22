<?php

namespace App\Services\AiService;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LogicException;

class ColbertDocumentClient
{
    public function get(int $artworkId): array
    {
        return $this->request('GET', $artworkId);
    }

    public function upsert(
        int $artworkId,
        string $searchableText,
        ?string $expectedTextHash = null,
        ?string $expectedVersion = null,
    ): array {
        return $this->request('PUT', $artworkId, ['json' => [
            'searchable_text' => $searchableText,
            'expected_previous_hash' => $expectedTextHash,
            'expected_previous_version' => $expectedVersion,
        ]]);
    }

    public function delete(int $artworkId, string $expectedTextHash, string $expectedVersion): array
    {
        return $this->request('DELETE', $artworkId, ['query' => [
            'expected_previous_hash' => $expectedTextHash,
            'expected_previous_version' => $expectedVersion,
        ]]);
    }

    private function request(string $method, int $artworkId, array $options = []): array
    {
        if (! config('ai.colbert_sync.enabled')) {
            throw new LogicException('ColBERT document operations are disabled.');
        }

        $url = config('ai.colbert_sync.url');
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new LogicException('ColBERT document URL is invalid.');
        }

        $timeout = max(0.1, min(10.0, (float) config('ai.colbert_sync.timeout')));
        $connectTimeout = max(0.1, min(2.0, $timeout, (float) config('ai.colbert_sync.connect_timeout')));
        $request = Http::baseUrl($url)
            ->acceptJson()
            ->withoutRedirecting()
            ->retry(1)
            ->connectTimeout($connectTimeout)
            ->timeout($timeout)
            ->withHeaders(['X-ColBERT-Sync-Key' => $this->key()]);

        if (defined('CURLOPT_QUICK_EXIT')) {
            $request->withOptions(['curl' => [CURLOPT_QUICK_EXIT => true]]);
        }

        try {
            $response = $request->send($method, "/api/v1/documents/{$artworkId}", $options);
        } catch (ConnectionException) {
            throw new ColbertDocumentException('Could not connect to ColBERT document API or request timed out.');
        }

        if (! $response->successful()) {
            $status = $response->status();
            $message = match ($status) {
                401, 403 => 'ColBERT document authorization was rejected.',
                409 => 'ColBERT document version conflict.',
                503 => 'ColBERT document API is unavailable.',
                default => 'ColBERT document API rejected the request.',
            };

            throw new ColbertDocumentException($message, $status);
        }

        $data = $response->json();
        if (! is_array($data) || ($data['artwork_id'] ?? null) !== $artworkId || ! is_bool($data['exists'] ?? null)
            || ! array_key_exists('content_hash', $data) || ! array_key_exists('version', $data)
            || ! $this->validRevision($data)) {
            throw new ColbertDocumentException('Invalid ColBERT document response.', 502);
        }

        $result = array_intersect_key($data, array_flip(['artwork_id', 'exists', 'content_hash', 'version']));
        if ($method === 'GET') {
            return $result;
        }

        $status = $data['status'] ?? null;
        $allowed = $method === 'PUT' && $result['exists'] ? ['created', 'updated', 'unchanged'] : [];
        if ($method === 'DELETE' && ! $result['exists']) {
            $allowed = ['deleted', 'absent'];
        }
        if (! in_array($status, $allowed, true)) {
            throw new ColbertDocumentException('Invalid ColBERT document response.', 502);
        }

        return $result + ['status' => $status];
    }

    private function key(): string
    {
        $key = config('ai.colbert_sync.api_key');
        $file = config('ai.colbert_sync.api_key_file');
        if ($key === null && is_string($file) && $file !== '') {
            $contents = @file_get_contents($file);
            $key = is_string($contents) ? rtrim($contents, "\r\n") : null;
        }

        if (! is_string($key) || ! preg_match('/\A[!-~]+\z/', $key)) {
            throw new LogicException('ColBERT document API key is missing or invalid.');
        }

        return $key;
    }

    private function validRevision(array $data): bool
    {
        $hash = $data['content_hash'];
        $version = $data['version'];
        $validVersion = is_string($version) && preg_match('/\A[0-9a-f]{32}\z/', $version);

        return $data['exists']
            ? $validVersion && is_string($hash) && preg_match('/\A[0-9a-f]{64}\z/', $hash)
            : $hash === null && ($version === null || $validVersion);
    }
}
