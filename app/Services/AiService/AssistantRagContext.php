<?php

namespace App\Services\AiService;

use App\Models\Artwork;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AssistantRagContext
{
    public const SOURCE_LIMIT = 5;

    public const LEGACY = 'legacy';

    public const VISUAL = 'visual_priority';

    // Matches the FastAPI bound on the serialized user message for both layouts.
    public const INPUT_BYTES = 1400;

    public const FACT_REF_PATTERN = '/\Af(?:[1-9]|1[01])\z/';

    private const FACT_BYTES = 240;

    private const PUBLIC_FACTS = ['f1', 'f2', 'f7'];

    private const AI_FACTS = ['f3', 'f4', 'f5', 'f6'];

    private const VISUAL_EXCERPT_BYTES = ['f3' => 84, 'f5' => 32, 'f8' => 30, 'f9' => 23, 'f4' => 26, 'f10' => 24, 'f11' => 24];

    public function __construct(private ArtworkIndexingService $indexing) {}

    public function prepare(string $query, Collection $artworks, string $strategy = self::LEGACY): array
    {
        $snapshots = [];
        foreach ($artworks->take(self::SOURCE_LIMIT)->values() as $index => $artwork) {
            $snapshots['s'.($index + 1)] = $this->snapshot($artwork);
        }

        $facts = $strategy === self::VISUAL
            ? $this->packVisualFacts($query, $snapshots)
            : $this->packFacts($query, $snapshots);

        return [$facts, $snapshots];
    }

    public function packFacts(string $query, array $snapshots, bool $savedFacts = false): array
    {
        $facts = [];
        // Offer a fact from each source before adding more detail from one work.
        $refs = [...self::PUBLIC_FACTS, ...self::AI_FACTS];
        if ($savedFacts) {
            $refs = array_unique([...$refs, ...array_keys(self::VISUAL_EXCERPT_BYTES)]);
        }
        foreach ($refs as $ref) {
            foreach ($snapshots as $source => $snapshot) {
                $fact = $snapshot['facts'][$ref] ?? null;
                if ($fact === null) {
                    continue;
                }

                $candidate = [...$facts, ['source_ref' => $source, 'fact_ref' => $ref, ...$fact]];
                if (self::inputBytes($query, $candidate, self::LEGACY) <= self::INPUT_BYTES) {
                    $facts = $candidate;
                }
            }
        }

        return $facts;
    }

    private function packVisualFacts(string $query, array $snapshots): array
    {
        $facts = [];
        if ($snapshots === []) {
            return $facts;
        }

        // Reserve a share for each source before offering any source a second fact.
        $baseBytes = self::inputBytes($query, [], self::VISUAL);
        $share = intdiv(self::INPUT_BYTES - $baseBytes, count($snapshots));
        foreach ($snapshots as $source => $snapshot) {
            $priority = $snapshot['vision'] ? [...array_keys(self::VISUAL_EXCERPT_BYTES), ...self::PUBLIC_FACTS] : self::PUBLIC_FACTS;
            $refs = array_intersect($priority, array_keys($snapshot['facts']));
            $ref = reset($refs);
            if ($ref === false) {
                continue;
            }
            $fact = ['source_ref' => $source, 'fact_ref' => $ref, ...$snapshot['facts'][$ref]];
            $overhead = self::inputBytes($query, [[...$fact, 'value' => '']], self::VISUAL) - $baseBytes + 1;
            $excerpt = $this->fitExcerpt($fact['value'], min(self::VISUAL_EXCERPT_BYTES[$ref] ?? 48, $share - $overhead));
            if ($excerpt === '') {
                return [];
            }
            $facts[$source.':'.$ref] = [...$fact, 'value' => $excerpt];
        }

        foreach (self::VISUAL_EXCERPT_BYTES as $ref => $bytes) {
            foreach ($snapshots as $source => $snapshot) {
                $key = $source.':'.$ref;
                if (! $snapshot['vision'] || isset($facts[$key]) || ! isset($snapshot['facts'][$ref])) {
                    continue;
                }
                $fact = $snapshot['facts'][$ref];
                $excerpt = $this->fitExcerpt($fact['value'], $bytes);
                if ($excerpt === '') {
                    continue;
                }
                $candidate = [...$facts, $key => ['source_ref' => $source, 'fact_ref' => $ref,
                    ...$fact, 'value' => $excerpt]];
                if (self::inputBytes($query, array_values($candidate), self::VISUAL) <= self::INPUT_BYTES) {
                    $facts = $candidate;
                }
            }
        }

        // Spend remaining space on fuller evidence, in the same order as the excerpts.
        foreach ($facts as $key => $fact) {
            if ($fact['origin'] !== 'ai_analysis') {
                continue;
            }
            $candidate = $facts;
            $candidate[$key]['value'] = $snapshots[$fact['source_ref']]['facts'][$fact['fact_ref']]['value'];
            if (self::inputBytes($query, array_values($candidate), self::VISUAL) <= self::INPUT_BYTES) {
                $facts = $candidate;
            }
        }

        return array_values($facts);
    }

    public static function inputBytes(string $query, array $facts, string $strategy): int
    {
        if ($strategy === self::VISUAL) {
            $grouped = [];
            foreach ($facts as $fact) {
                $source = $fact['source_ref'];
                $grouped[$source] ??= ['source_ref' => $source];
                $ref = $fact['fact_ref'];
                $grouped[$source][$ref] = ($ref === 'f8' ? 'Palette colors: ' : '').$fact['value'];
            }
            $facts = array_values($grouped);
        }

        return strlen(self::inputJson(['query' => $query, 'facts' => $facts]));
    }

    public static function inputJson(array|string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
    }

    public function snapshot(Artwork $artwork): array
    {
        $revision = $this->indexing->contentHash($artwork, useWritePdo: true,
            sortedTagNames: $artwork->relationLoaded('tags') ? $artwork->tags->pluck('name')->all() : null);
        $metadata = $artwork->aiMetadata;
        $mediaUuid = $artwork->getFirstMedia('artworks')?->uuid;
        $aiCurrent = $metadata?->isIndexed() && $metadata->content_hash === $revision
            && is_string($mediaUuid) && $mediaUuid !== '' && $metadata->source_media_uuid === $mediaUuid;
        $vision = $aiCurrent && is_string($metadata->vision_model) && trim($metadata->vision_model) !== '';

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
        if ($vision) {
            $values += [
                'f8' => $this->shortList($metadata->colors),
                'f9' => $this->shortList($metadata->styles),
                'f10' => $this->shortList($metadata->tags_ru),
                'f11' => $this->shortList($metadata->tags_en),
            ];
        }

        $facts = [];
        foreach ($values as $ref => $value) {
            $value = $this->plainValue($value);
            if ($value !== '') {
                $facts[$ref] = ['value' => $value, 'origin' => in_array($ref, self::PUBLIC_FACTS, true) ? 'user_metadata' : 'ai_analysis'];
            }
        }

        return ['revision' => $revision, 'facts' => $facts, 'vision' => $vision];
    }

    public function sameSnapshot(array $current, array $packed, string $strategy): bool
    {
        if ($strategy === self::LEGACY) {
            $legacy = array_fill_keys([...self::PUBLIC_FACTS, ...self::AI_FACTS], true);
            $current['facts'] = array_intersect_key($current['facts'], $legacy);
            $packed['facts'] = array_intersect_key($packed['facts'], $legacy);
            unset($current['vision'], $packed['vision']);
        }

        return $current === $packed;
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
            'f8' => "Цвета палитры по данным AI-анализа: «{$value}».",
            'f9' => "Стили по данным AI-анализа: «{$value}».",
            'f10' => "Теги на русском по данным AI-анализа: «{$value}».",
            'f11' => "Теги на английском по данным AI-анализа: «{$value}».",
        };
    }

    private function shortList(?array $values): string
    {
        return implode(', ', array_slice(array_filter($values ?? [], 'is_string'), 0, 4));
    }

    private function fitExcerpt(string $value, int $bytes): string
    {
        // Account for JSON escaping as well as UTF-8. Prefer complete words or list items.
        if ($bytes < 4) {
            return '';
        }
        if (strlen(self::inputJson($value)) - 2 <= $bytes) {
            return $value;
        }
        $excerpt = mb_strcut($value, 0, $bytes - strlen('…'), 'UTF-8');
        while (strlen(self::inputJson($excerpt.'…')) - 2 > $bytes) {
            $excerpt = mb_substr($excerpt, 0, -1, 'UTF-8');
        }
        $boundary = preg_replace('/[ ,]+\S*$/u', '', $excerpt);
        if ($boundary !== null && strlen($boundary) >= strlen($excerpt) / 2) {
            $excerpt = $boundary;
        }

        return $excerpt === '' ? '' : rtrim($excerpt, ' ,').'…';
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
