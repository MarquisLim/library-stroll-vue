<?php

namespace App\Console\Commands;

use App\Models\Artwork;
use App\Services\AiService\ArtworkIndexingService;
use Illuminate\Console\Command;

class AiIndexArtworkCommand extends Command
{
    protected $signature = 'ai:index-artwork {id}';

    protected $description = 'Dispatch AI analysis and indexing for a single artwork';

    public function handle(): int
    {
        if (! config('ai.enabled')) {
            $this->warn('AI service is disabled (AI_SERVICE_ENABLED=false).');

            return self::FAILURE;
        }

        $artwork = Artwork::withoutGlobalScopes()->find($this->argument('id'));

        if (! $artwork) {
            $this->error('Artwork not found.');

            return self::FAILURE;
        }

        if (! $artwork->is_published) {
            $this->error('Publish the artwork before indexing.');

            return self::FAILURE;
        }

        app(ArtworkIndexingService::class)->dispatch($artwork, force: true);

        $this->info("Dispatched AI analysis for artwork #{$artwork->id}.");

        return self::SUCCESS;
    }
}
