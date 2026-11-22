<?php

namespace Tests\Feature;

use App\Actions\Artwork\DeleteArtwork;
use App\Jobs\DeleteArtworkColbertJob;
use App\Jobs\Middleware\ArtworkAiJobLock;
use App\Jobs\SyncArtworkColbertJob;
use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Models\ArtworkColbertExpectation;
use App\Services\AiService\ArtworkIndexingService;
use App\Services\AiService\ColbertDocumentException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ArtworkColbertInspectionTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = 'Exact indexed text — not a reconstruction';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.enabled' => true,
            'ai.service_url' => 'http://ai.test',
            'ai.colbert_sync.auto_enabled' => true,
            'ai.colbert_sync.enabled' => true,
            'ai.colbert_sync.url' => 'http://colbert.test',
            'ai.colbert_sync.api_key' => 'synthetic-sync-key',
        ]);
        Queue::fake();
    }

    public function test_repeated_dry_run_and_default_mode_only_read_sql_and_both_indexes(): void
    {
        [$artwork, $metadata, $expected, $point] = $this->indexed();
        $this->assertNotSame($metadata->content_hash, $expected->text_sha256);
        $stored = $expected->refresh()->getRawOriginal();
        $storedMetadata = $metadata->refresh()->getRawOriginal();
        Http::fake([
            'ai.test/*' => Http::response($point),
            'colbert.test/*' => Http::response($this->remote($artwork->id, $expected->text_sha256)),
        ]);

        DB::statement('PRAGMA query_only = ON');
        try {
            foreach ([false, true] as $dryRun) {
                $this->artisan('ai:reconcile-colbert', ['--id' => $artwork->id, '--dry-run' => $dryRun])
                    ->expectsOutputToContain('consistent')
                    ->doesntExpectOutputToContain(self::TEXT)
                    ->doesntExpectOutputToContain('synthetic-sync-key')
                    ->assertSuccessful();
            }
        } finally {
            DB::statement('PRAGMA query_only = OFF');
        }
        $this->assertSame($stored, $expected->refresh()->getRawOriginal());
        $this->assertSame($storedMetadata, $metadata->refresh()->getRawOriginal());
        $this->assertDatabaseCount('ai_processing_runs', 0);
        Queue::assertNothingPushed();
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    #[DataProvider('diagnosticCases')]
    public function test_diagnostic_categories(string $case, string $category, int $code): void
    {
        [$artwork, $metadata, $expected, $point] = $this->indexed();
        $remote = Http::response($this->remote($artwork->id, $expected->text_sha256));
        match ($case) {
            'stale text' => $remote = Http::response($this->remote($artwork->id, str_repeat('d', 64))),
            'missing document' => $remote = Http::response($this->remote($artwork->id)),
            'missing point' => $point = $this->absentPoint($artwork->id, 'missing'),
            'different point revision' => $point['points'][0]['content_hash'] = str_repeat('e', 64),
            'newer dense attempt' => $metadata->update(['attempts' => $metadata->attempts + 1]),
            'legacy text missing' => $expected->delete(),
            'bad ciphertext' => DB::table('artwork_colbert_expectations')->where('artwork_id', $artwork->id)
                ->update(['searchable_text' => 'unreadable ciphertext']),
            'hash mismatch' => $expected->update(['text_sha256' => str_repeat('a', 64)]),
            'colbert offline' => $remote = Http::response(['detail' => 'nonpublic details'], 503),
            'duplicate points' => $point['has_duplicates'] = true,
            'qdrant offline' => $point = Http::response(['detail' => 'nonpublic details'], 503),
            'conflicting absence' => $point = [...$this->absentPoint($artwork->id, 'matched'), 'vector_present' => true],
        };
        Http::fake(['ai.test/*' => is_array($point) ? Http::response($point) : $point, 'colbert.test/*' => $remote]);

        $this->artisan('ai:reconcile-colbert', ['--id' => $artwork->id])
            ->expectsOutputToContain($category)
            ->doesntExpectOutputToContain('nonpublic details')
            ->assertExitCode($code);
        Queue::assertNothingPushed();
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public static function diagnosticCases(): array
    {
        return [
            ['stale text', 'colbert_stale', 0],
            ['missing document', 'colbert_missing', 0],
            ['missing point', 'dense_unconfirmed', 0],
            ['different point revision', 'dense_unconfirmed', 0],
            ['newer dense attempt', 'dense_unconfirmed', 0],
            ['legacy text missing', 'no_saved_text', 0],
            ['bad ciphertext', 'no_saved_text', 0],
            ['hash mismatch', 'no_saved_text', 0],
            ['colbert offline', 'conflict_or_unavailable', 1],
            ['duplicate points', 'conflict_or_unavailable', 1],
            ['qdrant offline', 'conflict_or_unavailable', 1],
            ['conflicting absence', 'conflict_or_unavailable', 1],
        ];
    }

    public function test_deleted_artwork_is_still_inspected_in_both_indexes(): void
    {
        [$artwork, , $expected] = $this->indexed();
        $id = $artwork->id;
        $oldHash = $expected->text_sha256;
        app(DeleteArtwork::class)->handle($artwork);
        Queue::fake();
        Http::fake([
            'ai.test/*' => Http::response($this->absentPoint($id)),
            'colbert.test/*' => Http::sequence()->push($this->remote($id, $oldHash))->push($this->remote($id)),
        ]);

        $this->artisan('ai:reconcile-colbert', ['--id' => $id])->expectsOutputToContain('colbert_should_be_deleted')->assertSuccessful();
        $this->artisan('ai:reconcile-colbert', ['--limit' => 1])->expectsOutputToContain('consistent')->assertSuccessful();
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        Queue::assertNothingPushed();
    }

    public function test_limit_counts_unique_laravel_ids_and_includes_legacy_metadata(): void
    {
        [$legacy, , $expected, $point] = $this->indexed();
        $expected->delete();
        [$tracked] = $this->indexed();
        Http::fake([
            "ai.test/api/v1/artworks/{$legacy->id}/index/inspect*" => Http::response($point),
            "colbert.test/api/v1/documents/{$legacy->id}" => Http::response($this->remote($legacy->id)),
        ]);

        $this->artisan('ai:reconcile-colbert', ['--limit' => 1])->expectsOutputToContain('no_saved_text')->assertSuccessful();
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), "/{$tracked->id}/"));
        $this->artisan('ai:reconcile-colbert', ['--limit' => 101])->assertExitCode(2);
        $this->artisan('ai:reconcile-colbert', ['--id' => 0])->assertExitCode(2);
        Http::assertSentCount(2);
    }

    public function test_a_local_change_while_inspecting_is_reported_as_a_conflict(): void
    {
        [$artwork, $metadata, $expected, $point] = $this->indexed();
        Http::fake([
            'ai.test/*' => Http::response($point),
            'colbert.test/*' => function () use ($artwork, $metadata, $expected) {
                $metadata->update(['attempts' => 2]);

                return Http::response($this->remote($artwork->id, $expected->text_sha256));
            },
        ]);

        $this->artisan('ai:reconcile-colbert', ['--id' => $artwork->id])
            ->expectsOutputToContain('Laravel state changed during inspection')->assertFailed();
        Queue::assertNothingPushed();
        Http::assertSentCount(2);
    }

    #[DataProvider('repairableTexts')]
    public function test_apply_uses_exact_text_and_repeating_it_converges_to_consistent(?string $oldHash): void
    {
        [$artwork, $metadata, $expected, $point] = $this->indexed();
        $id = $artwork->id;
        $current = $this->remote($id, $oldHash);
        $originalMetadata = $metadata->refresh()->getRawOriginal();
        Http::fake([
            'ai.test/*' => Http::response($point),
            'colbert.test/*' => function ($request) use ($id, $expected, &$current) {
                if ($request->method() === 'GET') {
                    return Http::response($current);
                }
                $this->assertSame('PUT', $request->method());
                $this->assertSame(self::TEXT, $request['searchable_text']);
                $this->assertSame($current['content_hash'], $request['expected_previous_hash']);
                $this->assertSame($current['version'], $request['expected_previous_version']);
                $current = [...$this->remote($id, $expected->text_sha256), 'version' => str_repeat('c', 32)];

                return Http::response($current + ['status' => 'updated']);
            },
        ]);

        $this->apply($id, 'repair_queued');
        $this->apply($id, 'repair_queued');
        Queue::assertPushedOn('ai', SyncArtworkColbertJob::class);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        foreach (Queue::pushed(SyncArtworkColbertJob::class) as $job) {
            $this->runRepair($job);
        }
        Http::assertSent(fn ($request) => $request->method() === 'PUT');
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'PUT'));
        $this->assertSame($originalMetadata, $metadata->refresh()->getRawOriginal());
        $this->artisan('ai:reconcile-colbert', ['--id' => $id, '--dry-run' => true])
            ->expectsOutputToContain('consistent')->assertSuccessful();
        $this->apply($id, 'already_consistent');
        Queue::assertPushed(SyncArtworkColbertJob::class, 2);
    }

    public static function repairableTexts(): array
    {
        return ['missing' => [null], 'stale' => [str_repeat('e', 64)]];
    }

    public function test_apply_deletes_colbert_document_for_a_physically_deleted_artwork(): void
    {
        [$artwork, $intent, $oldHash] = $this->publicationCleanup(physical: true);
        $id = $artwork->id;
        $current = $this->remote($id, $oldHash);
        Http::fake([
            'ai.test/*' => Http::response($this->absentPoint($id)),
            'colbert.test/*' => function ($request) use ($id, $oldHash, &$current) {
                if ($request->method() === 'GET') {
                    return Http::response($current);
                }
                $this->assertSame('DELETE', $request->method());
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $this->assertSame($oldHash, $query['expected_previous_hash']);
                $this->assertSame(str_repeat('a', 32), $query['expected_previous_version']);
                $current = [...$this->remote($id), 'version' => str_repeat('b', 32)];

                return Http::response($current + ['status' => 'deleted']);
            },
        ]);

        $this->apply($id, 'repair_queued');
        Queue::assertPushedOn('ai', DeleteArtworkColbertJob::class);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        $this->runRepair(Queue::pushed(DeleteArtworkColbertJob::class)->sole());
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'DELETE'));
        $this->assertDatabaseMissing('artworks', ['id' => $id]);
        $this->assertSame('success', $intent->refresh()->status);
        $this->artisan('ai:reconcile-colbert', ['--id' => $id, '--dry-run' => true])
            ->expectsOutputToContain('consistent')->assertSuccessful();
        $this->apply($id, 'already_consistent');
        Queue::assertPushed(DeleteArtworkColbertJob::class, 1);
    }

    #[DataProvider('unconfirmedRepairs')]
    public function test_apply_declines_unconfirmed_evidence(string $case): void
    {
        [$artwork, , $expected, $point] = $this->indexed();
        match ($case) {
            'missing point' => $point = $this->absentPoint($artwork->id, 'missing'),
            'missing saved text' => $expected->delete(),
            'corrupt saved text' => DB::table('artwork_colbert_expectations')->where('artwork_id', $artwork->id)
                ->update(['searchable_text' => 'corrupt ciphertext']),
        };
        Http::fake(['ai.test/*' => Http::response($point), 'colbert.test/*' => Http::response($this->remote($artwork->id))]);
        $this->apply($artwork->id, 'manual_review_required');
        Queue::assertNothingPushed();
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public static function unconfirmedRepairs(): array
    {
        return [['missing point'], ['missing saved text'], ['corrupt saved text']];
    }

    public function test_apply_does_not_bypass_a_newer_pending_dense_cleanup(): void
    {
        [$artwork, $intent, $oldHash] = $this->publicationCleanup(physical: true);
        $newer = $intent->replicate();
        $newer->forceFill(['request_id' => 'newer-publication-cleanup', 'status' => 'pending', 'finished_at' => null])->save();
        Http::fake([
            'ai.test/*' => Http::response($this->absentPoint($artwork->id)),
            'colbert.test/*' => Http::response($this->remote($artwork->id, $oldHash)),
        ]);

        $this->apply($artwork->id, 'manual_review_required');
        Queue::assertNothingPushed();
    }

    public function test_apply_leaves_deletion_with_a_remaining_qdrant_point_for_review(): void
    {
        [$artwork, , $oldHash, $point] = $this->publicationCleanup(physical: false);
        Http::fake([
            'ai.test/*' => Http::response($point),
            'colbert.test/*' => Http::response($this->remote($artwork->id, $oldHash)),
        ]);
        $this->apply($artwork->id, 'manual_review_required');
        Queue::assertNothingPushed();
    }

    public function test_republished_artwork_cancels_queued_delete(): void
    {
        [$artwork, , $oldHash] = $this->publicationCleanup(physical: false);
        Http::fake([
            'ai.test/*' => Http::response($this->absentPoint($artwork->id, 'missing')),
            'colbert.test/*' => Http::response($this->remote($artwork->id, $oldHash)),
        ]);
        $this->apply($artwork->id, 'repair_queued');
        $artwork->update(['is_private' => false]);
        $this->runRepair(Queue::pushed(DeleteArtworkColbertJob::class)->sole());
        Http::assertSentCount(2);
    }

    #[DataProvider('changedBeforeWorker')]
    public function test_changed_laravel_revision_cancels_queued_sync(string $case): void
    {
        [$artwork, $metadata, $expected, $point] = $this->indexed();
        Http::fake(['ai.test/*' => Http::response($point), 'colbert.test/*' => Http::response($this->remote($artwork->id))]);
        $this->apply($artwork->id, 'repair_queued');
        match ($case) {
            'private' => $artwork->update(['is_private' => true]),
            'newer attempt' => $metadata->increment('attempts'),
            'expected text removed' => $expected->delete(),
        };
        $this->runRepair(Queue::pushed(SyncArtworkColbertJob::class)->sole());
        Http::assertSentCount(2);
    }

    public static function changedBeforeWorker(): array
    {
        return [['private'], ['newer attempt'], ['expected text removed']];
    }

    public function test_missing_qdrant_point_after_apply_prevents_the_queued_write(): void
    {
        [$artwork, , , $point] = $this->indexed();
        Http::fake([
            'ai.test/*' => Http::sequence()->push($point)->push($this->absentPoint($artwork->id, 'missing')),
            'colbert.test/*' => Http::response($this->remote($artwork->id)),
        ]);
        $this->apply($artwork->id, 'repair_queued');
        $this->runRepair(Queue::pushed(SyncArtworkColbertJob::class)->sole());
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_new_colbert_version_after_apply_is_never_overwritten(): void
    {
        [$artwork, , , $point] = $this->indexed();
        $old = $this->remote($artwork->id, str_repeat('e', 64));
        Http::fake([
            'ai.test/*' => Http::response($point),
            'colbert.test/*' => Http::sequence()->push($old)->push([...$old, 'version' => str_repeat('b', 32)]),
        ]);
        $this->apply($artwork->id, 'repair_queued');
        $job = Queue::pushed(SyncArtworkColbertJob::class)->sole()->withFakeQueueInteractions();
        $this->runRepair($job);
        $job->assertFailedWith(new ColbertDocumentException('ColBERT document changed after repair inspection.', 409));
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_new_colbert_version_after_delete_is_queued_is_never_deleted(): void
    {
        [$artwork, , $oldHash] = $this->publicationCleanup(physical: true);
        $old = $this->remote($artwork->id, $oldHash);
        $new = [...$old, 'version' => str_repeat('b', 32)];
        Http::fake([
            'ai.test/*' => Http::response($this->absentPoint($artwork->id)),
            'colbert.test/*' => Http::sequence()->push($old)->push($new)->push($new),
        ]);
        $this->apply($artwork->id, 'repair_queued');
        $job = Queue::pushed(DeleteArtworkColbertJob::class)->sole()->withFakeQueueInteractions();
        $this->runRepair($job);
        $job->assertFailedWith(new ColbertDocumentException('ColBERT document changed after repair inspection.', 409));
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    #[DataProvider('repairFailures')]
    public function test_repair_job_keeps_its_precondition_on_http_failure(int $status): void
    {
        [$artwork, , , $point] = $this->indexed();
        $remote = $this->remote($artwork->id, str_repeat('e', 64));
        $responses = Http::sequence()->push($remote)->push($remote)->push([], $status);
        $responses->push([...$remote, 'version' => str_repeat('b', 32)]);
        Http::fake(['ai.test/*' => Http::response($point), 'colbert.test/*' => $responses]);
        $this->apply($artwork->id, 'repair_queued');
        $job = Queue::pushed(SyncArtworkColbertJob::class)->sole()->withFakeQueueInteractions();
        if ($status === 409) {
            $this->runRepair($job);
            $job->assertFailedWith(new ColbertDocumentException('ColBERT document conflict could not be resolved safely.', 409));
        } else {
            try {
                $this->runRepair($job);
                $this->fail('Expected a retryable ColBERT failure.');
            } catch (ColbertDocumentException $error) {
                $this->assertSame(503, $error->getCode());
                $this->assertSame([15, 60, 120], $job->backoff());
            }
            $this->runRepair($job);
            $job->assertFailedWith(new ColbertDocumentException('ColBERT document changed after repair inspection.', 409));
        }
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'PUT'));
    }

    public static function repairFailures(): array
    {
        return [[409], [503]];
    }

    public function test_apply_reports_queue_failure_and_releases_existing_artwork_lock(): void
    {
        [$artwork, , , $point] = $this->indexed();
        Log::spy();
        Queue::shouldReceive('connection')->once()->andThrow(new RuntimeException('sensitive Redis details'));
        Http::fake(['ai.test/*' => Http::response($point), 'colbert.test/*' => Http::response($this->remote($artwork->id))]);

        $this->apply($artwork->id, 'queue_failed');
        Log::shouldHaveReceived('error')->once()->with('Could not queue ColBERT repair.', [
            'artwork_id' => $artwork->id, 'exception_type' => RuntimeException::class,
        ]);
        $lock = app(Cache::class)->lock(ArtworkAiJobLock::key($artwork->id), ArtworkAiJobLock::LOCK_TTL);
        $this->assertTrue($lock->get());
        try {
            $this->apply($artwork->id, 'skipped_stale');
            Http::assertSentCount(2);
        } finally {
            $lock->release();
        }
    }

    public function test_apply_refuses_sync_queue_and_an_unavailable_service(): void
    {
        [$artwork, , , $point] = $this->indexed();
        Queue::shouldReceive('connection')->once()->andReturn(new SyncQueue);
        Http::fake(['ai.test/*' => Http::response($point), 'colbert.test/*' => Http::sequence()
            ->push($this->remote($artwork->id))->push([], 503)]);
        $this->apply($artwork->id, 'queue_failed');
        $this->apply($artwork->id, 'service_unavailable');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_apply_requires_both_flags_and_explicit_matching_id(): void
    {
        Http::fake();
        foreach ([
            ['--apply' => true],
            ['--apply' => true, '--id' => 42, '--confirm-id' => 43],
            ['--apply' => true, '--id' => 42, '--confirm-id' => 42, '--limit' => 20],
            ['--apply' => true, '--id' => 42, '--confirm-id' => 42, '--dry-run' => true],
            ['--id' => 42, '--confirm-id' => 42],
        ] as $arguments) {
            $this->artisan('ai:reconcile-colbert', $arguments)->assertExitCode(2);
        }
        foreach (['ai.colbert_sync.auto_enabled', 'ai.colbert_sync.enabled'] as $flag) {
            config([$flag => false]);
            $this->apply(42, 'manual_review_required');
            config([$flag => true]);
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    private function apply(int $id, string $result): void
    {
        $this->artisan('ai:reconcile-colbert', ['--id' => $id, '--apply' => true, '--confirm-id' => $id])
            ->expectsOutput($result)
            ->assertExitCode(in_array($result, ['repair_queued', 'already_consistent'], true) ? 0 : 1);
    }

    private function publicationCleanup(bool $physical): array
    {
        [$artwork, , $expected, $point] = $this->indexed();
        $physical ? app(DeleteArtwork::class)->handle($artwork) : $artwork->update(['is_private' => true]);
        $intent = AiProcessingRun::sole();
        $intent->update(['status' => 'success', 'finished_at' => now()]);
        Queue::fake();

        return [$artwork, $intent, $expected->text_sha256, $point];
    }

    private function runRepair(SyncArtworkColbertJob|DeleteArtworkColbertJob $job): void
    {
        $job->middleware()[0]->handle($job, fn () => app()->call([$job, 'handle']));
    }

    private function indexed(): array
    {
        $artwork = Artwork::factory()->create();
        $revision = app(ArtworkIndexingService::class)->contentHash($artwork);
        $pointId = '00000000-0000-4000-8000-000000000001';
        $metadata = $artwork->aiMetadata()->create([
            'status' => ArtworkAiMetadata::STATUS_INDEXED, 'attempts' => 1, 'content_hash' => $revision,
            'qdrant_point_id' => $pointId, 'metadata_schema_version' => config('ai.schema_version'),
            'prompt_version' => config('ai.prompt_version'), 'embedding_dimension' => 1024,
        ]);
        $expected = ArtworkColbertExpectation::create([
            'artwork_id' => $artwork->id, 'state' => ArtworkColbertExpectation::INDEXED,
            'revision_hash' => $revision, 'dense_attempt' => 1, 'searchable_text' => self::TEXT,
            'text_sha256' => hash('sha256', self::TEXT),
        ]);
        $point = $this->absentPoint($artwork->id);
        $point['saved_point'] = 'matched';
        $point['vector_present'] = true;
        $point['points'] = [[
            'id' => $pointId, 'artwork_id' => $artwork->id, 'content_hash' => $revision,
            'schema_version' => config('ai.schema_version'), 'prompt_version' => config('ai.prompt_version'),
            'is_published' => true, 'is_private' => false, 'is_blocked' => false,
        ]];

        return [$artwork, $metadata, $expected, $point];
    }

    private function absentPoint(int $id, string $savedPoint = 'not_provided'): array
    {
        return [
            'artwork_id' => $id, 'collection' => 'test', 'dimension' => 1024, 'saved_point' => $savedPoint,
            'points' => [], 'has_duplicates' => false, 'vector_present' => null,
        ];
    }

    private function remote(int $id, ?string $hash = null): array
    {
        return [
            'artwork_id' => $id, 'exists' => $hash !== null, 'content_hash' => $hash,
            'version' => $hash === null ? null : str_repeat('a', 32),
        ];
    }
}
