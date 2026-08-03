<?php

namespace Tests\Feature;

use App\Contracts\AiServiceClient;
use App\DTOs\AiSearchResult;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SemanticSimilarTest extends TestCase
{
    use RefreshDatabase;

    private function publishedArtwork(array $attrs = []): Artwork
    {
        return Artwork::factory()->create(array_merge([
            'is_published' => true,
            'is_private' => false,
        ], $attrs));
    }

    public function test_similar_uses_semantic_when_ai_enabled_and_indexed(): void
    {
        config(['ai.enabled' => true]);

        $source = $this->publishedArtwork();
        $target = $this->publishedArtwork();

        ArtworkAiMetadata::create([
            'artwork_id' => $source->id,
            'status' => ArtworkAiMetadata::STATUS_INDEXED,
        ]);

        $mock = Mockery::mock(AiServiceClient::class);
        $mock->shouldReceive('similar')
            ->once()
            ->andReturn(new AiSearchResult(
                results: [['artwork_id' => $target->id, 'score' => 0.9, 'rank' => 1]],
                model: 'test',
                latencyMs: 50,
            ));
        $this->app->instance(AiServiceClient::class, $mock);

        $response = $this->getJson("/artworks/{$source->id}/similar");

        $response->assertOk()
            ->assertJsonCount(1, 'artworks')
            ->assertJsonPath('artworks.0.id', $target->id);
    }

    public function test_similar_falls_back_to_tags_when_ai_disabled(): void
    {
        config(['ai.enabled' => false]);

        $source = $this->publishedArtwork(['title' => 'Test Art']);
        $this->publishedArtwork(['title' => 'Another Art']);

        $response = $this->getJson("/artworks/{$source->id}/similar");

        $response->assertOk()
            ->assertJsonStructure(['artworks', 'hasMore']);
    }

    public function test_similar_falls_back_when_metadata_not_indexed(): void
    {
        config(['ai.enabled' => true]);

        $source = $this->publishedArtwork();

        $response = $this->getJson("/artworks/{$source->id}/similar");

        $response->assertOk();
    }

    public function test_similar_excludes_private_artworks_from_python_results(): void
    {
        config(['ai.enabled' => true]);

        $source = $this->publishedArtwork();
        $privateArt = Artwork::factory()->create([
            'is_published' => true,
            'is_private' => true,
        ]);

        ArtworkAiMetadata::create([
            'artwork_id' => $source->id,
            'status' => ArtworkAiMetadata::STATUS_INDEXED,
        ]);

        $mock = Mockery::mock(AiServiceClient::class);
        $mock->shouldReceive('similar')
            ->andReturn(new AiSearchResult(
                results: [['artwork_id' => $privateArt->id, 'score' => 0.9, 'rank' => 1]],
                model: 'test',
                latencyMs: 50,
            ));
        $this->app->instance(AiServiceClient::class, $mock);

        $response = $this->getJson("/artworks/{$source->id}/similar");

        $response->assertOk()
            ->assertJsonCount(0, 'artworks');
    }

    public function test_similar_excludes_unpublished_artworks_from_python_results(): void
    {
        config(['ai.enabled' => true]);

        $source = $this->publishedArtwork();
        $draft = Artwork::factory()->create([
            'is_published' => false,
            'is_private' => false,
        ]);

        ArtworkAiMetadata::create([
            'artwork_id' => $source->id,
            'status' => ArtworkAiMetadata::STATUS_INDEXED,
        ]);

        $mock = Mockery::mock(AiServiceClient::class);
        $mock->shouldReceive('similar')
            ->andReturn(new AiSearchResult(
                results: [['artwork_id' => $draft->id, 'score' => 0.9, 'rank' => 1]],
                model: 'test',
                latencyMs: 50,
            ));
        $this->app->instance(AiServiceClient::class, $mock);

        $response = $this->getJson("/artworks/{$source->id}/similar");

        $response->assertOk()
            ->assertJsonCount(0, 'artworks');
    }

    public function test_similar_excludes_source_artwork_from_results(): void
    {
        config(['ai.enabled' => true]);

        $source = $this->publishedArtwork();

        ArtworkAiMetadata::create([
            'artwork_id' => $source->id,
            'status' => ArtworkAiMetadata::STATUS_INDEXED,
        ]);

        $mock = Mockery::mock(AiServiceClient::class);
        $mock->shouldReceive('similar')
            ->andReturn(new AiSearchResult(
                results: [['artwork_id' => $source->id, 'score' => 1.0, 'rank' => 1]],
                model: 'test',
                latencyMs: 50,
            ));
        $this->app->instance(AiServiceClient::class, $mock);

        $response = $this->getJson("/artworks/{$source->id}/similar");

        $response->assertOk()
            ->assertJsonCount(0, 'artworks');
    }

    public function test_similar_preserves_python_order(): void
    {
        config(['ai.enabled' => true]);

        $source = $this->publishedArtwork();
        $a = $this->publishedArtwork();
        $b = $this->publishedArtwork();

        ArtworkAiMetadata::create([
            'artwork_id' => $source->id,
            'status' => ArtworkAiMetadata::STATUS_INDEXED,
        ]);

        $mock = Mockery::mock(AiServiceClient::class);
        $mock->shouldReceive('similar')
            ->andReturn(new AiSearchResult(
                results: [
                    ['artwork_id' => $b->id, 'score' => 0.9, 'rank' => 1],
                    ['artwork_id' => $a->id, 'score' => 0.8, 'rank' => 2],
                ],
                model: 'test',
                latencyMs: 50,
            ));
        $this->app->instance(AiServiceClient::class, $mock);

        $response = $this->getJson("/artworks/{$source->id}/similar");

        $response->assertOk();
        $ids = collect($response->json('artworks'))->pluck('id')->all();
        $this->assertSame([$b->id, $a->id], $ids);
    }

    public function test_similar_falls_back_on_ai_timeout(): void
    {
        config(['ai.enabled' => true]);

        $source = $this->publishedArtwork();

        ArtworkAiMetadata::create([
            'artwork_id' => $source->id,
            'status' => ArtworkAiMetadata::STATUS_INDEXED,
        ]);

        $mock = Mockery::mock(AiServiceClient::class);
        $mock->shouldReceive('similar')
            ->andThrow(new \Exception('Connection timed out'));
        $this->app->instance(AiServiceClient::class, $mock);

        $response = $this->getJson("/artworks/{$source->id}/similar");

        $response->assertOk()
            ->assertJsonStructure(['artworks', 'hasMore']);
    }

    public function test_similar_ignores_nonexistent_artwork_ids(): void
    {
        config(['ai.enabled' => true]);

        $source = $this->publishedArtwork();

        ArtworkAiMetadata::create([
            'artwork_id' => $source->id,
            'status' => ArtworkAiMetadata::STATUS_INDEXED,
        ]);

        $mock = Mockery::mock(AiServiceClient::class);
        $mock->shouldReceive('similar')
            ->andReturn(new AiSearchResult(
                results: [['artwork_id' => 99999, 'score' => 0.9, 'rank' => 1]],
                model: 'test',
                latencyMs: 50,
            ));
        $this->app->instance(AiServiceClient::class, $mock);

        $response = $this->getJson("/artworks/{$source->id}/similar");

        $response->assertOk()
            ->assertJsonCount(0, 'artworks');
    }
}
