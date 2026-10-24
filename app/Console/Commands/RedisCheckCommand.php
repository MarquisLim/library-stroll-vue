<?php

namespace App\Console\Commands;

use App\Jobs\RedisProbeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RedisCheckCommand extends Command
{
    protected $signature = 'redis:check {--queue : Also execute a real job in an isolated Redis queue}';

    protected $description = 'Check Redis connections, cache, locks, sessions and optionally a real queue worker';

    public function handle(): int
    {
        $token = (string) Str::uuid();
        $key = 'redis-probe:'.$token;
        $queue = 'redis-probe-'.$token;
        $sessionId = Str::random(40);
        $cache = Cache::store('redis');
        $handler = null;
        $lock = null;
        $queued = false;

        try {
            foreach (['default', 'cache', 'sessions', 'locks'] as $connection) {
                $reply = Redis::connection($connection)->ping();
                $this->ensure(in_array($reply, [true, 'PONG', '+PONG'], true), "PING failed: {$connection}");
                $this->info("Redis {$connection}: OK");
            }

            $this->ensure(config('cache.default') === 'redis', 'CACHE_STORE must be redis');
            $this->ensure(config('queue.default') === 'redis', 'QUEUE_CONNECTION must be redis');
            $this->ensure(config('session.driver') === 'redis', 'SESSION_DRIVER must be redis');
            $this->ensure((int) config('queue.connections.redis.retry_after') > 600, 'Redis retry_after must exceed the AI job timeout (600s)');
            $this->ensure((float) config('database.redis.default.read_timeout') > (int) config('queue.connections.redis.block_for'), 'Redis read_timeout must exceed block_for');

            $cache->put($key, $token, 60);
            $this->ensure($cache->get($key) === $token, 'Cache round trip failed');
            $ttl = $cache->getStore()->connection()->ttl($cache->getStore()->getPrefix().$key);
            $this->ensure($ttl > 0 && $ttl <= 60, 'Cache TTL failed');
            $cache->forget($key);
            $this->info('Cache write/read/TTL/delete: OK');

            $lock = $cache->lock($key.':lock', 30);
            $this->ensure($lock->get(), 'Lock acquisition failed');
            $this->ensure(! $cache->lock($key.':lock', 30)->get(), 'Lock mutual exclusion failed');
            $this->ensure($lock->release(), 'Lock release failed');
            $this->info('Atomic locks: OK');

            $handler = app('session')->driver()->getHandler();
            $this->ensure($handler->write($sessionId, $token), 'Session write failed');
            $this->ensure($handler->read($sessionId) === $token, 'Session read failed');
            $handler->destroy($sessionId);
            $this->info('Redis session write/read/delete: OK');

            if ($this->option('queue')) {
                $queued = true;
                Queue::connection('redis')->push(new RedisProbeJob($key, $token), '', $queue);
                $this->ensure(Queue::connection('redis')->size($queue) === 1, 'Job was not enqueued');
                $exit = $this->call('queue:work', [
                    'connection' => 'redis', '--queue' => $queue,
                    '--once' => true, '--tries' => 1, '--timeout' => 15,
                ]);
                $this->ensure($exit === 0 && $cache->get($key) === $token, 'Queue worker did not complete the job');
                $this->ensure(Queue::connection('redis')->size($queue) === 0, 'Queue was not acknowledged');
                $this->info('Redis enqueue/worker/acknowledgement: OK');
            }

            $this->info('Redis checks passed. No application queues or existing keys were cleared.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Redis check failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            try {
                $lock?->release();
                $handler?->destroy($sessionId);
                $cache->forget($key);
                if ($queued) {
                    Queue::connection('redis')->clear($queue);
                }
            } catch (Throwable $e) {
                $this->warn('Probe cleanup failed (keys have TTL): '.$e->getMessage());
            }
        }
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
