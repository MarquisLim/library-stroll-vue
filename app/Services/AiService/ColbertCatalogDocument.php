<?php

namespace App\Services\AiService;

use App\Models\Artwork;
use App\Services\Search\AssistantSearchDiagnostics;

class ColbertCatalogDocument
{
    public function __construct(private ArtworkIndexingService $indexing) {}

    /** The caller must load the artwork through the assistant's visibility scope, with metadata and sorted tags. */
    public function make(Artwork $work): ?array
    {
        $meta = $work->aiMetadata;
        $uuid = $work->getFirstMedia('artworks')?->uuid;
        $revision = $this->indexing->contentHash($work, sortedTagNames: $work->tags->pluck('name')->all());
        if (! $meta?->isIndexed() || ! $meta->vision_model || ! $uuid || $meta->source_media_uuid !== $uuid
            || $meta->content_hash !== $revision) {
            return null;
        }

        $parts = [$work->title, $work->description, $meta->summary];
        foreach ([$meta->objects, $meta->themes, $meta->styles, $meta->colors, $meta->tags_ru, $meta->tags_en, $work->tags->pluck('name')->all()] as $values) {
            $parts[] = implode(', ', array_filter(array_slice($values ?? [], 0, 16), 'is_string'));
        }
        $text = implode("\n", array_filter(array_map(
            fn ($part) => trim(AssistantSearchDiagnostics::publicText($part, 2048) ?? ''),
            $parts,
        )));
        if ($text === '') {
            return null;
        }
        $digest = hash('sha256', $text);

        return ['id' => $work->id, 'revision' => $revision, 'source_uuid' => $uuid, 'text' => $text,
            'text_sha256' => $digest, 'document_key' => hash('sha256', $revision.':'.$digest)];
    }
}
