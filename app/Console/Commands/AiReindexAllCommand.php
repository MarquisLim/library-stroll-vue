<?php

namespace App\Console\Commands;

use App\Models\Artwork;
use App\Services\AiService\ArtworkIndexingService;
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
        if ($chunkSize < 1 || $chunkSize > 1000) {
            $this->error('Chunk size must be between 1 and 1000.');

            return self::INVALID;
        }
        $dispatched = 0;

        Artwork::where('is_published', true)
            ->with('media')
            ->chunkById($chunkSize, function ($artworks) use (&$dispatched) {
                foreach ($artworks as $artwork) {
                    app(ArtworkIndexingService::class)->dispatch($artwork, force: true);

                    $dispatched++;
                }

                $this->info("Dispatched {$dispatched} jobs...");
            });

        $this->info("Total: {$dispatched} artworks queued for reindexing.");

        return self::SUCCESS;
    }
}
