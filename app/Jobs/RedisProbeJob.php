<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RedisProbeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public string $key, public string $token) {}

    public function handle(): void
    {
        Cache::store('redis')->put($this->key, $this->token, 60);
    }
}
