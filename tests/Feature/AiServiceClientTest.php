<?php

namespace Tests\Feature;

use App\Contracts\AiServiceClient;
use App\DTOs\AiHealthResult;
use App\DTOs\AiSearchResult;
use App\Services\AiService\HttpAiServiceClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiServiceClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.enabled' => true,
            'ai.service_url' => 'http://127.0.0.1:8001',
            'ai.api_key' => '',
            'ai.timeout' => 10,
            'ai.connect_timeout' => 5,
            'ai.retry_times' => 1,
            'ai.retry_sleep' => 100,
        ]);
    }

    public function test_container_resolves_ai_service_client(): void
    {
        $client = app(AiServiceClient::class);
        $this->assertInstanceOf(HttpAiServiceClient::class, $client);
    }

    public function test_health_success(): void
    {
        Http::fake([
            '*/health' => Http::response([
                'status' => 'ok',
                'services' => ['ollama' => 'ok', 'qdrant' => 'ok'],
            ]),
        ]);

        $result = app(AiServiceClient::class)->health();

        $this->assertInstanceOf(AiHealthResult::class, $result);
        $this->assertTrue($result->isOk());
        $this->assertSame('ok', $result->ollama);
    }

    public function test_health_returns_unavailable_on_connection_error(): void
    {
        Http::fake([
            '*/health' => Http::response(status: 500),
        ]);

        $result = app(AiServiceClient::class)->health();

        $this->assertSame('unavailable', $result->status);
    }

    public function test_health_returns_unavailable_on_timeout(): void
    {
        Http::fake([
            '*/health' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            },
        ]);

        $result = app(AiServiceClient::class)->health();

        $this->assertSame('unavailable', $result->status);
    }

    public function test_search_success(): void
    {
        Http::fake([
            '*/api/v1/search' => Http::response([
                'results' => [
                    ['artwork_id' => 1, 'score' => 0.95, 'rank' => 1],
                    ['artwork_id' => 2, 'score' => 0.80, 'rank' => 2],
                ],
                'model' => 'qwen3-embedding:0.6b',
                'latency_ms' => 150,
            ]),
        ]);

        $result = app(AiServiceClient::class)->search('cat painting');

        $this->assertInstanceOf(AiSearchResult::class, $result);
        $this->assertCount(2, $result->results);
        $this->assertSame([1, 2], $result->artworkIds());
        $this->assertSame(150, $result->latencyMs);
    }

    public function test_similar_success(): void
    {
        Http::fake([
            '*/api/v1/similar' => Http::response([
                'results' => [
                    ['artwork_id' => 5, 'score' => 0.88, 'rank' => 1],
                ],
                'model' => 'qwen3-embedding:0.6b',
                'latency_ms' => 100,
            ]),
        ]);

        $result = app(AiServiceClient::class)->similar(1);

        $this->assertCount(1, $result->results);
        $this->assertSame([5], $result->artworkIds());
    }

    public function test_index_success(): void
    {
        Http::fake([
            '*/api/v1/artworks/index' => Http::response([
                'artwork_id' => 42,
                'qdrant_point_id' => 'point-123',
                'status' => 'indexed',
            ]),
        ]);

        $result = app(AiServiceClient::class)->indexArtwork(
            artworkId: 42,
            metadata: ['summary' => 'test'],
            searchableText: 'test painting',
            payload: ['user_id' => 1],
        );

        $this->assertSame('indexed', $result['status']);
        $this->assertSame('point-123', $result['qdrant_point_id']);
    }

    public function test_delete_index_success(): void
    {
        Http::fake([
            '*/api/v1/artworks/42/index' => Http::response([
                'artwork_id' => 42,
                'status' => 'deleted',
            ]),
        ]);

        $result = app(AiServiceClient::class)->deleteArtworkIndex(42);

        $this->assertSame('deleted', $result['status']);
    }

    public function test_search_throws_on_422(): void
    {
        Http::fake([
            '*/api/v1/search' => Http::response(['detail' => 'Validation error'], 422),
        ]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        app(AiServiceClient::class)->search('cat');
    }

    public function test_search_throws_on_500(): void
    {
        Http::fake([
            '*/api/v1/search' => Http::response(['detail' => 'Internal error'], 500),
        ]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        app(AiServiceClient::class)->search('cat');
    }

    public function test_api_key_header_sent_when_configured(): void
    {
        config(['ai.api_key' => 'test-secret-key']);

        Http::fake([
            '*/health' => Http::response(['status' => 'ok', 'services' => ['ollama' => 'ok', 'qdrant' => 'ok']]),
        ]);

        app(AiServiceClient::class)->health();

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-AI-Service-Key', 'test-secret-key');
        });
    }

    public function test_rerank_returns_search_result(): void
    {
        Http::fake([
            '*/api/v1/rerank' => Http::response([
                'results' => [
                    ['artwork_id' => 3, 'score' => 0.0, 'rank' => 1],
                    ['artwork_id' => 1, 'score' => 0.0, 'rank' => 2],
                ],
                'reranked' => false,
                'model' => 'none',
                'latency_ms' => 0,
            ]),
        ]);

        $result = app(AiServiceClient::class)->rerank('query', [3, 1]);

        $this->assertInstanceOf(AiSearchResult::class, $result);
        $this->assertFalse($result->reranked);
    }

    public function test_empty_search_result_helper(): void
    {
        $empty = AiSearchResult::empty();

        $this->assertEmpty($empty->results);
        $this->assertEmpty($empty->artworkIds());
        $this->assertSame(0, $empty->latencyMs);
    }

    public function test_empty_search_and_similar_filters_are_json_objects(): void
    {
        Http::fake(['*' => Http::response([
            'results' => [], 'model' => 'test', 'latency_ms' => 0,
        ])]);

        $client = app(AiServiceClient::class);
        $client->search('cat');
        $client->similar(1);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/search')
            && json_decode($request->body())->filters instanceof \stdClass);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/similar')
            && json_decode($request->body())->filters instanceof \stdClass);
    }
}
