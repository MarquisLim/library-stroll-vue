<?php

namespace Tests\Unit;

use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtworkAiMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_metadata_belongs_to_artwork(): void
    {
        $artwork = Artwork::factory()->create();

        $meta = ArtworkAiMetadata::create([
            'artwork_id' => $artwork->id,
            'status' => ArtworkAiMetadata::STATUS_PENDING,
        ]);

        $this->assertTrue($meta->artwork->is($artwork));
        $this->assertTrue($artwork->aiMetadata->is($meta));
    }

    public function test_ai_metadata_is_deleted_when_artwork_is_deleted(): void
    {
        $artwork = Artwork::factory()->create();

        ArtworkAiMetadata::create([
            'artwork_id' => $artwork->id,
            'status' => ArtworkAiMetadata::STATUS_INDEXED,
        ]);

        $artwork->delete();

        $this->assertDatabaseMissing('artwork_ai_metadata', [
            'artwork_id' => $artwork->id,
        ]);
    }

    public function test_json_fields_cast_correctly(): void
    {
        $artwork = Artwork::factory()->create();

        $meta = ArtworkAiMetadata::create([
            'artwork_id' => $artwork->id,
            'status' => ArtworkAiMetadata::STATUS_INDEXED,
            'objects' => ['cat', 'tree'],
            'themes' => ['nature'],
            'styles' => ['watercolor'],
            'mood' => ['calm'],
            'colors' => ['blue', 'green'],
            'tags_ru' => ['кот', 'дерево'],
            'tags_en' => ['cat', 'tree'],
            'confidence' => 0.85,
        ]);

        $meta->refresh();

        $this->assertIsArray($meta->objects);
        $this->assertSame(['cat', 'tree'], $meta->objects);
        $this->assertIsArray($meta->tags_ru);
        $this->assertSame('0.850', $meta->confidence);
    }

    public function test_status_helpers(): void
    {
        $artwork = Artwork::factory()->create();

        $meta = ArtworkAiMetadata::create([
            'artwork_id' => $artwork->id,
            'status' => ArtworkAiMetadata::STATUS_INDEXED,
        ]);

        $this->assertTrue($meta->isIndexed());
        $this->assertFalse($meta->isFailed());
        $this->assertFalse($meta->isStale());

        $meta->markStale();
        $this->assertTrue($meta->isStale());
    }

    public function test_artwork_id_is_unique(): void
    {
        $artwork = Artwork::factory()->create();

        ArtworkAiMetadata::create([
            'artwork_id' => $artwork->id,
            'status' => ArtworkAiMetadata::STATUS_PENDING,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ArtworkAiMetadata::create([
            'artwork_id' => $artwork->id,
            'status' => ArtworkAiMetadata::STATUS_PENDING,
        ]);
    }

    public function test_ai_processing_run_belongs_to_artwork(): void
    {
        $artwork = Artwork::factory()->create();

        $run = AiProcessingRun::create([
            'artwork_id' => $artwork->id,
            'operation' => 'analyze',
            'status' => 'success',
            'started_at' => now(),
            'finished_at' => now(),
            'latency_ms' => 1200,
        ]);

        $this->assertTrue($run->artwork->is($artwork));
    }

    public function test_ai_processing_run_preserves_after_artwork_deletion(): void
    {
        $artwork = Artwork::factory()->create();
        $artworkId = $artwork->id;

        AiProcessingRun::create([
            'artwork_id' => $artworkId,
            'operation' => 'index',
            'status' => 'success',
            'started_at' => now(),
        ]);

        $artwork->delete();

        $run = AiProcessingRun::where('operation', 'index')->first();
        $this->assertNotNull($run);
        $this->assertNull($run->artwork_id);
    }

    public function test_ai_config_loads_correctly(): void
    {
        config([
            'ai.enabled' => true,
            'ai.service_url' => 'http://127.0.0.1:8001',
            'ai.queue' => 'ai',
            'ai.schema_version' => 1,
        ]);

        $this->assertTrue(config('ai.enabled'));
        $this->assertSame('http://127.0.0.1:8001', config('ai.service_url'));
        $this->assertSame('ai', config('ai.queue'));
        $this->assertSame(1, config('ai.schema_version'));
    }

    public function test_has_ai_remains_independent_of_ai_metadata(): void
    {
        $artwork = Artwork::factory()->create(['has_ai' => true]);

        ArtworkAiMetadata::create([
            'artwork_id' => $artwork->id,
            'status' => ArtworkAiMetadata::STATUS_INDEXED,
            'confidence' => 0.9,
        ]);

        $artwork->refresh();

        $this->assertTrue((bool) $artwork->has_ai);
        $this->assertSame('0.900', $artwork->aiMetadata->confidence);
    }
}
