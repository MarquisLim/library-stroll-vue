<?php

namespace App\Console\Commands;

use App\Jobs\DeleteArtworkIndexJob;
use App\Models\AiProcessingRun;
use App\Services\AiService\ArtworkIndexVisibility;
use Illuminate\Console\Command;

class AiDeleteIndexCommand extends Command
{
    protected $signature = 'ai:delete-index {id} {--intent= : Retry a pending or failed publication cleanup by its journal ID}';
    protected $description = 'Dispatch AI index deletion for a single artwork';

    public function handle(ArtworkIndexVisibility $visibility): int
    {
        if (! config('ai.enabled')) {
            $this->warn('AI service is disabled (AI_SERVICE_ENABLED=false).');
            return self::FAILURE;
        }

        $artworkId = (int) $this->argument('id');

        if (($intentId = $this->option('intent')) !== null) {
            $intent = AiProcessingRun::whereKey((int) $intentId)->where('operation', 'delete_index')
                ->whereIn('status', ['pending', 'failed'])->first();
            if (! ctype_digit((string) $intentId) || ! $intent || ($intent->parameters['deleted_artwork_id'] ?? null) !== $artworkId
                || ($intent->parameters['stage'] ?? null) !== 'publication_cleanup') {
                $this->error('No pending publication cleanup matches this artwork and intent.');

                return self::FAILURE;
            }
            $visibility->enqueueRemoval($artworkId, $intent->id);
        } else {
            DeleteArtworkIndexJob::dispatch($artworkId);
        }

        $this->info("Dispatched index deletion for artwork #{$artworkId}.");

        return self::SUCCESS;
    }
}
