<?php

namespace App\Console\Commands;

use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Services\AiService\ArtworkIndexingService;
use Illuminate\Console\Command;

class AiReconcileCommand extends Command
{
    protected $signature = 'ai:reconcile
        {--stale-hours=24 : Reprocess metadata older than N hours}
        {--max-pending-hours=2 : Reprocess stuck pending items older than N hours}
        {--limit=50 : Maximum items to process per run}';

    protected $description = 'Reconcile stale, failed, and stuck-pending AI metadata';

    public function handle(): int
    {
        if (! config('ai.enabled')) {
            $this->warn('AI service is disabled.');

            return self::SUCCESS;
        }

        $staleHours = (int) $this->option('stale-hours');
        $pendingHours = (int) $this->option('max-pending-hours');
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 1000 || $staleHours < 1 || $pendingHours < 1) {
            $this->error('limit must be 1..1000 and hour intervals must be positive.');

            return self::INVALID;
        }
        $dispatched = 0;

        $staleItems = ArtworkAiMetadata::where(function ($query) use ($staleHours, $pendingHours) {
            $query->where('status', ArtworkAiMetadata::STATUS_STALE)
                ->orWhere(fn ($q) => $q->where('status', ArtworkAiMetadata::STATUS_PROCESSING)
                    ->where('updated_at', '<', now()->subHours($pendingHours)))
                ->orWhere(fn ($q) => $q->where('status', ArtworkAiMetadata::STATUS_INDEXED)
                    ->where('indexed_at', '<', now()->subHours($staleHours)));
        })
            ->limit($limit)
            ->get();

        foreach ($staleItems as $meta) {
            $this->dispatchForMeta($meta);
            $dispatched++;
        }

        $remaining = $limit - $dispatched;
        if ($remaining > 0) {
            $failedItems = ArtworkAiMetadata::where('status', ArtworkAiMetadata::STATUS_FAILED)
                ->where('attempts', '<', 5)
                ->limit($remaining)
                ->get();

            foreach ($failedItems as $meta) {
                $this->dispatchForMeta($meta);
                $dispatched++;
            }
        }

        $remaining = $limit - $dispatched;
        if ($remaining > 0) {
            $stuckPending = ArtworkAiMetadata::where('status', ArtworkAiMetadata::STATUS_PENDING)
                ->where('updated_at', '<', now()->subHours($pendingHours))
                ->limit($remaining)
                ->get();

            foreach ($stuckPending as $meta) {
                $this->dispatchForMeta($meta);
                $dispatched++;
            }
        }

        $remaining = $limit - $dispatched;
        if ($remaining > 0) {
            $missing = Artwork::where('is_published', true)
                ->whereDoesntHave('aiMetadata')
                ->limit($remaining)
                ->get();

            foreach ($missing as $artwork) {
                app(ArtworkIndexingService::class)->dispatch($artwork, force: true);
                $dispatched++;
            }
        }

        $this->info("Reconciliation complete: {$dispatched} items dispatched.");

        return self::SUCCESS;
    }

    private function dispatchForMeta(ArtworkAiMetadata $meta): void
    {
        $artwork = Artwork::withoutGlobalScopes()->find($meta->artwork_id);

        if (! $artwork || ! $artwork->is_published) {
            $meta->delete();

            return;
        }

        app(ArtworkIndexingService::class)->dispatch($artwork, force: true);
    }
}
