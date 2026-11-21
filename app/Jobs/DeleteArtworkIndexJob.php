<?php

namespace App\Jobs;

use App\Contracts\AiServiceClient;
use App\Jobs\Middleware\ArtworkAiJobLock;
use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Services\AiService\ArtworkIndexingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class DeleteArtworkIndexJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = ArtworkAiJobLock::MAX_RESERVATIONS;

    public int $maxExceptions = ArtworkAiJobLock::MAX_EXCEPTIONS;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    protected ?int $intentId = null;

    public function __construct(protected int $artworkId, ?int $intentId = null)
    {
        $this->intentId = $intentId;
        $this->onQueue(config('ai.queue', 'ai'));
        $this->afterCommit();
    }

    public function middleware(): array
    {
        return [ArtworkAiJobLock::middleware($this->artworkId)];
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
        $startedAt = now();
        $intent = null;
        if ($this->intentId !== null) {
            $intent = AiProcessingRun::whereKey($this->intentId)->where('operation', 'delete_index')
                ->whereIn('status', ['pending', 'failed'])->first();
            if (! $intent || ($intent->parameters['deleted_artwork_id'] ?? null) !== $this->artworkId) {
                return;
            }
            $artwork = Artwork::withoutGlobalScopes()->find($this->artworkId);
            if (app(ArtworkIndexingService::class)->canIndex($artwork)) {
                $intent->update(['status' => 'skipped', 'finished_at' => now()]);

                return;
            }
        }

        $requestId = $intent?->request_id ?? Str::uuid()->toString();

        try {
            $aiClient->deleteArtworkIndex($this->artworkId);

            $this->finishRun($intent, $requestId, $startedAt, [
                'status' => 'success',
                'error' => null,
            ]);

            Log::info('Artwork index deleted.', [
                'artwork_id' => $this->artworkId,
                'request_id' => $requestId,
            ]);
        } catch (Throwable $e) {
            $this->finishRun($intent, $requestId, $startedAt, [
                'status' => 'failed',
                'error' => Str::limit($e->getMessage(), 500),
            ]);

            throw $e;
        }
    }

    private function finishRun(?AiProcessingRun $intent, string $requestId, \DateTimeInterface $startedAt, array $fields): void
    {
        $fields += ['latency_ms' => (int) abs(now()->diffInMilliseconds($startedAt)), 'finished_at' => now()];
        if ($intent) {
            $intent->update($fields);

            return;
        }

        DB::transaction(function () use ($requestId, $startedAt, $fields) {
            $artwork = Artwork::withoutGlobalScopes()->lockForUpdate()->find($this->artworkId);
            AiProcessingRun::create($fields + [
                'artwork_id' => $artwork?->id,
                'parameters' => ['deleted_artwork_id' => $this->artworkId],
                'operation' => 'delete_index',
                'request_id' => $requestId,
                'started_at' => $startedAt,
            ]);
        });
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
