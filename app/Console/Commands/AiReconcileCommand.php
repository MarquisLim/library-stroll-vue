<?php

namespace App\Console\Commands;

use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Services\AiService\ArtworkIndexingService;
use Illuminate\Console\Command;
use Throwable;

class AiReconcileCommand extends Command
{
    protected $signature = 'ai:reconcile
        {--stale-hours= : Deprecated. Accepted for compatibility and ignored.}
        {--max-pending-hours=2 : Reprocess stuck pending items older than N hours}
        {--limit=50 : Maximum items to process per run}';

    protected $description = 'Reconcile stale, failed, and stuck-pending AI metadata';

    public function handle(ArtworkIndexingService $indexing): int
    {
        if (! config('ai.enabled')) {
            $this->warn('AI service is disabled.');

            return self::SUCCESS;
        }

        $pendingHours = (int) $this->option('max-pending-hours');
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 1000 || $pendingHours < 1) {
            $this->error('limit must be 1..1000 and max-pending-hours must be positive.');

            return self::INVALID;
        }
        $dispatched = 0;
        $examined = 0;
        $errors = 0;
        $dispatch = function (int $id, ?ArtworkAiMetadata $observed = null) use ($indexing, &$dispatched, &$examined, &$errors): void {
            $examined++;
            try {
                if ($indexing->dispatchForReconciliation($id, $observed)) {
                    $dispatched++;
                }
            } catch (Throwable $error) {
                $errors++;
                report($error);
                $this->warn("Queue submission failed for artwork #{$id}.");
            }
        };

        $eligibleArtwork = fn ($query) => $query->publiclyVisible()->where('ai_indexing_enabled', true);
        $staleItems = ArtworkAiMetadata::whereHas('artwork', $eligibleArtwork)->where(function ($query) use ($pendingHours) {
            $query->where('status', ArtworkAiMetadata::STATUS_STALE)
                ->orWhere(fn ($q) => $q->where('status', ArtworkAiMetadata::STATUS_PROCESSING)
                    ->where('updated_at', '<', now()->subHours($pendingHours)));
        })
            ->limit($limit)
            ->get();

        foreach ($staleItems as $meta) {
            $dispatch($meta->artwork_id, $meta);
        }

        $remaining = $limit - $examined;
        if ($remaining > 0) {
            $failedItems = ArtworkAiMetadata::where('status', ArtworkAiMetadata::STATUS_FAILED)
                ->whereHas('artwork', $eligibleArtwork)
                ->where('attempts', '<', ArtworkIndexingService::RECONCILIATION_ATTEMPT_LIMIT)
                ->limit($remaining)
                ->get();

            foreach ($failedItems as $meta) {
                $dispatch($meta->artwork_id, $meta);
            }
        }

        $remaining = $limit - $examined;
        if ($remaining > 0) {
            $stuckPending = ArtworkAiMetadata::where('status', ArtworkAiMetadata::STATUS_PENDING)
                ->whereHas('artwork', $eligibleArtwork)
                ->where('updated_at', '<', now()->subHours($pendingHours))
                ->limit($remaining)
                ->get();

            foreach ($stuckPending as $meta) {
                $dispatch($meta->artwork_id, $meta);
            }
        }

        $remaining = $limit - $examined;
        if ($remaining > 0) {
            $missing = Artwork::publiclyVisible()
                ->where('ai_indexing_enabled', true)
                ->whereDoesntHave('aiMetadata')
                ->limit($remaining)
                ->get();

            foreach ($missing as $artwork) {
                $dispatch($artwork->id);
            }
        }

        $skipped = $examined - $dispatched - $errors;
        $this->info("Reconciliation complete: {$dispatched} dispatched, {$skipped} skipped, {$errors} failed.");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
