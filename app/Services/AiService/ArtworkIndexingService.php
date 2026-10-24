<?php

namespace App\Services\AiService;

use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;

class ArtworkIndexingService
{
    public function contentHash(Artwork $artwork): string
    {
        return hash('sha256', json_encode([
            'title' => $artwork->title,
            'description' => $artwork->description,
            'media' => $artwork->getFirstMedia('artworks')?->uuid,
            'tags' => $artwork->tags()->orderBy('name')->pluck('name')->all(),
            'published' => (bool) $artwork->is_published,
            'private' => (bool) $artwork->is_private,
            'blocked' => (bool) $artwork->is_blocked,
            'has_ai' => (bool) $artwork->has_ai,
            'schema' => config('ai.schema_version'),
            'prompt' => config('ai.prompt_version'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function dispatch(Artwork $artwork, bool $force = false): bool
    {
        if (! config('ai.enabled') || ! $artwork->is_published) {
            return false;
        }
        $hash = $this->contentHash($artwork);
        $metadata = $artwork->aiMetadata()->first();
        if (! $force && $metadata?->isIndexed() && $metadata->content_hash === $hash) {
            return false;
        }
        ArtworkAiMetadata::updateOrCreate(['artwork_id' => $artwork->id], [
            'status' => ArtworkAiMetadata::STATUS_PENDING,
        ]);
        AnalyzeAndIndexArtworkJob::dispatch($artwork->id, $hash, $artwork->getFirstMedia('artworks')?->uuid);

        return true;
    }
}
