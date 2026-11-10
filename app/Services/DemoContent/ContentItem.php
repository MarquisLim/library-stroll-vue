<?php

namespace App\Services\DemoContent;

final readonly class ContentItem
{
    public function __construct(public string $source, public string $external_id, public string $media_type,
        public string $source_url, public string $media_url, public string $original_title,
        public ?string $creator_name, public string $license, public ?string $license_url,
        public array $original_tags = [], public array $categories = [], public ?string $creator_url = null,
        public ?string $original_description = null, public ?string $creation_date = null, public ?string $medium = null,
        public ?string $attribution_text = null, public ?string $language = null, public array $original_metadata = []) {}

    public function description(): string
    {
        return implode("\n", array_filter([$this->original_title, $this->creator_name ? 'Автор оригинала: '.$this->creator_name : null,
            $this->creation_date ? 'Дата: '.$this->creation_date : null, $this->medium ? 'Техника: '.$this->medium : null,
            $this->original_tags ? 'Предметы и классификация: '.implode(', ', array_slice($this->original_tags, 0, 12)) : null,
            'Источник: '.$this->source_url, 'Лицензия: '.$this->license, $this->attribution_text]));
    }

    public static function text(mixed $value): string
    {
        return trim(html_entity_decode(strip_tags(is_scalar($value) ? (string) $value : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
