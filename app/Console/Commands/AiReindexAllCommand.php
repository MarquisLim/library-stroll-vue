<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use Illuminate\Console\Command;

class AiReindexAllCommand extends Command
{
    protected $signature = 'ai:reindex-all {--chunk=50 : Chunk size for batch processing}';
    protected $description = 'Dispatch AI re-analysis and re-indexing for all published artworks';

    public function handle(): int
    {
        if (! config('ai.enabled')) {
            $this->warn('AI service is disabled (AI_SERVICE_ENABLED=false).');
            return self::FAILURE;
        }

        $count = Artwork::where('is_published', true)->count();

        if ($count === 0) {
            $this->info('No published artworks to reindex.');
            return self::SUCCESS;
        }

        if (! $this->confirm("This will reindex {$count} published artworks. Continue?")) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        $chunkSize = (int) $this->option('chunk');
        $dispatched = 0;

        Artwork::where('is_published', true)
            ->select(['id', 'title', 'description'])
            ->with('media')
            ->chunkById($chunkSize, function ($artworks) use (&$dispatched) {
                foreach ($artworks as $artwork) {
                    $media = $artwork->getFirstMedia('artworks');
                    $contentHash = md5(implode('|', [
                        $artwork->title, $artwork->description, $media?->uuid,
                    ]));

                    ArtworkAiMetadata::updateOrCreate(
                        ['artwork_id' => $artwork->id],
                        ['status' => ArtworkAiMetadata::STATUS_PENDING],
                    );

                    AnalyzeAndIndexArtworkJob::dispatch(
                        $artwork->id, $contentHash, $media?->uuid,
                    );

                    $dispatched++;
                }

                $this->info("Dispatched {$dispatched} jobs...");
            });

        $this->info("Total: {$dispatched} artworks queued for reindexing.");

        return self::SUCCESS;
    }
}
