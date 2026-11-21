<?php

namespace Tests\Feature;

use App\Contracts\AiServiceClient;
use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Services\AiService\ArtworkIndexingService;
use App\Services\AiService\ArtworkIndexInspectionService;
use App\Services\AiService\ArtworkIndexStatusRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\UniqueJobSkipped;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ArtworkIndexDispatchTest extends TestCase
{
    use RefreshDatabase;

    private QueueManager $queueManager;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => true, 'ai.queue' => 'ai', 'ai.service_url' => 'http://ai.test']);
        $this->queueManager = app('queue');
        Queue::fake();
        Http::fake();
    }

    public function test_overlapping_dispatches_submit_only_one_unique_job_and_do_not_write_pending(): void
    {
        $artwork = Artwork::factory()->create();
        $indexing = app(ArtworkIndexingService::class);
        Event::fake([UniqueJobSkipped::class]);

        // Interleave the second producer after Laravel's unique lock has been acquired by the first.
        Queue::beforePushing(function () use ($artwork, $indexing) {
            $this->assertFalse($indexing->dispatch($artwork));
            $this->assertDatabaseCount('artwork_ai_metadata', 0);
        });

        $this->assertTrue($indexing->dispatch($artwork));
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, 1);
        Event::assertDispatched(UniqueJobSkipped::class);
        $this->assertDatabaseCount('artwork_ai_metadata', 0);
    }

    public function test_a_new_revision_can_be_queued_while_indexing_holds_the_artwork_lock(): void
    {
        $artwork = Artwork::factory()->create();
        $indexing = app(ArtworkIndexingService::class);
        $active = new AnalyzeAndIndexArtworkJob($artwork->id, $indexing->contentHash($artwork));
        $metadata = $artwork->aiMetadata()->create(['status' => ArtworkAiMetadata::STATUS_PROCESSING, 'attempts' => 2]);
        $before = $metadata->refresh()->getRawOriginal();

        $active->middleware()[0]->handle($active, function () use ($artwork, $indexing) {
            $artwork->update(['title' => 'A concurrent edit']);
            $this->assertTrue($indexing->dispatch($artwork));
        });

        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, fn ($job) => $job->uniqueId() !== $active->uniqueId());
        $this->assertSame($before, $metadata->refresh()->getRawOriginal());
    }

    public function test_the_worker_releases_the_unique_lock_acquired_at_submission(): void
    {
        Queue::swap($this->queueManager);
        config(['queue.default' => 'database']);
        $artwork = Artwork::factory()->create();
        $indexing = app(ArtworkIndexingService::class);

        $this->assertTrue($indexing->dispatch($artwork));
        $this->assertFalse($indexing->dispatch($artwork));
        $this->assertDatabaseCount('jobs', 1);

        $artwork->aiMetadata()->create([
            'status' => ArtworkAiMetadata::STATUS_INDEXED,
            'content_hash' => $indexing->contentHash($artwork),
        ]);
        Queue::connection()->pop('ai')->fire();

        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
        $this->assertTrue($indexing->dispatch($artwork, force: true));
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_dispatch_does_not_change_recovery_evidence_during_preflight(): void
    {
        $artwork = Artwork::factory()->create();
        $metadata = $artwork->aiMetadata()->create(['status' => ArtworkAiMetadata::STATUS_FAILED, 'indexed_at' => now()]);
        $before = $metadata->refresh()->getRawOriginal();
        $inspection = $this->mock(ArtworkIndexInspectionService::class);
        $initial = ['group' => 'A', 'mysql_status' => 'failed', 'recovery_evidence' => ['sql' => 'test', 'indexed_at' => now()]];
        $inspection->shouldReceive('inspect')->once()->andReturnUsing(function () use ($artwork, $initial, $metadata, $before) {
            $this->assertTrue(app(ArtworkIndexingService::class)->dispatch($artwork));
            $this->assertSame($before, $metadata->refresh()->getRawOriginal());

            return $initial;
        });
        $inspection->shouldReceive('sqlRevisionMatches')->once()->with($artwork->id, 'test')->andReturnTrue();

        $this->assertStringContainsString('Status writes are disabled', app(ArtworkIndexStatusRecoveryService::class)->refusalReason($artwork->id, $initial));
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, 1);
    }

    public function test_only_the_latest_revision_is_submitted_after_the_outer_commit(): void
    {
        $artwork = Artwork::factory()->create();
        $indexing = app(ArtworkIndexingService::class);
        $originalHash = $indexing->contentHash($artwork);
        DB::transaction(function () use ($artwork, $indexing) {
            DB::transaction(fn () => $this->assertTrue($indexing->dispatch($artwork)));
            $artwork->fresh()->update(['title' => 'Committed title']);
            Queue::assertNothingPushed();
            $this->assertDatabaseCount('artwork_ai_metadata', 0);
        });

        $hash = $indexing->contentHash($artwork->fresh());
        $this->assertNotSame($originalHash, $hash);
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, fn ($job) => str_ends_with($job->uniqueId(), $hash));
        $this->assertDatabaseCount('artwork_ai_metadata', 0);
    }

    public function test_outer_rollback_after_inner_commit_never_enqueues_or_takes_a_unique_lock(): void
    {
        $artwork = Artwork::factory()->create();
        $indexing = app(ArtworkIndexingService::class);
        DB::beginTransaction();
        try {
            DB::transaction(fn () => $this->assertTrue($indexing->dispatch($artwork)));
            Queue::assertNothingPushed();
        } finally {
            DB::rollBack();
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('artwork_ai_metadata', 0);
        $this->assertTrue($indexing->dispatch($artwork));
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, 1);
    }

    public function test_enqueue_failure_leaves_metadata_intact_and_allows_retry(): void
    {
        $artwork = Artwork::factory()->create();
        $indexing = app(ArtworkIndexingService::class);
        $metadata = $artwork->aiMetadata()->create(['status' => ArtworkAiMetadata::STATUS_FAILED, 'attempts' => 1]);
        $before = $metadata->refresh()->getRawOriginal();
        Queue::beforePushing(fn () => throw new RuntimeException('Queue unavailable'));

        try {
            $indexing->dispatch($artwork);
            $this->fail('Expected the queue exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Queue unavailable', $exception->getMessage());
        }

        Queue::assertNothingPushed();
        $this->assertSame($before, $metadata->refresh()->getRawOriginal());
        Queue::fake();
        $this->assertTrue($indexing->dispatch($artwork));
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, 1);
    }

    public function test_a_job_queues_its_replacement_while_it_owns_the_artwork_lock(): void
    {
        $artwork = Artwork::factory()->create();
        $job = new AnalyzeAndIndexArtworkJob($artwork->id, 'outdated-hash');
        $job->middleware()[0]->handle($job, fn () => $job->handle(app(AiServiceClient::class)));

        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, 1);
        $this->assertDatabaseCount('artwork_ai_metadata', 0);
        Http::assertNothingSent();
    }

    public function test_a_job_releases_itself_when_the_revision_reverts_to_its_own_unique_key(): void
    {
        Queue::swap($this->queueManager);
        config(['queue.default' => 'database']);
        $artwork = Artwork::factory()->create(['title' => 'Original revision']);
        $indexing = app(ArtworkIndexingService::class);
        $this->assertTrue($indexing->dispatch($artwork));
        $artwork->update(['title' => 'Temporary revision']);

        $this->partialMock(ArtworkIndexingService::class)->shouldReceive('dispatch')->once()
            ->andReturnUsing(function () use ($artwork, $indexing) {
                $artwork->update(['title' => 'Original revision']);

                return $indexing->dispatch($artwork);
            });

        $queue = Queue::connection();
        $reserved = $queue->pop('ai');
        $reserved->fire();

        $this->assertTrue($reserved->isReleased());
        $this->assertDatabaseCount('jobs', 1);
        $this->assertFalse($indexing->dispatch($artwork));
        $this->assertDatabaseCount('artwork_ai_metadata', 0);
        Http::assertNothingSent();
    }

    public function test_stale_reconcile_selection_cannot_queue_after_a_newer_operation(): void
    {
        $artwork = Artwork::factory()->create();
        $metadata = $artwork->aiMetadata()->create(['status' => ArtworkAiMetadata::STATUS_STALE]);
        $observed = $metadata->fresh();
        $metadata->update(['status' => ArtworkAiMetadata::STATUS_PROCESSING, 'attempts' => 1]);

        $this->assertFalse(app(ArtworkIndexingService::class)->dispatchForReconciliation($artwork->id, $observed));
        Queue::assertNothingPushed();
        $this->assertSame(ArtworkAiMetadata::STATUS_PROCESSING, $metadata->refresh()->status);
    }

    public function test_reconcile_counts_suppressed_jobs_as_skipped_and_preserves_status(): void
    {
        $artwork = Artwork::factory()->create();
        $artwork->aiMetadata()->create(['status' => ArtworkAiMetadata::STATUS_STALE]);
        $this->assertTrue(app(ArtworkIndexingService::class)->dispatch($artwork));
        Artwork::factory()->create();

        $this->artisan('ai:reconcile')->expectsOutput('Reconciliation complete: 1 dispatched, 1 skipped, 0 failed.')->assertSuccessful();

        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, 2);
        $this->assertTrue($artwork->aiMetadata()->firstOrFail()->isStale());
        $this->assertDatabaseCount('artwork_ai_metadata', 1);
    }

    public function test_reconcile_reports_a_queue_error_separately_from_a_skipped_job(): void
    {
        $artwork = Artwork::factory()->create();
        Queue::beforePushing(fn () => throw new RuntimeException('Queue unavailable'));

        $this->artisan('ai:reconcile')
            ->expectsOutput("Queue submission failed for artwork #{$artwork->id}.")
            ->expectsOutput('Reconciliation complete: 0 dispatched, 0 skipped, 1 failed.')
            ->assertFailed();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('artwork_ai_metadata', 0);
    }

    public function test_reconcile_keeps_unpublished_metadata_and_uses_its_limit_for_eligible_work(): void
    {
        $draft = Artwork::factory()->create(['is_published' => false]);
        $metadata = $draft->aiMetadata()->create(['status' => ArtworkAiMetadata::STATUS_STALE]);
        $published = Artwork::factory()->create();

        $this->artisan('ai:reconcile', ['--limit' => 1])
            ->expectsOutput('Reconciliation complete: 1 dispatched, 0 skipped, 0 failed.')
            ->assertSuccessful();

        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, 1);
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, fn ($job) => str_starts_with($job->uniqueId(), "analyze-artwork-{$published->id}-"));
        $this->assertTrue($metadata->fresh()->isStale());
    }

    public function test_a_queued_reconciliation_does_not_replace_newer_success(): void
    {
        $artwork = Artwork::factory()->create();
        $indexing = app(ArtworkIndexingService::class);
        $metadata = $artwork->aiMetadata()->create(['status' => ArtworkAiMetadata::STATUS_STALE]);
        $this->assertTrue($indexing->dispatchForReconciliation($artwork->id, $metadata->fresh()));
        $metadata->update(['status' => ArtworkAiMetadata::STATUS_INDEXED, 'content_hash' => $indexing->contentHash($artwork)]);
        $before = $metadata->refresh()->getRawOriginal();

        $job = Queue::pushed(AnalyzeAndIndexArtworkJob::class)->sole();
        $job->middleware()[0]->handle($job, fn () => $job->handle(app(AiServiceClient::class)));

        Http::assertNothingSent();
        $this->assertSame($before, $metadata->refresh()->getRawOriginal());
        $this->assertDatabaseCount('ai_processing_runs', 0);
    }

    public function test_disabling_indexing_before_commit_prevents_submission(): void
    {
        $artwork = Artwork::factory()->create();
        DB::transaction(function () use ($artwork) {
            app(ArtworkIndexingService::class)->dispatch($artwork);
            $artwork->fresh()->forceFill(['ai_indexing_enabled' => false])->save();
        });

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('artwork_ai_metadata', 0);
    }

    public function test_a_queued_reconciliation_does_not_replace_a_failed_job_with_exhausted_attempts(): void
    {
        $artwork = Artwork::factory()->create();
        $metadata = $artwork->aiMetadata()->create(['status' => ArtworkAiMetadata::STATUS_FAILED, 'attempts' => 4]);
        $this->assertTrue(app(ArtworkIndexingService::class)->dispatchForReconciliation($artwork->id, $metadata->fresh()));
        $metadata->update(['attempts' => 5]);
        $artwork->update(['title' => 'Edited after the scheduler tick']);
        $before = $metadata->refresh()->getRawOriginal();

        $job = Queue::pushed(AnalyzeAndIndexArtworkJob::class)->sole();
        $job->middleware()[0]->handle($job, fn () => $job->handle(app(AiServiceClient::class)));

        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, 1);
        Http::assertNothingSent();
        $this->assertSame($before, $metadata->refresh()->getRawOriginal());
        $this->assertDatabaseCount('ai_processing_runs', 0);
    }
}
