<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Services\AiService\ArtworkIndexingService;
use Illuminate\Contracts\Cache\Repository as Cache;
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
        [$artwork, , , $response] = $this->confirmedFixture();
        Http::fake(['*/index/inspect*' => Http::response($response)]);

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

    public function test_confirmed_failed_record_passes_preflight_but_writes_are_disabled(): void
    {
        [$artwork, $metadata, , $response] = $this->confirmedFixture();
        $metadata->update(['last_error' => 'Historical failure']);
        $snapshot = $metadata->refresh()->getRawOriginal();
        Http::fake(['*/index/inspect*' => Http::response($response)]);

        DB::statement('PRAGMA query_only = ON');
        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this->artisan('ai:reconcile-artwork', ['id' => $artwork->id, '--apply-status' => true, '--confirm-id' => $artwork->id])
                    ->expectsOutput('Classification: A')
                    ->expectsOutput('Status writes are disabled until index, delete and publication writers share a concurrency guard.')
                    ->expectsOutput('Action taken: none')->assertFailed();
            }
        } finally {
            DB::statement('PRAGMA query_only = OFF');
        }

        $this->assertSame($snapshot, $metadata->refresh()->getRawOriginal());
        $this->assertDatabaseCount('ai_processing_runs', 1);
        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET' || ! str_contains($request->url(), '/index/inspect'));
        Queue::assertNothingPushed();
    }

    public function test_already_indexed_verified_record_is_an_idempotent_no_op(): void
    {
        [$artwork, $metadata, , $response] = $this->confirmedFixture();
        $metadata->update(['status' => ArtworkAiMetadata::STATUS_INDEXED]);
        $snapshot = $metadata->refresh()->getRawOriginal();
        Http::fake(['*/index/inspect*' => Http::response($response)]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->artisan('ai:reconcile-artwork', ['id' => $artwork->id, '--apply-status' => true, '--confirm-id' => $artwork->id])
                ->expectsOutput('The artwork is already indexed.')
                ->expectsOutput('Action taken: none')->assertSuccessful();
        }

        $this->assertSame($snapshot, $metadata->refresh()->getRawOriginal());
        $this->assertDatabaseCount('ai_processing_runs', 1);
        Queue::assertNothingPushed();
    }

    #[DataProvider('unrecoverablePoints')]
    public function test_apply_rejects_groups_b_through_f(string $group, array $pointChanges, array $responseChanges, array $artworkChanges = []): void
    {
        [$artwork, $metadata, , $response] = $this->confirmedFixture();
        $artwork->forceFill($artworkChanges)->save();
        $response['points'][0] = [...$response['points'][0], ...$pointChanges];
        Http::fake(['*/index/inspect*' => Http::response([...$response, ...$responseChanges])]);
        $snapshot = $metadata->refresh()->getRawOriginal();

        $this->artisan('ai:reconcile-artwork', ['id' => $artwork->id, '--apply-status' => true, '--confirm-id' => $artwork->id])
            ->expectsOutput("Classification: {$group}")
            ->expectsOutput('Status recovery requires a verified group A point.')->assertFailed();

        $this->assertSame($snapshot, $metadata->refresh()->getRawOriginal());
        $this->assertDatabaseCount('ai_processing_runs', 1);
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
    }

    public static function unrecoverablePoints(): array
    {
        return [
            'B missing fingerprint' => ['B', ['embedding_fingerprint' => null], []],
            'C outdated hash' => ['C', ['content_hash' => 'outdated'], []],
            'D point absent' => ['D', [], ['points' => [], 'saved_point' => 'missing', 'vector_present' => null]],
            'E indexing disabled' => ['E', [], [], ['ai_indexing_enabled' => false]],
            'F duplicate point' => ['F', [], ['has_duplicates' => true]],
        ];
    }

    public function test_fingerprint_without_successful_upsert_history_cannot_be_applied(): void
    {
        [$artwork, $metadata, $run, $response] = $this->confirmedFixture();
        $run->update(['status' => 'failed']);
        Http::fake(['*/index/inspect*' => Http::response($response)]);

        $this->artisan('ai:reconcile-artwork', ['id' => $artwork->id, '--apply-status' => true, '--confirm-id' => $artwork->id])
            ->expectsOutput('Classification: B')->assertFailed();

        $this->assertTrue($metadata->refresh()->isFailed());
        $this->assertDatabaseCount('ai_processing_runs', 1);
        Queue::assertNothingPushed();
    }

    public function test_confirmed_point_without_original_indexed_at_cannot_be_applied(): void
    {
        [$artwork, $metadata, , $response] = $this->confirmedFixture();
        $metadata->update(['indexed_at' => null]);
        Http::fake(['*/index/inspect*' => Http::response($response)]);

        $this->artisan('ai:reconcile-artwork', ['id' => $artwork->id, '--apply-status' => true, '--confirm-id' => $artwork->id])
            ->expectsOutput('Classification: A')
            ->expectsOutput('Status recovery requires a failed or indexed record with its original indexed_at.')->assertFailed();

        $this->assertTrue($metadata->refresh()->isFailed());
        $this->assertNull($metadata->indexed_at);
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
    }

    public function test_apply_requires_matching_confirmation_and_cannot_be_combined_with_dry_run(): void
    {
        [$artwork] = $this->indexedFixture();
        Http::fake();
        $options = ['id' => $artwork->id, '--apply-status' => true];

        foreach ([[], ['--confirm-id' => $artwork->id + 1], ['--confirm-id' => $artwork->id, '--dry-run' => true]] as $invalid) {
            $this->artisan('ai:reconcile-artwork', [...$options, ...$invalid])->assertExitCode(2);
        }

        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_active_index_job_lock_blocks_the_recovery_preflight(): void
    {
        [$artwork, $metadata, , $response] = $this->confirmedFixture();
        Http::fake(['*/index/inspect*' => Http::response($response)]);
        $job = new AnalyzeAndIndexArtworkJob($artwork->id);
        $lock = app(Cache::class)->lock($job->middleware()[0]->getLockKey($job), 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('ai:reconcile-artwork', ['id' => $artwork->id, '--apply-status' => true, '--confirm-id' => $artwork->id])
                ->expectsOutput('The artwork is currently locked by indexing.')->assertFailed();
        } finally {
            $lock->release();
        }

        $this->assertTrue($metadata->refresh()->isFailed());
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
    }

    #[DataProvider('changesDuringInspection')]
    public function test_apply_rejects_changes_during_the_second_inspection(array $artworkChanges, array $metadataChanges): void
    {
        [$artwork, $metadata, , $response] = $this->confirmedFixture();
        $reads = 0;
        Http::fake(['*/index/inspect*' => function () use ($artwork, $metadata, $response, &$reads, $artworkChanges, $metadataChanges) {
            if (++$reads === 2) {
                $artwork->forceFill($artworkChanges)->save();
                $metadata->update($metadataChanges);
            }

            return Http::response($response);
        }]);

        $this->artisan('ai:reconcile-artwork', ['id' => $artwork->id, '--apply-status' => true, '--confirm-id' => $artwork->id])
            ->expectsOutput('The artwork, SQL metadata or Qdrant point changed during verification.')->assertFailed();

        $this->assertNotSame(ArtworkAiMetadata::STATUS_INDEXED, $metadata->refresh()->status);
        $this->assertDatabaseCount('ai_processing_runs', 1);
        Http::assertSentCount(2);
        Queue::assertNothingPushed();
    }

    public static function changesDuringInspection(): array
    {
        return [
            'changed revision' => [['title' => 'New revision'], []],
            'unpublished' => [['is_published' => false], []],
            'indexing disabled' => [['ai_indexing_enabled' => false], []],
            'stored hash changed' => [[], ['content_hash' => 'new stored hash']],
            'stored point ID changed' => [[], ['qdrant_point_id' => 'decce4fd-d713-4d09-be51-276e375f3978']],
            'status changed' => [[], ['status' => ArtworkAiMetadata::STATUS_PROCESSING]],
        ];
    }

    public function test_apply_rejects_concurrent_deletion_of_the_point(): void
    {
        [$artwork, $metadata, , $response] = $this->confirmedFixture();
        Http::fake(['*/index/inspect*' => Http::sequence()->push($response)->push([
            ...$response, 'points' => [], 'saved_point' => 'missing', 'vector_present' => null,
        ])]);

        $this->artisan('ai:reconcile-artwork', ['id' => $artwork->id, '--apply-status' => true, '--confirm-id' => $artwork->id])
            ->expectsOutput('The artwork, SQL metadata or Qdrant point changed during verification.')->assertFailed();

        $this->assertTrue($metadata->refresh()->isFailed());
        $this->assertDatabaseCount('ai_processing_runs', 1);
        Http::assertSentCount(2);
        Queue::assertNothingPushed();
    }

    private function confirmedFixture(): array
    {
        [$artwork, $metadata, , $response] = $this->indexedFixture();
        $digest = str_repeat('a', 64);
        $run = AiProcessingRun::create([
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
        $response['points'][0]['embedding_fingerprint'] = $digest;

        return [$artwork, $metadata, $run, $response];
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
