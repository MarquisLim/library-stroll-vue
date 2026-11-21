<?php

namespace Tests\Feature;

use App\Contracts\AiServiceClient;
use App\DTOs\AiSearchResult;
use App\Models\Artwork;
use App\Models\Tag;
use App\Models\User;
use App\Services\Search\HybridSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HybridSearchTest extends TestCase
{
    use RefreshDatabase;

    private function publishedArtwork(array $attrs = []): Artwork
    {
        return Artwork::factory()->create(array_merge([
            'is_published' => true,
            'is_private' => false,
        ], $attrs));
    }

    public function test_lexical_only_when_ai_disabled(): void
    {
        config(['ai.enabled' => false]);

        $match = $this->publishedArtwork(['title' => 'Beautiful Cat']);
        $noMatch = $this->publishedArtwork(['title' => 'Abstract Shape']);

        $response = $this->get('/search?q=Cat');

        $response->assertOk();
        $response->assertSee('Beautiful Cat');
        $response->assertDontSee('Abstract Shape');
    }

    public function test_hybrid_search_merges_lexical_and_semantic(): void
    {
        config(['ai.enabled' => true]);

        $lexicalMatch = $this->publishedArtwork(['title' => 'Sunset painting']);
        $semanticMatch = $this->publishedArtwork(['title' => 'Dawn over ocean']);

        $mock = Mockery::mock(AiServiceClient::class);
        $mock->shouldReceive('search')
            ->andReturn(new AiSearchResult(
                results: [
                    ['artwork_id' => $semanticMatch->id, 'score' => 0.9, 'rank' => 1],
                    ['artwork_id' => $lexicalMatch->id, 'score' => 0.7, 'rank' => 2],
                ],
                model: 'test',
                latencyMs: 50,
            ));
        $this->app->instance(AiServiceClient::class, $mock);

        $response = $this->get('/search?q=Sunset');

        $response->assertOk()->assertSee('Sunset painting')->assertSee('Dawn over ocean');
    }

    public function test_search_excludes_private_artworks_from_semantic(): void
    {
        config(['ai.enabled' => true]);

        $privateArt = Artwork::factory()->create([
            'title' => 'Secret Art',
            'is_published' => true,
            'is_private' => true,
        ]);

        $mock = Mockery::mock(AiServiceClient::class);
        $mock->shouldReceive('search')
            ->andReturn(new AiSearchResult(
                results: [['artwork_id' => $privateArt->id, 'score' => 0.9, 'rank' => 1]],
                model: 'test',
                latencyMs: 50,
            ));
        $this->app->instance(AiServiceClient::class, $mock);

        $response = $this->get('/search?q=Secret');

        $response->assertOk();
        $response->assertDontSee('Secret Art');
    }

    public function test_search_falls_back_to_lexical_when_ai_cannot_verify_visibility(): void
    {
        config(['ai.enabled' => true, 'ai.service_url' => 'http://ai.test']);

        $this->publishedArtwork(['title' => 'Watercolor Forest']);
        Http::fake(['ai.test/api/v1/search' => Http::response(['detail' => 'artwork_visibility_unavailable'], 503)]);

        $response = $this->get('/search?q=Watercolor');

        $response->assertOk();
        $response->assertSee('Watercolor Forest');
        Http::assertSentCount(1);
    }

    public function test_rerank_disabled_keeps_dense_order_without_calling_colbert(): void
    {
        config(['ai.enabled' => true, 'ai.rerank_enabled' => false,
            'ai.service_url' => 'http://ai.test', 'ai.colbert_url' => 'http://colbert.test']);
        $dense = Artwork::factory()->count(2)->create(['title' => 'Ocean'])->modelKeys();
        Http::preventStrayRequests();
        Http::fake(['ai.test/api/v1/search' => Http::response(['results' => $this->hits($dense)])]);

        $result = app(HybridSearchService::class)->search('sunset');

        $this->assertSame($dense, $result['artworks']->modelKeys());
        Http::assertSentCount(1);
    }

    public function test_reranking_preserves_dense_tail_and_uses_rrf_filters_and_pagination(): void
    {
        config(['ai.enabled' => true, 'ai.rerank_enabled' => true,
            'ai.service_url' => 'http://ai.test', 'ai.colbert_url' => 'http://colbert.test']);
        $author = User::factory()->create();
        $lexical = $this->publishedArtwork(['user_id' => $author->id, 'title' => 'Sunset painting']);
        $private = $this->publishedArtwork(['user_id' => $author->id, 'is_private' => true, 'title' => 'Sunset private']);
        $blocked = $this->publishedArtwork(['user_id' => $author->id, 'is_blocked' => true, 'title' => 'Sunset blocked']);
        $otherAuthor = $this->publishedArtwork(['title' => 'Sunset other author']);
        $visible = Artwork::factory()->count(31)->create(['user_id' => $author->id, 'title' => 'Ocean'])->modelKeys();
        $head = [$private->id, $blocked->id, $otherAuthor->id, ...array_slice($visible, 0, 27)];
        $dense = [...$head, ...array_slice($visible, 27), $lexical->id];
        Http::preventStrayRequests();
        Http::fake([
            'ai.test/api/v1/search' => Http::response(['results' => $this->hits($dense)]),
            'colbert.test/api/v1/rerank' => Http::response([
                'results' => $this->hits(array_reverse($head)),
                'reranked' => true,
            ]),
        ]);

        $search = app(HybridSearchService::class);
        $filters = ['author_id' => $author->id];
        $actual = $search->search('sunset', $filters, perPage: 50);
        $expected = [$lexical->id, ...array_reverse(array_slice($visible, 0, 27)), ...array_slice($visible, 27)];

        $this->assertSame($expected, $actual['artworks']->modelKeys());
        $this->assertSame(count($expected), $actual['total']);
        $page = $search->search('sunset', $filters, page: 2, perPage: 5);
        $this->assertSame(array_slice($expected, 5, 5), $page['artworks']->modelKeys());
        $this->assertSame($actual['total'], $page['total']);
        Http::assertSent(fn ($request) => $request->url() === 'http://ai.test/api/v1/search'
            && ((array) $request['filters'])['user_id'] === $author->id);
        Http::assertSent(fn ($request) => $request->url() === 'http://colbert.test/api/v1/rerank'
            && $request->data() === ['query' => 'sunset', 'artwork_ids' => $head, 'limit' => 30]);
    }

    #[DataProvider('rerankingFailures')]
    public function test_reranking_failure_uses_original_dense_and_lexical_order(string $failure): void
    {
        config(['ai.enabled' => true, 'ai.rerank_enabled' => true,
            'ai.service_url' => 'http://ai.test', 'ai.colbert_url' => 'http://colbert.test']);
        $lexical = $this->publishedArtwork(['title' => 'Sunset painting']);
        $dense = Artwork::factory()->count(2)->create(['title' => 'Ocean'])->modelKeys();
        [$first, $second] = $dense;
        $invalid = match ($failure) {
            'incomplete' => $this->hits([$second]),
            'duplicate' => $this->hits([$second, $second]),
            'foreign' => $this->hits([$second, $lexical->id]),
            'rank' => array_reverse($this->hits([$first, $second])),
            'missing-score' => [['artwork_id' => $second, 'rank' => 1], $this->hits([$second, $first])[1]],
            default => $this->hits([$second, $first]),
        };
        Http::preventStrayRequests();
        Http::fake([
            'ai.test/api/v1/search' => Http::response(['results' => $this->hits($dense)]),
            'colbert.test/api/v1/rerank' => match ($failure) {
                'timeout' => Http::failedConnection('Operation timed out'),
                'http' => Http::response([], 503),
                'malformed-json' => Http::response('invalid JSON'),
                default => Http::response(['results' => $invalid, 'reranked' => $failure !== 'not-reranked']),
            },
        ]);

        $result = app(HybridSearchService::class)->search('sunset');

        $this->assertSame([$lexical->id, ...$dense], $result['artworks']->modelKeys());
        $this->assertSame(3, $result['total']);
    }

    public static function rerankingFailures(): array
    {
        return [
            'timeout' => ['timeout'],
            'HTTP error' => ['http'],
            'incomplete' => ['incomplete'],
            'duplicate ID' => ['duplicate'],
            'foreign ID' => ['foreign'],
            'rank mismatch' => ['rank'],
            'missing score' => ['missing-score'],
            'malformed JSON' => ['malformed-json'],
            'reranking declined' => ['not-reranked'],
        ];
    }

    private function hits(array $ids): array
    {
        return array_map(fn ($id, $offset) => ['artwork_id' => $id, 'score' => 0.5, 'rank' => $offset + 1],
            $ids, array_keys($ids));
    }

    public function test_empty_query_returns_recommendations(): void
    {
        config(['ai.enabled' => false]);

        $this->publishedArtwork();

        $response = $this->get('/search');

        $response->assertOk();
    }

    public function test_type_filter_applied(): void
    {
        config(['ai.enabled' => false]);

        $image = $this->publishedArtwork(['title' => 'Cat Image', 'type' => 'image']);
        $video = $this->publishedArtwork(['title' => 'Cat Video', 'type' => 'video']);

        $response = $this->get('/search?q=Cat&type=image');

        $response->assertOk();
        $response->assertSee('Cat Image');
        $response->assertDontSee('Cat Video');
    }
}
