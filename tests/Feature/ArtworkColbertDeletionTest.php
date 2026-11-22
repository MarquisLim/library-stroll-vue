<?php

namespace Tests\Feature;

use App\Actions\Artwork\DeleteArtwork;
use App\Actions\Artwork\UpdateArtwork;
use App\Jobs\DeleteArtworkColbertJob;
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

class ArtworkColbertDeletionTest extends TestCase
{
    use RefreshDatabase;

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

    #[DataProvider('restrictions')]
    public function test_existing_visibility_path_queues_colbert_only_after_dense_commit(string $restriction): void
    {
        Queue::fake();
        $artwork = $this->indexedArtwork();
        $id = $artwork->id;
        [$hash, $version] = [str_repeat('a', 64), str_repeat('b', 32)];
        Http::fake([
            "ai.test/api/v1/artworks/$id/index" => Http::response(['status' => 'deleted']),
            "colbert.test/api/v1/documents/$id*" => function ($request) use ($id, $hash, $version) {
                if ($request->method() === 'GET') {
                    return Http::response($this->remote($id, $hash, $version));
                }
                $this->assertSame('DELETE', $request->method());
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
                $this->assertSame(['expected_previous_hash' => $hash, 'expected_previous_version' => $version], $query);

                return Http::response($this->remote($id, null, str_repeat('c', 32)) + ['status' => 'deleted']);
            },
        ]);
        match ($restriction) {
            'deleted' => app(DeleteArtwork::class)->handle($artwork),
            'private' => app(UpdateArtwork::class)->handle($artwork, $artwork->user, ['is_private' => true]),
            'unpublished' => $artwork->update(['is_published' => false]),
            'blocked' => $artwork->block(),
            'author' => $artwork->user->block(),
            'disabled' => $artwork->forceFill(['ai_indexing_enabled' => false])->save(),
        };

        $dense = Queue::pushed(DeleteArtworkIndexJob::class)->sole();
        DB::transaction(function () use ($dense) {
            $this->runJob($dense);
            Queue::assertNotPushed(DeleteArtworkColbertJob::class);
            Http::assertSentCount(1);
        });
        $this->assertSame('success', AiProcessingRun::sole()->status);
        Queue::assertPushedOn('ai', DeleteArtworkColbertJob::class);
        $this->runJob(Queue::pushed(DeleteArtworkColbertJob::class)->sole());
        Http::assertSentCount(3);
        $this->assertSame('success', AiProcessingRun::sole()->status);
    }

    public static function restrictions(): array
    {
        return [['deleted'], ['private'], ['unpublished'], ['blocked'], ['author'], ['disabled']];
    }

    public function test_rolled_back_dense_result_does_not_enqueue_colbert(): void
    {
        Queue::fake();
        Http::fake(['ai.test/*' => Http::response(['status' => 'deleted'])]);
        DB::beginTransaction();
        try {
            $this->runJob(new DeleteArtworkIndexJob(42));
            Queue::assertNothingPushed();
        } finally {
            DB::rollBack();
        }
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('ai_processing_runs', 0);
        Http::assertSentCount(1);
    }

    public function test_dense_failure_does_not_enqueue_colbert(): void
    {
        Queue::fake();
        Http::fake(['ai.test/*' => Http::response(status: 503)]);
        try {
            $this->runJob(new DeleteArtworkIndexJob(42));
            $this->fail('Expected a Dense deletion failure.');
        } catch (RequestException $error) {
            $this->assertSame(503, $error->response->status());
        }
        Queue::assertNothingPushed();
        $this->assertSame('failed', AiProcessingRun::sole()->status);
    }

    public function test_enqueue_failure_is_reported_without_failing_dense_cleanup(): void
    {
        Log::spy();
        Queue::shouldReceive('connection')->once()->andThrow(new RuntimeException('Sensitive queue details.'));
        Http::fake(['ai.test/*' => Http::response(['status' => 'deleted'])]);
        $this->runJob(new DeleteArtworkIndexJob(42));
        $run = AiProcessingRun::sole();
        $this->assertSame('success', $run->status);
        Log::shouldHaveReceived('error')->once()->with('Could not enqueue ColBERT document deletion after Dense cleanup.', [
            'artwork_id' => 42, 'request_id' => $run->request_id, 'exception_type' => RuntimeException::class,
        ]);
    }

    #[DataProvider('disabledFlags')]
    public function test_each_flag_can_disable_automatic_deletion(string $key): void
    {
        config([$key => false]);
        Queue::fake();
        Http::fake(['ai.test/*' => Http::response(['status' => 'deleted'])]);
        $this->runJob(new DeleteArtworkIndexJob(42));
        Queue::assertNothingPushed();
        $this->runJob(new DeleteArtworkColbertJob(42, 'operation-id'));
        Http::assertSentCount(1);
        $this->assertSame('success', AiProcessingRun::sole()->status);
    }

    public static function disabledFlags(): array
    {
        return [['ai.colbert_sync.auto_enabled'], ['ai.colbert_sync.enabled']];
    }

    public function test_repeat_delivery_does_not_delete_an_absent_document(): void
    {
        $id = 42;
        Http::fake(['colbert.test/*' => Http::response($this->remote($id, null, str_repeat('b', 32)))]);
        $job = new DeleteArtworkColbertJob($id, 'operation-id');
        $this->runJob($job);
        $this->runJob($job);
        $this->assertSame(['GET', 'GET'], $this->methods());
    }

    public function test_republication_cancels_dense_or_colbert_cleanup_at_the_time_it_runs(): void
    {
        Queue::fake();
        Http::fake(['ai.test/*' => Http::response(['status' => 'deleted'])]);
        $artwork = $this->indexedArtwork();
        $artwork->update(['is_published' => false]);
        $first = Queue::pushed(DeleteArtworkIndexJob::class)->sole();
        $artwork->update(['is_published' => true]);
        $this->runJob($first);
        $this->assertSame('skipped', AiProcessingRun::sole()->status);
        Queue::assertNotPushed(DeleteArtworkColbertJob::class);
        Http::assertNothingSent();

        $artwork->update(['is_published' => false]);
        $this->runJob(Queue::pushed(DeleteArtworkIndexJob::class)->last());
        $delete = Queue::pushed(DeleteArtworkColbertJob::class)->sole();
        $artwork->update(['is_published' => true]);
        $this->runJob($delete);
        $this->assertSame(['DELETE'], $this->methods());
    }

    public function test_publication_restored_during_get_prevents_delete(): void
    {
        $artwork = Artwork::factory()->draft()->create();
        Http::fake(['colbert.test/*' => function () use ($artwork) {
            Artwork::whereKey($artwork->id)->update(['is_published' => true]);

            return Http::response($this->remote($artwork->id, str_repeat('a', 64), str_repeat('b', 32)));
        }]);

        $this->runJob(new DeleteArtworkColbertJob($artwork->id, 'operation-id'));
        $this->assertSame(['GET'], $this->methods());
    }

    #[DataProvider('conflictOutcomes')]
    public function test_http_conflict_rereads_both_sides_without_repeating_delete(string $outcome, bool $fails): void
    {
        $artwork = Artwork::factory()->draft()->create();
        $id = $artwork->id;
        $response = match ($outcome) {
            'still present' => Http::response($this->remote($id, str_repeat('a', 64), str_repeat('c', 32))),
            'reread unavailable' => Http::response(status: 503),
            default => Http::response($this->remote($id, null, str_repeat('c', 32))),
        };
        $job = (new DeleteArtworkColbertJob($id, 'operation-id'))->withFakeQueueInteractions();
        Http::fake(['colbert.test/*' => function ($request) use ($artwork, $id, $response, $outcome) {
            if ($request->method() === 'DELETE') {
                return Http::response(status: 409);
            }
            if (count(Http::recorded()) === 0) {
                return Http::response($this->remote($id, str_repeat('a', 64), str_repeat('b', 32)));
            }
            if ($outcome === 'republished') {
                Artwork::whereKey($artwork->id)->update(['is_published' => true]);

                return Http::response($this->remote($id, str_repeat('d', 64), str_repeat('e', 32)));
            }

            return $response;
        }]);
        $this->runJob($job);
        $this->assertSame(['GET', 'DELETE', 'GET'], $this->methods());
        if ($fails) {
            $job->assertFailedWith(new ColbertDocumentException('ColBERT document version conflict.', 409));
        } else {
            $job->assertNotFailed();
        }
    }

    public static function conflictOutcomes(): array
    {
        return [['still present', true], ['reread unavailable', true], ['removed', false], ['republished', false]];
    }

    public function test_deleted_response_requires_a_new_tombstone_version(): void
    {
        $id = 42;
        $version = str_repeat('b', 32);
        Http::fake(['colbert.test/*' => Http::sequence()
            ->push($this->remote($id, str_repeat('a', 64), $version))
            ->push($this->remote($id, null, $version) + ['status' => 'deleted'])]);
        $job = (new DeleteArtworkColbertJob($id, 'operation-id'))->withFakeQueueInteractions();
        $this->runJob($job);
        $job->assertFailedWith(new ColbertDocumentException('ColBERT did not return a new document deletion version.', 502));
    }

    #[DataProvider('transientErrors')]
    public function test_worker_retries_transient_failures_and_logs_terminal_failure_without_reopening_dense(int $status): void
    {
        $this->freezeSecond();
        Log::spy();
        config(['queue.default' => 'database']);
        $id = 42;
        $deleteAttempts = 0;
        Http::fake([
            'ai.test/*' => Http::response(['status' => 'deleted']),
            'colbert.test/*' => function ($request) use ($id, $status, &$deleteAttempts) {
                if ($request->method() === 'GET') {
                    return Http::response($this->remote($id, str_repeat('a', 64), str_repeat('b', 32)));
                }
                $deleteAttempts++;
                if ($status === 0) {
                    throw new ConnectionException('Sensitive transport details.');
                }

                return Http::response(['detail' => 'Sensitive response.'], $status);
            },
        ]);

        $this->runJob(new DeleteArtworkIndexJob($id));
        $run = AiProcessingRun::sole();
        $this->assertSame('success', $run->status);
        $queue = Queue::connection('database');
        $worker = app('queue.worker')->setCache(app(Cache::class));
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $job = $queue->pop('ai');
            $this->assertNotNull($job);
            try {
                $worker->process('database', $job, new WorkerOptions);
                $this->fail('Expected a ColBERT transport failure.');
            } catch (ColbertDocumentException $error) {
                $this->assertSame($status, $error->getCode());
                $this->assertStringNotContainsString('Sensitive', $error->getMessage());
            }
            $this->assertSame($attempt === 3, $job->hasFailed());
            $this->assertNull($queue->pop('ai'));
            if ($attempt < 3) {
                $this->travel($attempt === 1 ? 15 : 60)->seconds();
            }
        }

        $this->assertSame(3, $deleteAttempts);
        $this->assertSame(0, $queue->size('ai'));
        $this->assertSame($run->getRawOriginal(), AiProcessingRun::sole()->getRawOriginal());
        Log::shouldHaveReceived('error')->once()->with('ColBERT document deletion failed.', [
            'artwork_id' => $id, 'request_id' => $run->request_id,
            'exception_type' => ColbertDocumentException::class, 'http_status' => $status ?: null, 'attempts' => 3,
        ]);
    }

    public static function transientErrors(): array
    {
        return ['network' => [0], 'unavailable' => [503]];
    }

    public function test_delete_and_sync_wait_for_the_same_artwork_lock(): void
    {
        $sync = (new SyncArtworkColbertJob(42, 'revision', 'text', 1, 1))->withFakeQueueInteractions();
        $delete = (new DeleteArtworkColbertJob(42, 'operation-id'))->withFakeQueueInteractions();
        foreach ([[$sync, $delete], [$delete, $sync]] as [$holder, $waiting]) {
            $holder->middleware()[0]->handle($holder, function () use ($waiting) {
                $waiting->middleware()[0]->handle($waiting, fn () => $this->fail('Job started under an active lock.'));
            });
            $waiting->assertReleased(30);
        }
    }

    private function indexedArtwork(): Artwork
    {
        $artwork = Artwork::factory()->create();
        $artwork->aiMetadata()->create([
            'status' => ArtworkAiMetadata::STATUS_INDEXED,
            'content_hash' => app(ArtworkIndexingService::class)->contentHash($artwork),
            'attempts' => 1,
        ]);

        return $artwork;
    }

    private function runJob(DeleteArtworkIndexJob|DeleteArtworkColbertJob $job): void
    {
        $job->middleware()[0]->handle($job, fn () => app()->call([$job, 'handle']));
    }

    private function methods(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0]->method())->all();
    }

    private function remote(int $id, ?string $hash, ?string $version): array
    {
        return ['artwork_id' => $id, 'exists' => $hash !== null, 'content_hash' => $hash, 'version' => $version];
    }
}
