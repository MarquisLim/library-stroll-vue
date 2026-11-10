<?php

namespace App\Services\DemoContent;

use App\Services\ArtworkImport\MuseumSource;

class MuseumAdapter implements ContentSource
{
    public function __construct(private string $source) {}

    public function candidates(string $query, string $type): iterable
    {
        if ($type !== 'image') {
            return;
        }
        foreach (app(MuseumSource::class)->candidates($this->source, $query, 1, config("demo_content.sources.{$this->source}.max_pages")) as $item) {
            yield new ContentItem($item->source, $item->externalId, 'image', $item->sourceUrl, $item->imageUrl, $item->title,
                $item->artist ?: null, 'Public Domain', 'https://creativecommons.org/publicdomain/zero/1.0/', $item->tags,
                creation_date: $item->date ?: null, medium: $item->medium ?: null, original_metadata: $item->metadata());
        }
    }
}
