<?php

namespace App\Services\ArtworkImport;

final readonly class SourceArtwork
{
    public function __construct(
        public string $source,
        public string $externalId,
        public string $title,
        public string $artist,
        public string $date,
        public string $medium,
        public string $sourceUrl,
        public string $imageUrl,
        public array $tags,
        public string $license = 'CC0 / Public Domain',
    ) {}

    public function description(): string
    {
        return implode("\n", array_filter([
            $this->artist !== '' ? 'Художник: '.$this->artist : null,
            $this->date !== '' ? 'Дата создания: '.$this->date : null,
            $this->medium !== '' ? 'Материал и техника: '.$this->medium : null,
            'Источник: '.$this->sourceUrl,
            'Лицензия изображения: '.$this->license,
        ]));
    }

    public function metadata(): array
    {
        return [
            'title' => $this->title,
            'artist' => $this->artist,
            'date' => $this->date,
            'medium' => $this->medium,
            'tags' => $this->tags,
            'tags_origin' => 'museum_metadata',
        ];
    }
}
