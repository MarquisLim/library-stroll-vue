<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Jobs\DeleteArtworkIndexJob;
use App\Jobs\Middleware\ArtworkAiJobLock;
use App\Models\AiProcessingRun;
use App\Models\Artwork;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ArtworkAiJobLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_jobs_share_the_same_cache_key_for_an_artwork(): void
    {
        $index = new AnalyzeAndIndexArtworkJob(42);
        $delete = new DeleteArtworkIndexJob(42);
        $other = new DeleteArtworkIndexJob(43);

        $this->assertSame(ArtworkAiJobLock::key(42), $index->middleware()[0]->getLockKey($index));
        $this->assertSame(ArtworkAiJobLock::key(42), $delete->middleware()[0]->getLockKey($delete));
        $this->assertNotSame(ArtworkAiJobLock::key(42), $other->middleware()[0]->getLockKey($other));
    }

    #[DataProvider('competingJobs')]
    public function test_jobs_for_the_same_artwork_are_released_on_contention(string $holderClass, string $waitingClass): void
    {
        $holder = new $holderClass(42);
        $waiting = (new $waitingClass(42))->withFakeQueueInteractions();

        $holder->middleware()[0]->handle($holder, function () use ($waiting) {
            $waiting->middleware()[0]->handle($waiting, fn () => $this->fail('The waiting job started under an active lock.'));
        });

        $waiting->assertReleased(30);
    }

    public static function competingJobs(): array
    {
        return [
            'index then index' => [AnalyzeAndIndexArtworkJob::class, AnalyzeAndIndexArtworkJob::class],
            'delete then delete' => [DeleteArtworkIndexJob::class, DeleteArtworkIndexJob::class],
            'index then delete' => [AnalyzeAndIndexArtworkJob::class, DeleteArtworkIndexJob::class],
            'delete then index' => [DeleteArtworkIndexJob::class, AnalyzeAndIndexArtworkJob::class],
        ];
    }

    public function test_different_artworks_can_run_at_the_same_time(): void
    {
        $index = new AnalyzeAndIndexArtworkJob(42);
        $delete = (new DeleteArtworkIndexJob(43))->withFakeQueueInteractions();
        $ran = false;

        $index->middleware()[0]->handle($index, function () use ($delete, &$ran) {
            $delete->middleware()[0]->handle($delete, function () use (&$ran) {
                $ran = true;
            });
        });

        $this->assertTrue($ran);
        $delete->assertNotReleased();
    }

    public function test_an_exception_releases_the_shared_lock(): void
    {
        $index = new AnalyzeAndIndexArtworkJob(42);
        $delete = (new DeleteArtworkIndexJob(42))->withFakeQueueInteractions();

        try {
            $index->middleware()[0]->handle($index, fn () => throw new RuntimeException('Index failed.'));
            $this->fail('The indexing error did not propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('Index failed.', $error->getMessage());
        }

        $ran = false;
        $delete->middleware()[0]->handle($delete, function () use (&$ran) {
            $ran = true;
        });
        $this->assertTrue($ran);
        $delete->assertNotReleased();
    }

    public function test_an_abandoned_lock_outlives_the_index_timeout_and_then_expires(): void
    {
        $this->freezeSecond();
        $index = new AnalyzeAndIndexArtworkJob(42);
        $delete = (new DeleteArtworkIndexJob(42))->withFakeQueueInteractions();
        $middleware = $index->middleware()[0];
        $lock = app(Cache::class)->lock($middleware->getLockKey($index), $middleware->expiresAfter);
        $this->assertTrue($lock->get());

        $this->travel(600)->seconds();
        $delete->middleware()[0]->handle($delete, fn () => $this->fail('The lease expired during indexing.'));
        $delete->assertReleased(30);

        $this->travel(60)->seconds();
        $ran = false;
        $delete->middleware()[0]->handle($delete, function () use (&$ran) {
            $ran = true;
        });
        $this->assertTrue($ran);
    }

    public function test_delete_survives_ten_minutes_of_worker_reservations_and_then_runs_once(): void
    {
        $this->freezeSecond();
        config(['ai.service_url' => 'http://ai.test', 'ai.queue' => 'ai']);
        Http::fake(['ai.test/api/v1/artworks/42/index' => Http::response(['status' => 'deleted'])]);
        $index = new AnalyzeAndIndexArtworkJob(42);
        $queue = Queue::connection('database');
        $worker = app('queue.worker')->setCache(app(Cache::class));
        $options = new WorkerOptions(maxTries: 3);
        $queue->push(new DeleteArtworkIndexJob(42), '', 'ai');

        $index->middleware()[0]->handle($index, function () use ($queue, $worker, $options) {
            for ($attempt = 1; $attempt <= 20; $attempt++) {
                $waiting = $queue->pop('ai');
                $this->assertNotNull($waiting);
                $this->assertSame($attempt, $waiting->attempts());
                $worker->process('database', $waiting, $options);
                $this->assertTrue($waiting->isReleased());
                $this->assertFalse($waiting->hasFailed());
                $this->assertNull($queue->pop('ai'));
                $this->travel(30)->seconds();
            }

            Http::assertNothingSent();
            $this->assertDatabaseCount('ai_processing_runs', 0);
        });

        $waiting = $queue->pop('ai');
        $this->assertNotNull($waiting);
        $this->assertSame(21, $waiting->attempts());
        $worker->process('database', $waiting, $options);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
        $this->assertSame(0, $queue->size('ai'));
        $this->assertSame('success', AiProcessingRun::sole()->status);
    }

    #[DataProvider('failingJobs')]
    public function test_worker_still_fails_the_job_on_the_third_execution_exception(string $jobClass): void
    {
        $this->freezeSecond();
        config(['ai.service_url' => 'http://ai.test', 'ai.queue' => 'ai', 'ai.retry_times' => 1]);
        Http::fake(['ai.test/*' => Http::response(['detail' => 'Temporarily unavailable'], 503)]);
        $artwork = Artwork::factory()->create();
        $queue = Queue::connection('database');
        $worker = app('queue.worker')->setCache(app(Cache::class));
        $options = new WorkerOptions(maxTries: 3);
        $queue->push(new $jobClass($artwork->id), '', 'ai');

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $reserved = $queue->pop('ai');
            $this->assertNotNull($reserved);
            try {
                $worker->process('database', $reserved, $options);
                $this->fail('The job did not propagate the HTTP error.');
            } catch (RequestException $error) {
                $this->assertSame(503, $error->response->status());
            }
            $this->assertSame($attempt === 3, $reserved->hasFailed());
            $this->travel(120)->seconds();
        }

        Http::assertSentCount(3);
        $this->assertSame(0, $queue->size('ai'));
        $this->assertDatabaseCount('ai_processing_runs', 3);
    }

    public static function failingJobs(): array
    {
        return ['index' => [AnalyzeAndIndexArtworkJob::class], 'delete' => [DeleteArtworkIndexJob::class]];
    }
}
