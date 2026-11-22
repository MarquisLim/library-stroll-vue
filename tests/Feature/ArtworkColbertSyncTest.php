<?php

namespace Tests\Feature;

use App\Contracts\AiServiceClient;
use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Jobs\DeleteArtworkIndexJob;
use App\Jobs\SyncArtworkColbertJob;
use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Services\AiService\ArtworkIndexingService;
use App\Services\AiService\ColbertDocumentException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ArtworkColbertSyncTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = '  A landscape near the river  ';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.enabled' => true,
            'ai.service_url' => 'http://ai.test',
            'ai.retry_times' => 1,
            'ai.colbert_sync.auto_enabled' => true,
            'ai.colbert_sync.enabled' => true,
            'ai.colbert_sync.url' => 'http://colbert.test',
            'ai.colbert_sync.api_key' => 'synthetic-sync-key',
        ]);
    }

    public function test_dense_success_queues_exact_text_after_commit(): void
    {
        Queue::fake();
        $artwork = Artwork::factory()->create();
        $denseText = null;
        $url = "http://colbert.test/api/v1/documents/{$artwork->id}";
        $this->fakeDense([
            'http://ai.test/api/v1/artworks/index' => function ($request) use (&$denseText) {
                $denseText = $request['searchable_text'];

                return Http::response(['qdrant_point_id' => 'test-point', 'embedding_dimension' => 1024]);
            },
            $url => function ($request) use (&$denseText, $artwork) {
                if ($request->method() === 'GET') {
                    return Http::response($this->remote($artwork));
                }

                $this->assertSame($denseText, $request['searchable_text']);
                $this->assertNull($request['expected_previous_hash']);
                $this->assertNull($request['expected_previous_version']);

                return Http::response($this->remote($artwork, hash('sha256', $denseText), str_repeat('c', 32)) + ['status' => 'created']);
            },
        ]);

        DB::transaction(function () use ($artwork) {
            $this->runDense($artwork);
            Queue::assertNothingPushed();
            Http::assertSentCount(2);
        });
        $this->assertDenseSucceeded($artwork);
        Queue::assertPushedOn('ai', SyncArtworkColbertJob::class);
        Queue::assertPushed(SyncArtworkColbertJob::class, 1);
        $queued = Queue::pushed(SyncArtworkColbertJob::class)->sole();
        app()->call([$queued, 'handle']);
        Http::assertSentCount(4);
    }

    public function test_rolled_back_dense_status_does_not_queue_colbert(): void
    {
        Queue::fake();
        $artwork = Artwork::factory()->create();
        $this->fakeDense();
        DB::beginTransaction();
        try {
            $this->runDense($artwork);
            Queue::assertNothingPushed();
        } finally {
            DB::rollBack();
        }
        Queue::assertNothingPushed();
        $this->assertNull($artwork->aiMetadata()->first());
    }

    public function test_dense_failure_does_not_queue_colbert(): void
    {
        Queue::fake();
        $artwork = Artwork::factory()->create();
        $this->fakeDense(['http://ai.test/api/v1/artworks/index' => Http::response(status: 503)]);
        try {
            $this->runDense($artwork);
            $this->fail('Expected Dense index failure.');
        } catch (RequestException $error) {
            $this->assertSame(503, $error->response->status());
        }

        Queue::assertNothingPushed();
        $this->assertSame('failed', $artwork->aiMetadata()->firstOrFail()->status);
    }

    public function test_revision_changed_during_dense_indexing_does_not_queue_colbert(): void
    {
        Queue::fake();
        $artwork = Artwork::factory()->create();
        $this->fakeDense(['http://ai.test/api/v1/artworks/index' => function () use ($artwork) {
            Artwork::whereKey($artwork->id)->update(['title' => 'new revision']);

            return Http::response(['qdrant_point_id' => 'test-point', 'embedding_dimension' => 1024]);
        }]);
        $this->runDense($artwork);
        Queue::assertNotPushed(SyncArtworkColbertJob::class);
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class);
        $this->assertSame('skipped', AiProcessingRun::sole()->status);
    }

    public function test_enqueue_error_is_reported_without_failing_dense(): void
    {
        Log::spy();
        Queue::shouldReceive('connection')->once()->andThrow(new RuntimeException('Sensitive queue failure details.'));
        $this->fakeDense();
        $artwork = Artwork::factory()->create();
        $this->runDense($artwork);

        $this->assertDenseSucceeded($artwork);
        Log::shouldHaveReceived('error')->once()->with('Could not enqueue ColBERT document sync after Dense indexing.', [
            'artwork_id' => $artwork->id,
            'request_id' => AiProcessingRun::sole()->request_id,
            'content_hash' => $artwork->aiMetadata()->firstOrFail()->content_hash,
            'exception_type' => RuntimeException::class,
        ]);
    }

    #[DataProvider('disabledFlags')]
    public function test_each_flag_can_disable_the_automatic_job(string $key): void
    {
        config([$key => false]);
        Queue::fake();
        $artwork = Artwork::factory()->create();
        $this->fakeDense();
        $this->runDense($artwork);
        $this->assertDenseSucceeded($artwork);
        Queue::assertNothingPushed();
        app()->call([$this->job($artwork), 'handle']);
        Http::assertSentCount(2);
    }

    public static function disabledFlags(): array
    {
        return [['ai.colbert_sync.auto_enabled'], ['ai.colbert_sync.enabled']];
    }

    public function test_identical_text_skips_put(): void
    {
        [$artwork, $job] = $this->indexedArtwork();
        Http::fake(['colbert.test/*' => Http::response($this->remote($artwork, hash('sha256', self::TEXT), str_repeat('c', 32)))]);
        app()->call([$job, 'handle']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    #[DataProvider('staleCases')]
    public function test_stale_artwork_or_dense_attempt_never_contacts_colbert(array $artworkChanges, array $metadataChanges): void
    {
        [$artwork, $job] = $this->indexedArtwork();
        if ($artworkChanges !== []) {
            Artwork::whereKey($artwork->id)->update($artworkChanges);
        }
        if ($metadataChanges !== []) {
            $artwork->aiMetadata()->update($metadataChanges);
        }
        Http::fake();
        app()->call([$job, 'handle']);
        Http::assertNothingSent();
    }

    public static function staleCases(): array
    {
        return [
            'private' => [['is_private' => true], []],
            'disabled' => [['ai_indexing_enabled' => false], []],
            'changed revision' => [['title' => 'changed'], []],
            'different metadata hash' => [[], ['content_hash' => str_repeat('a', 64)]],
            'stale status' => [[], ['status' => ArtworkAiMetadata::STATUS_STALE]],
            'newer analysis of same hash' => [[], ['attempts' => 2]],
        ];
    }

    public function test_update_rechecks_artwork_after_get(): void
    {
        [$artwork, $job] = $this->indexedArtwork();
        Http::fake(['colbert.test/*' => function () use ($artwork) {
            Artwork::whereKey($artwork->id)->update(['is_published' => false]);

            return Http::response($this->remote($artwork));
        }]);
        app()->call([$job, 'handle']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    public function test_conflict_retries_with_new_version_only_for_same_remote_text(): void
    {
        [$artwork, $job] = $this->indexedArtwork();
        $oldHash = str_repeat('a', 64);
        $firstVersion = str_repeat('b', 32);
        $nextVersion = str_repeat('c', 32);
        $versionAfterPut = str_repeat('d', 32);
        Http::fake(['colbert.test/*' => Http::sequence()
            ->push($this->remote($artwork, $oldHash, $firstVersion))
            ->push([], 409)
            ->push($this->remote($artwork, $oldHash, $nextVersion))
            ->push($this->remote($artwork, hash('sha256', self::TEXT), $versionAfterPut) + ['status' => 'updated'])]);

        app()->call([$job, 'handle']);
        $this->assertSame(['GET', 'PUT', 'GET', 'PUT'], Http::recorded()->map(fn ($pair) => $pair[0]->method())->all());
        $this->assertSame([$firstVersion, $nextVersion], Http::recorded(fn ($request) => $request->method() === 'PUT')
            ->map(fn ($pair) => $pair[0]['expected_previous_version'])->values()->all());
    }

    #[DataProvider('conflictStates')]
    public function test_conflict_with_changed_remote_state_is_never_overwritten(?string $hash, bool $isFailure): void
    {
        [$artwork, $job] = $this->indexedArtwork();
        $job->withFakeQueueInteractions();
        Http::fake(['colbert.test/*' => Http::sequence()
            ->push($this->remote($artwork, str_repeat('a', 64), str_repeat('b', 32)))
            ->push([], 409)
            ->push($this->remote($artwork, $hash, str_repeat('c', 32)))]);
        app()->call([$job, 'handle']);

        Http::assertSentCount(3);
        if ($isFailure) {
            $job->assertFailedWith(new ColbertDocumentException('ColBERT document conflict could not be resolved safely.', 409));
        } else {
            $job->assertNotFailed();
        }
    }

    public static function conflictStates(): array
    {
        return [
            'different text' => [str_repeat('f', 64), true],
            'deleted' => [null, true],
            'already updated' => [hash('sha256', self::TEXT), false],
        ];
    }

    public function test_conflict_stops_if_dense_attempt_changes_before_retry(): void
    {
        [$artwork, $job] = $this->indexedArtwork();
        Http::fake(['colbert.test/*' => function ($request) use ($artwork) {
            if ($request->method() === 'PUT') {
                $artwork->aiMetadata()->update(['attempts' => 2]);

                return Http::response(status: 409);
            }

            return Http::response($this->remote($artwork));
        }]);
        app()->call([$job, 'handle']);
        Http::assertSentCount(2);
    }

    public function test_conflict_followed_by_unavailability_does_not_drop_the_precondition(): void
    {
        [$artwork, $job] = $this->indexedArtwork();
        $job->withFakeQueueInteractions();
        Http::fake(['colbert.test/*' => Http::sequence()
            ->push($this->remote($artwork, str_repeat('a', 64), str_repeat('b', 32)))
            ->push([], 409)->push([], 503)]);
        app()->call([$job, 'handle']);

        $job->assertFailedWith(new ColbertDocumentException('ColBERT document conflict could not be resolved safely.', 409));
        Http::assertSentCount(3);
    }

    public function test_mismatched_result_hash_is_a_terminal_error(): void
    {
        [$artwork, $job] = $this->indexedArtwork();
        $job->withFakeQueueInteractions();
        Http::fake(['colbert.test/*' => Http::sequence()->push($this->remote($artwork))
            ->push($this->remote($artwork, str_repeat('f', 64), str_repeat('b', 32)) + ['status' => 'created'])]);
        app()->call([$job, 'handle']);

        $job->assertFailedWith(new ColbertDocumentException('ColBERT returned a different document hash.', 502));
        Http::assertSentCount(2);
    }

    #[DataProvider('transientErrors')]
    public function test_worker_backs_off_and_fails_on_third_transient_error_without_changing_dense(int $code): void
    {
        $this->freezeSecond();
        Log::spy();
        [$artwork, $job] = $this->indexedArtwork();
        $requests = 0;
        Http::fake(['colbert.test/*' => function ($request) use ($artwork, $code, &$requests) {
            $requests++;
            if ($request->method() === 'GET') {
                return Http::response($this->remote($artwork));
            }
            if ($code === 0) {
                throw new ConnectionException('Sensitive transport failure.');
            }

            return Http::response(['detail' => self::TEXT], $code);
        }]);

        $queue = Queue::connection('database');
        $worker = app('queue.worker')->setCache(app(Cache::class));
        $queue->push($job, queue: 'ai');
        $this->assertStringNotContainsString(self::TEXT, DB::table('jobs')->value('payload'));
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $reserved = $queue->pop('ai');
            $this->assertNotNull($reserved);
            try {
                $worker->process('database', $reserved, new WorkerOptions);
                $this->fail('Expected a ColBERT transport failure.');
            } catch (ColbertDocumentException $error) {
                $this->assertSame($code, $error->getCode());
                $this->assertStringNotContainsString(self::TEXT, $error->getMessage());
            }
            $this->assertSame($attempt === 3, $reserved->hasFailed());
            $this->assertNull($queue->pop('ai'));
            if ($attempt < 3) {
                $this->travel($attempt === 1 ? 15 : 60)->seconds();
            }
        }

        $this->assertSame(6, $requests);
        $this->assertSame(0, $queue->size('ai'));
        $this->assertTrue($artwork->aiMetadata()->firstOrFail()->isIndexed());
        $this->assertSame(1, $artwork->aiMetadata()->firstOrFail()->attempts);
        Log::shouldHaveReceived('error')->once()->with('ColBERT document sync failed.', [
            'artwork_id' => $artwork->id,
            'content_hash' => $artwork->aiMetadata()->firstOrFail()->content_hash,
            'dense_attempt' => 1,
            'exception_type' => ColbertDocumentException::class,
            'http_status' => $code,
        ]);
    }

    public static function transientErrors(): array
    {
        return ['network' => [0], 'unavailable' => [503]];
    }

    public function test_colbert_job_uses_shared_lock_and_waits_for_dense_delete(): void
    {
        [$artwork, $job] = $this->indexedArtwork();
        $job->withFakeQueueInteractions();
        foreach ([new AnalyzeAndIndexArtworkJob($artwork->id), new DeleteArtworkIndexJob($artwork->id)] as $holder) {
            $holder->middleware()[0]->handle($holder, function () use ($job) {
                $job->middleware()[0]->handle($job, fn () => $this->fail('ColBERT job started under an active lock.'));
                $job->assertReleased(30);
            });
        }
    }

    private function fakeDense(array $responses = []): void
    {
        Http::fake($responses + [
            'http://ai.test/api/v1/artworks/analyze' => Http::response(['artwork_id' => 1, 'summary' => 'River landscape']),
            'http://ai.test/api/v1/artworks/index' => Http::response(['qdrant_point_id' => 'test-point', 'embedding_dimension' => 1024]),
        ]);
    }

    private function runDense(Artwork $artwork): void
    {
        (new AnalyzeAndIndexArtworkJob($artwork->id))->handle(app(AiServiceClient::class));
    }

    private function assertDenseSucceeded(Artwork $artwork): void
    {
        $this->assertTrue($artwork->aiMetadata()->firstOrFail()->isIndexed());
        $this->assertSame('success', AiProcessingRun::sole()->status);
    }

    private function indexedArtwork(): array
    {
        $artwork = Artwork::factory()->create();
        $artwork->aiMetadata()->create([
            'status' => ArtworkAiMetadata::STATUS_INDEXED,
            'content_hash' => app(ArtworkIndexingService::class)->contentHash($artwork),
            'attempts' => 1,
        ]);

        return [$artwork, $this->job($artwork)];
    }

    private function job(Artwork $artwork): SyncArtworkColbertJob
    {
        $metadata = $artwork->aiMetadata()->firstOrFail();

        return new SyncArtworkColbertJob($artwork->id, $metadata->content_hash, self::TEXT, $metadata->id, $metadata->attempts);
    }

    private function remote(Artwork $artwork, ?string $hash = null, ?string $version = null): array
    {
        return ['artwork_id' => $artwork->id, 'exists' => $hash !== null, 'content_hash' => $hash, 'version' => $version];
    }
}
