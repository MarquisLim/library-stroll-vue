<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
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
        $dispatched = 0;

        $staleItems = ArtworkAiMetadata::where('status', ArtworkAiMetadata::STATUS_STALE)
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
                $media = $artwork->getFirstMedia('artworks');
                $contentHash = md5(implode('|', [
                    $artwork->title, $artwork->description, $media?->uuid,
                ]));

                ArtworkAiMetadata::create([
                    'artwork_id' => $artwork->id,
                    'status' => ArtworkAiMetadata::STATUS_PENDING,
                ]);

                AnalyzeAndIndexArtworkJob::dispatch($artwork->id, $contentHash, $media?->uuid);
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

        $media = $artwork->getFirstMedia('artworks');
        $contentHash = md5(implode('|', [
            $artwork->title, $artwork->description, $media?->uuid,
        ]));

        $meta->update(['status' => ArtworkAiMetadata::STATUS_PENDING]);

        AnalyzeAndIndexArtworkJob::dispatch($artwork->id, $contentHash, $media?->uuid);
    }
}
