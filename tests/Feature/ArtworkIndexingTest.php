<?php

namespace Tests\Feature;

use App\Actions\Artwork\UpdateArtwork;
use App\Contracts\AiServiceClient;
use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Jobs\DeleteArtworkIndexJob;
use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Models\Tag;
use App\Services\AiService\ArtworkIndexingService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArtworkIndexingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => true, 'ai.service_url' => 'http://ai.test', 'ai.retry_times' => 1]);
        Http::preventStrayRequests();
    }

    private function artwork(): Artwork
    {
        return Artwork::factory()->create(['title' => 'Sunset', 'is_published' => true, 'is_private' => false]);
    }

    public function test_job_records_dimension_nonnegative_latency_and_user_tags(): void
    {
        $artwork = $this->artwork();
        $artwork->tags()->attach(Tag::create(['name' => 'author tag']));
        Http::fake([
            '*/api/v1/artworks/analyze' => function () use ($artwork) {
                $run = AiProcessingRun::sole();
                $this->assertSame('processing', $run->status);
                $this->assertSame('analysis', $run->parameters['stage']);
                $this->assertNull($run->finished_at);
                usleep(5000);

                return Http::response(['artwork_id' => $artwork->id, 'summary' => 'Sunset landscape', 'styles' => ['watercolor']]);
            },
            '*/api/v1/artworks/index' => function () {
                $this->assertSame('embedding_and_indexing', AiProcessingRun::sole()->parameters['stage']);

                return Http::response(['qdrant_point_id' => 'test-point', 'embedding_dimension' => 1024]);
            },
        ]);
        $hash = app(ArtworkIndexingService::class)->contentHash($artwork);
        (new AnalyzeAndIndexArtworkJob($artwork->id, $hash))->handle(app(AiServiceClient::class));
        $metadata = $artwork->aiMetadata()->firstOrFail();
        $this->assertTrue($metadata->isIndexed());
        $this->assertSame(1024, $metadata->embedding_dimension);
        $this->assertSame($hash, $metadata->content_hash);
        $this->assertGreaterThanOrEqual(0, AiProcessingRun::firstOrFail()->latency_ms);
        $this->assertSame('success', AiProcessingRun::sole()->status);
        $this->assertSame('completed', AiProcessingRun::sole()->parameters['stage']);
        $this->assertNotNull(AiProcessingRun::sole()->finished_at);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/index')
            && str_contains($r['searchable_text'], 'author tag')
            && str_contains($r['searchable_text'], 'watercolor')
            && $r['payload']['is_published'] === true);
    }

    #[DataProvider('indexableEdits')]
    public function test_relevant_artwork_edit_changes_hash_and_dispatches_reindex(array $changes): void
    {
        Queue::fake();
        $artwork = $this->artwork();
        $hash = app(ArtworkIndexingService::class)->contentHash($artwork);
        ArtworkAiMetadata::create(['artwork_id' => $artwork->id, 'status' => 'indexed', 'content_hash' => $hash]);
        app(UpdateArtwork::class)->handle($artwork, $artwork->user, $changes);
        $this->assertNotSame($hash, app(ArtworkIndexingService::class)->contentHash($artwork->fresh()));
        Queue::assertPushedOn('ai', AnalyzeAndIndexArtworkJob::class);
        $this->assertSame('pending', $artwork->aiMetadata()->first()->status);
    }

    public static function indexableEdits(): array
    {
        return [
            'tags only' => [['tags' => ['new tag']]],
            'title and description' => [['title' => 'New title', 'description' => 'New description']],
        ];
    }

    public function test_invalid_index_response_does_not_mark_artwork_indexed(): void
    {
        $artwork = $this->artwork();
        Http::fake([
            '*/artworks/analyze' => Http::response(['artwork_id' => $artwork->id, 'summary' => 'Test']),
            '*/artworks/index' => Http::response(['status' => 'indexed']),
        ]);
        try {
            (new AnalyzeAndIndexArtworkJob($artwork->id))->handle(app(AiServiceClient::class));
            $this->fail('Expected an invalid index response error.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('point ID', $exception->getMessage());
        }
        $this->assertSame('failed', $artwork->aiMetadata()->first()->status);
        $this->assertSame('failed', AiProcessingRun::first()->status);
    }

    public function test_unchanged_index_does_not_dispatch_but_force_does(): void
    {
        Queue::fake();
        $artwork = $this->artwork();
        $service = app(ArtworkIndexingService::class);
        ArtworkAiMetadata::create(['artwork_id' => $artwork->id, 'status' => 'indexed', 'content_hash' => $service->contentHash($artwork)]);
        $this->assertFalse($service->dispatch($artwork));
        Queue::assertNothingPushed();
        $this->assertTrue($service->dispatch($artwork, force: true));
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, 1);
    }

    public function test_obsolete_job_does_not_call_ollama_and_reschedules_current_revision(): void
    {
        Queue::fake();
        $artwork = $this->artwork();
        (new AnalyzeAndIndexArtworkJob($artwork->id, 'old-hash'))->handle(app(AiServiceClient::class));
        Http::assertNothingSent();
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, 1);
    }

    public function test_analysis_result_for_changed_artwork_is_not_indexed(): void
    {
        Queue::fake();
        $artwork = $this->artwork();
        Http::fake(['*/artworks/analyze' => function () use ($artwork) {
            $artwork->update(['title' => 'New version']);

            return Http::response(['artwork_id' => $artwork->id, 'summary' => 'Old version']);
        }]);
        (new AnalyzeAndIndexArtworkJob($artwork->id))->handle(app(AiServiceClient::class));
        Http::assertSentCount(1);
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class);
        $this->assertSame('pending', $artwork->aiMetadata()->first()->status);
        $this->assertSame('skipped', AiProcessingRun::sole()->status);
        $this->assertSame('revision_changed', AiProcessingRun::sole()->parameters['reason']);
        $this->assertNotNull(AiProcessingRun::sole()->finished_at);
    }

    public function test_delete_job_logs_after_artwork_deletion_without_foreign_key_error(): void
    {
        $artwork = $this->artwork();
        $id = $artwork->id;
        $artwork->delete();
        Http::fake(['*/index' => Http::response(['status' => 'deleted'])]);
        (new DeleteArtworkIndexJob($id))->handle(app(AiServiceClient::class));
        $run = AiProcessingRun::firstOrFail();
        $this->assertNull($run->artwork_id);
        $this->assertSame($id, $run->parameters['deleted_artwork_id']);
        $this->assertSame('success', $run->status);
        $this->assertGreaterThanOrEqual(0, $run->latency_ms);
    }

    public function test_failed_delete_logs_original_error_instead_of_foreign_key_exception(): void
    {
        Http::fake(['*/index' => Http::response(['detail' => 'offline'], 503)]);
        try {
            (new DeleteArtworkIndexJob(12345))->handle(app(AiServiceClient::class));
            $this->fail('Expected HTTP failure.');
        } catch (RequestException $exception) {
            $this->assertSame(503, $exception->response->status());
        }
        $this->assertSame('failed', AiProcessingRun::firstOrFail()->status);
        $this->assertNull(AiProcessingRun::first()->artwork_id);
    }

    public function test_manual_index_rejects_draft_without_pending_metadata(): void
    {
        Queue::fake();
        $draft = Artwork::factory()->create(['is_published' => false]);
        $this->artisan('ai:index-artwork', ['id' => $draft->id])->assertFailed();
        $this->assertDatabaseCount('artwork_ai_metadata', 0);
        Queue::assertNothingPushed();
    }

    #[DataProvider('indexedAges')]
    public function test_reconcile_does_not_reindex_unchanged_artwork_due_to_age(int $ageHours, array $options): void
    {
        Queue::fake();
        $artwork = $this->artwork();
        $indexedAt = now()->subHours($ageHours)->startOfSecond();
        $metadata = $artwork->aiMetadata()->create([
            'status' => ArtworkAiMetadata::STATUS_INDEXED,
            'content_hash' => app(ArtworkIndexingService::class)->contentHash($artwork),
            'indexed_at' => $indexedAt,
        ]);

        $this->artisan('ai:reconcile', $options)->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertTrue($metadata->refresh()->isIndexed());
        $this->assertTrue($metadata->indexed_at->equalTo($indexedAt));
    }

    public static function indexedAges(): array
    {
        return [
            'older than 24 hours' => [25, []],
            'older than seven days' => [192, []],
            'deprecated age option' => [2, ['--stale-hours' => 1]],
        ];
    }

    #[DataProvider('recoverableMetadata')]
    public function test_reconcile_queues_recoverable_metadata(string $status, int $ageHours, int $attempts): void
    {
        Queue::fake();
        $artwork = $this->artwork();
        $metadata = $artwork->aiMetadata()->create(['status' => $status, 'attempts' => $attempts]);
        $metadata->forceFill(['updated_at' => now()->subHours($ageHours)])->save();

        $this->artisan('ai:reconcile', ['--max-pending-hours' => 3])->assertSuccessful();

        Queue::assertPushedOn('ai', AnalyzeAndIndexArtworkJob::class);
        $this->assertSame(ArtworkAiMetadata::STATUS_PENDING, $metadata->refresh()->status);
    }

    public static function recoverableMetadata(): array
    {
        return [
            'stale' => [ArtworkAiMetadata::STATUS_STALE, 0, 1],
            'stuck processing' => [ArtworkAiMetadata::STATUS_PROCESSING, 4, 1],
            'old pending' => [ArtworkAiMetadata::STATUS_PENDING, 4, 0],
            'failed with attempts remaining' => [ArtworkAiMetadata::STATUS_FAILED, 0, 4],
        ];
    }

    public function test_reconcile_does_not_queue_failed_metadata_at_the_attempt_limit(): void
    {
        Queue::fake();
        $metadata = $this->artwork()->aiMetadata()->create([
            'status' => ArtworkAiMetadata::STATUS_FAILED,
            'attempts' => 5,
        ]);

        $this->artisan('ai:reconcile')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertTrue($metadata->refresh()->isFailed());
    }

    public function test_reconcile_does_not_queue_stale_metadata_when_indexing_is_disabled(): void
    {
        Queue::fake();
        $artwork = $this->artwork();
        $artwork->forceFill(['ai_indexing_enabled' => false])->save();
        $metadata = $artwork->aiMetadata()->create(['status' => ArtworkAiMetadata::STATUS_STALE]);

        $this->artisan('ai:reconcile')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertTrue($metadata->refresh()->isStale());
    }

    public function test_reconcile_queues_published_artwork_without_metadata(): void
    {
        Queue::fake();
        $artwork = $this->artwork();

        $this->artisan('ai:reconcile')->assertSuccessful();

        Queue::assertPushedOn('ai', AnalyzeAndIndexArtworkJob::class);
        $this->assertSame(ArtworkAiMetadata::STATUS_PENDING, $artwork->aiMetadata()->firstOrFail()->status);
    }

    public function test_successive_scheduled_reconciliations_leave_unchanged_indexed_artwork_alone(): void
    {
        Queue::fake();
        $artwork = $this->artwork();
        $firstRun = now()->startOfHour()->addMinutes(30);
        $metadata = $artwork->aiMetadata()->create([
            'status' => ArtworkAiMetadata::STATUS_INDEXED,
            'content_hash' => app(ArtworkIndexingService::class)->contentHash($artwork),
            'indexed_at' => $firstRun->copy()->subDays(8),
        ]);

        foreach ([$firstRun, $firstRun->copy()->addMinutes(30)] as $runAt) {
            $this->travelTo($runAt, function () {
                $event = app(Schedule::class)->dueEvents($this->app)
                    ->sole(fn (Event $event) => str_contains($event->command ?? '', 'ai:reconcile --limit=25'));
                $this->assertTrue($event->filtersPass($this->app));

                // Run the registered command in-process so SQLite and the queue fake are shared.
                $this->artisan(strstr($event->command, 'ai:reconcile'))->assertSuccessful();
            });
        }

        Queue::assertNothingPushed();
        $this->assertTrue($metadata->refresh()->isIndexed());
    }
}
