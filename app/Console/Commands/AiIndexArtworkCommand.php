<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
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
            $this->warn('Artwork is not published. Indexing anyway as manual request.');
        }

        $media = $artwork->getFirstMedia('artworks');
        $contentHash = md5(implode('|', [
            $artwork->title, $artwork->description, $media?->uuid,
        ]));

        ArtworkAiMetadata::updateOrCreate(
            ['artwork_id' => $artwork->id],
            ['status' => ArtworkAiMetadata::STATUS_PENDING],
        );

        AnalyzeAndIndexArtworkJob::dispatch($artwork->id, $contentHash, $media?->uuid);

        $this->info("Dispatched AI analysis for artwork #{$artwork->id}.");

        return self::SUCCESS;
    }
}
