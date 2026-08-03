<?php

namespace App\Jobs;

use App\Contracts\AiServiceClient;
use App\Models\AiProcessingRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DeleteArtworkIndexJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public bool $failOnTimeout = true;

    public function __construct(protected int $artworkId)
    {
        $this->onQueue(config('ai.queue', 'ai'));
        $this->afterCommit();
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(AiServiceClient $aiClient): void
    {
        $requestId = Str::uuid()->toString();
        $startedAt = now();

        try {
            $aiClient->deleteArtworkIndex($this->artworkId);

            AiProcessingRun::create([
                'artwork_id' => $this->artworkId,
                'operation' => 'delete_index',
                'status' => 'success',
                'request_id' => $requestId,
                'latency_ms' => (int) now()->diffInMilliseconds($startedAt),
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            Log::info('Artwork index deleted.', [
                'artwork_id' => $this->artworkId,
                'request_id' => $requestId,
            ]);
        } catch (Throwable $e) {
            AiProcessingRun::create([
                'artwork_id' => $this->artworkId,
                'operation' => 'delete_index',
                'status' => 'failed',
                'request_id' => $requestId,
                'latency_ms' => (int) now()->diffInMilliseconds($startedAt),
                'error' => Str::limit($e->getMessage(), 500),
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('DeleteArtworkIndexJob failed permanently.', [
            'artwork_id' => $this->artworkId,
            'attempts' => $this->attempts(),
            'error' => $exception?->getMessage(),
        ]);
    }
}
