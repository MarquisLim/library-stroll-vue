<?php

namespace App\Console\Commands;

use App\Jobs\DeleteArtworkIndexJob;
use Illuminate\Console\Command;

class AiDeleteIndexCommand extends Command
{
    protected $signature = 'ai:delete-index {id}';
    protected $description = 'Dispatch AI index deletion for a single artwork';

    public function handle(): int
    {
        if (! config('ai.enabled')) {
            $this->warn('AI service is disabled (AI_SERVICE_ENABLED=false).');
            return self::FAILURE;
        }

        $artworkId = (int) $this->argument('id');

        DeleteArtworkIndexJob::dispatch($artworkId);

        $this->info("Dispatched index deletion for artwork #{$artworkId}.");

        return self::SUCCESS;
    }
}
