<?php

namespace Tests\Feature;

use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Services\AiService\ArtworkIndexingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArtworkIndexInspectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.enabled' => true,
            'ai.service_url' => 'http://ai.test',
            'ai.api_key' => 'inspection-test-key',
            'ai.schema_version' => 1,
            'ai.prompt_version' => 1,
        ]);
        Queue::fake();
    }

    public function test_a_requires_matching_embedding_provenance_in_the_latest_successful_run(): void
    {
        [$artwork, $metadata, $point, $response] = $this->indexedFixture();
        $digest = str_repeat('a', 64);
        AiProcessingRun::create([
            'artwork_id' => $artwork->id,
            'operation' => 'analyze_and_index',
            'status' => 'success',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'parameters' => [
                'collection' => $response['collection'],
                'qdrant_point_id' => $metadata->qdrant_point_id,
                'content_hash' => $metadata->content_hash,
                'embedding_model' => $metadata->embedding_model,
                'embedding_model_digest' => 'sha256:'.strtoupper($digest),
            ],
        ]);
        Http::fake(['*/index/inspect*' => Http::response([
            ...$response, 'points' => [[...$point, 'embedding_fingerprint' => $digest]],
        ])]);

        $this->artisan('ai:reconcile-artwork', ['id' => $artwork->id, '--dry-run' => true])
            ->expectsOutput('Classification: A')->assertSuccessful();

        AiProcessingRun::create([
            'artwork_id' => $artwork->id,
            'operation' => 'analyze_and_index',
            'status' => 'success',
            'started_at' => now(),
            'finished_at' => now()->addSecond(),
        ]);
        $this->artisan('ai:reconcile-artwork', ['id' => $artwork->id])
            ->expectsOutput('Classification: B')->assertSuccessful();
    }

    public function test_b_without_a_digest_and_dry_run_performs_only_reads(): void
    {
        [$artwork, $metadata, , $response] = $this->indexedFixture();
        $snapshot = $metadata->refresh()->getRawOriginal();
        Http::fake(['*/index/inspect*' => Http::response($response)]);

        DB::statement('PRAGMA query_only = ON');
        try {
            $this->artisan('ai:reconcile-artwork', ['id' => $artwork->id, '--dry-run' => true])
                ->expectsOutput('Model fingerprint: unknown')
                ->expectsOutput('Classification: B')
                ->expectsOutput('Recommendation: preserve existing point')
                ->expectsOutput('Action taken: none')->assertSuccessful();
        } finally {
            DB::statement('PRAGMA query_only = OFF');
        }

        $this->assertSame($snapshot, $metadata->refresh()->getRawOriginal());
        $this->assertDatabaseCount('ai_processing_runs', 0);
        Queue::assertNothingPushed();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_contains($request->url(), "/api/v1/artworks/{$artwork->id}/index/inspect")
            && str_ends_with($request->url(), 'point_id='.$metadata->qdrant_point_id)
            && $request->hasHeader('X-AI-Service-Key', 'inspection-test-key'));
    }

    public function test_point_digest_without_corroboration_is_b(): void
    {
        [$artwork, , $point, $response] = $this->indexedFixture();
        Http::fake(['*/index/inspect*' => Http::response([
            ...$response, 'points' => [[...$point, 'embedding_model_digest' => str_repeat('a', 64)]],
        ])]);

        $this->artisan('ai:reconcile-artwork', ['id' => $artwork->id])
            ->expectsOutput("Artwork: {$artwork->id} (read-only)")
            ->expectsOutput('Classification: B')->assertSuccessful();
    }

    public function test_c_when_the_point_hash_mismatches_current_revision(): void
    {
        [$artwork, , , $response] = $this->indexedFixture();
        $artwork->update(['title' => 'A newer revision']);
        Http::fake(['*/index/inspect*' => Http::response($response)]);

        $this->artisan('ai:reconcile-artwork', ['id' => $artwork->id, '--dry-run' => true])
            ->expectsOutput('Content hash: mismatch')->expectsOutput('Classification: C')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_d_when_no_point_exists_after_a_successful_read(): void
    {
        [$artwork, $metadata, , $response] = $this->indexedFixture();
        $metadata->update(['qdrant_point_id' => null, 'content_hash' => null]);
        Http::fake(['*/index/inspect' => Http::response([
            ...$response, 'saved_point' => 'not_provided', 'points' => [], 'vector_present' => null,
        ])]);

        $this->artisan('ai:reconcile-artwork', ['id' => $artwork->id, '--dry-run' => true])
            ->expectsOutput('Qdrant: point not found')->expectsOutput('Classification: D')->assertSuccessful();
    }

    public function test_e_takes_priority_for_hidden_or_disabled_artwork(): void
    {
        [$artwork, , , $response] = $this->indexedFixture();
        $artwork->forceFill(['is_private' => true, 'ai_indexing_enabled' => false])->save();
        Http::fake(['*/index/inspect*' => Http::response([
            ...$response, 'saved_point' => 'missing', 'points' => [], 'vector_present' => null,
        ])]);

        $this->artisan('ai:reconcile-artwork', ['id' => $artwork->id])
            ->expectsOutput('Qdrant: point not found')->expectsOutput('Classification: E')->assertSuccessful();
    }

    public function test_missing_artwork_is_e_without_making_a_request(): void
    {
        Http::fake();
        $this->artisan('ai:reconcile-artwork', ['id' => 99])->expectsOutput('Classification: E')->assertSuccessful();
        Http::assertNothingSent();
    }

    #[DataProvider('indexConflicts')]
    public function test_f_for_conflicting_points(array $overrides, bool $hidden = false): void
    {
        [$artwork, , , $response] = $this->indexedFixture();
        if ($hidden) {
            $artwork->update(['is_private' => true]);
        }
        Http::fake(['*/index/inspect*' => Http::response([...$response, ...$overrides])]);

        $this->artisan('ai:reconcile-artwork', ['id' => $artwork->id, '--dry-run' => true])
            ->expectsOutput('Classification: F')->assertFailed();
        Queue::assertNothingPushed();
    }

    public static function indexConflicts(): array
    {
        return [
            'duplicate points' => [['has_duplicates' => true]],
            'duplicate points for a hidden artwork' => [['has_duplicates' => true], true],
            'point ID found outside artwork' => [['saved_point' => 'conflict', 'points' => [], 'vector_present' => null]],
            'point belongs to a different ID than MySQL' => [['saved_point' => 'missing']],
            'point without a vector' => [['vector_present' => false]],
            'empty but not a consistent absence' => [['saved_point' => 'missing', 'points' => []]],
            'invalid response artwork ID' => [['artwork_id' => null]],
        ];
    }

    public function test_qdrant_failure_is_f_even_if_no_point_was_returned(): void
    {
        [$artwork, , , $response] = $this->indexedFixture();
        Http::fake(['*/index/inspect*' => Http::response([
            ...$response, 'detail' => 'qdrant_inspection_failed', 'points' => [],
        ], 503)]);

        $this->artisan('ai:reconcile-artwork', ['id' => $artwork->id, '--dry-run' => true])
            ->expectsOutput('Qdrant: unavailable')->expectsOutput('Classification: F')->assertFailed();
    }

    private function indexedFixture(): array
    {
        $artwork = Artwork::factory()->create();
        $metadata = $artwork->aiMetadata()->create([
            'status' => ArtworkAiMetadata::STATUS_FAILED,
            'content_hash' => app(ArtworkIndexingService::class)->contentHash($artwork),
            'qdrant_point_id' => (string) Str::uuid(),
            'embedding_model' => 'qwen3-embedding:0.6b',
            'embedding_dimension' => 1024,
            'indexed_at' => now()->subWeek(),
            'attempts' => 7,
        ]);
        $point = [
            'id' => $metadata->qdrant_point_id, 'artwork_id' => $artwork->id, 'content_hash' => $metadata->content_hash,
            'schema_version' => 1, 'prompt_version' => 1, 'is_published' => true, 'is_private' => false,
            'is_blocked' => false, 'embedding_model' => $metadata->embedding_model,
        ];
        $response = [
            'artwork_id' => $artwork->id, 'collection' => 'artworks', 'dimension' => 1024,
            'saved_point' => 'matched', 'has_duplicates' => false, 'vector_present' => true, 'points' => [$point],
        ];

        return [$artwork, $metadata, $point, $response];
    }
}
