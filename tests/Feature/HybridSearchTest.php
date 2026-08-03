<?php

namespace Tests\Feature;

use App\Contracts\AiServiceClient;
use App\DTOs\AiSearchResult;
use App\Models\Artwork;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
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

        $response->assertOk();
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

    public function test_search_falls_back_to_lexical_on_ai_error(): void
    {
        config(['ai.enabled' => true]);

        $match = $this->publishedArtwork(['title' => 'Watercolor Forest']);

        $mock = Mockery::mock(AiServiceClient::class);
        $mock->shouldReceive('search')
            ->andThrow(new \Exception('AI unavailable'));
        $this->app->instance(AiServiceClient::class, $mock);

        $response = $this->get('/search?q=Watercolor');

        $response->assertOk();
        $response->assertSee('Watercolor Forest');
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
