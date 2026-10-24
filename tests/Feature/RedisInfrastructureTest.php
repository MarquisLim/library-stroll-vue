<?php

namespace Tests\Feature;

use App\Jobs\RedisProbeJob;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RedisInfrastructureTest extends TestCase
{
    public function test_queue_reservation_outlives_ai_timeout_and_blocking_read(): void
    {
        $this->assertGreaterThan(600, config('queue.connections.redis.retry_after'));
        $this->assertGreaterThan(config('queue.connections.redis.block_for'), config('database.redis.default.read_timeout'));
        $this->assertTrue(config('queue.connections.redis.after_commit'));
        $this->assertSame('locks', config('cache.stores.redis.lock_connection'));
        $this->assertCount(4, array_unique(array_map(
            fn ($name) => config("database.redis.{$name}.database"),
            ['default', 'cache', 'sessions', 'locks'],
        )));
    }

    public function test_probe_job_writes_only_its_own_key(): void
    {
        config(['cache.stores.redis' => ['driver' => 'array']]);
        (new RedisProbeJob('probe-test', 'token'))->handle();
        $this->assertSame('token', Cache::store('redis')->get('probe-test'));
        Cache::store('redis')->forget('probe-test');
    }

    public function test_real_redis_infrastructure_and_worker(): void
    {
        if (getenv('RUN_REDIS_SMOKE') !== '1') {
            $this->markTestSkipped('Set RUN_REDIS_SMOKE=1 to test a running Redis server.');
        }

        config([
            'cache.default' => 'redis', 'queue.default' => 'redis',
            'session.driver' => 'redis', 'session.connection' => 'sessions',
        ]);
        $this->artisan('redis:check', ['--queue' => true])
            ->expectsOutputToContain('Redis enqueue/worker/acknowledgement: OK')
            ->assertSuccessful();
    }
}
