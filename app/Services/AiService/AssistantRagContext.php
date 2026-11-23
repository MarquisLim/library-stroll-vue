<?php

namespace App\Services\AiService;

use App\Models\Artwork;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AssistantRagContext
{
    private const SOURCE_LIMIT = 5;

    // Matches the FastAPI bound on the serialized user message.
    private const INPUT_BYTES = 1400;

    private const FACT_BYTES = 240;

    private const PUBLIC_FACTS = ['f1', 'f2', 'f7'];

    private const AI_FACTS = ['f3', 'f4', 'f5', 'f6'];

    public function __construct(private ArtworkIndexingService $indexing) {}

    public function prepare(string $query, Collection $artworks): array
    {
        $snapshots = [];
        foreach ($artworks->take(self::SOURCE_LIMIT) as $index => $artwork) {
            $snapshots['s'.($index + 1)] = $this->snapshot($artwork);
        }

        return [$this->packFacts($query, $snapshots), $snapshots];
    }

    public function packFacts(string $query, array $snapshots): array
    {
        $facts = [];
        // Offer a fact from each source before adding more detail from one work.
        foreach ([...self::PUBLIC_FACTS, ...self::AI_FACTS] as $ref) {
            foreach ($snapshots as $source => $snapshot) {
                $fact = $snapshot['facts'][$ref] ?? null;
                if ($fact === null) {
                    continue;
                }

                $candidate = [...$facts, ['source_ref' => $source, 'fact_ref' => $ref, ...$fact]];
                if (strlen(json_encode(['query' => $query, 'facts' => $candidate],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR)) <= self::INPUT_BYTES) {
                    $facts = $candidate;
                }
            }
        }

        return $facts;
    }

    public function snapshot(Artwork $artwork): array
    {
        $revision = $this->indexing->contentHash($artwork, useWritePdo: true,
            sortedTagNames: $artwork->relationLoaded('tags') ? $artwork->tags->pluck('name')->all() : null);
        $metadata = $artwork->aiMetadata;
        $mediaUuid = $artwork->getFirstMedia('artworks')?->uuid;
        $aiCurrent = $metadata?->isIndexed() && $metadata->content_hash === $revision
            && is_string($mediaUuid) && $mediaUuid !== '' && $metadata->source_media_uuid === $mediaUuid;

        $values = [
            'f1' => $artwork->title,
            'f2' => $artwork->description,
            'f7' => $artwork->provenance?->creator_name,
        ];
        if ($aiCurrent) {
            $values += [
                'f3' => $metadata->summary,
                'f4' => $this->shortList($metadata->themes),
                'f5' => $this->shortList($metadata->objects),
                'f6' => $this->shortList([...($metadata->tags_ru ?? []), ...($metadata->tags_en ?? [])]),
            ];
        }

        $facts = [];
        foreach ($values as $ref => $value) {
            $value = $this->plainValue($value);
            if ($value !== '') {
                $facts[$ref] = ['value' => $value, 'origin' => in_array($ref, self::PUBLIC_FACTS, true) ? 'user_metadata' : 'ai_analysis'];
            }
        }

        return ['revision' => $revision, 'facts' => $facts];
    }

    public function render(string $ref, string $value): string
    {
        return match ($ref) {
            'f1' => "Найдена работа «{$value}».",
            'f2' => "В описании работы указано: «{$value}».",
            'f3' => "По данным AI-анализа: «{$value}».",
            'f4' => "Темы по данным AI-анализа: «{$value}».",
            'f5' => "Объекты по данным AI-анализа: «{$value}».",
            'f6' => "Теги по данным AI-анализа: «{$value}».",
            'f7' => "В публичной атрибуции указан автор «{$value}».",
        };
    }

    private function shortList(?array $values): string
    {
        return implode(', ', array_slice(array_filter($values ?? [], 'is_string'), 0, 4));
    }

    private function plainValue(?string $value): string
    {
        $text = strip_tags(html_entity_decode($value ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('~(?:[a-z][a-z0-9+.-]*://|(?<!:)//|www\.|mailto:)[^\s]+~iu', '', $text);

        $text = Str::squish($text ?? '');

        return strlen($text) <= self::FACT_BYTES
            ? $text
            : rtrim(mb_strcut($text, 0, self::FACT_BYTES - strlen('…'), 'UTF-8')).'…';
    }
}
