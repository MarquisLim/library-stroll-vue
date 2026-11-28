<?php

namespace App\Services\Search;

use App\Models\Artwork;
use App\Services\AiService\ArtworkIndexingService;
use Illuminate\Database\Eloquent\Builder;

class MetadataLexicalSearch
{
    public const STRATEGY = 'metadata_tokens';

    private const MAX_DOCUMENTS = 1000;

    private const WEIGHTS = [
        'title' => 7.0, 'description' => 1.5, 'summary' => 3.5, 'objects' => 6.0,
        'themes' => 2.0, 'colors' => 0.35, 'styles' => 2.5, 'tags_ru' => 5.0, 'tags_en' => 5.0,
    ];

    private const STOP_WORDS = 'a an the and or of to in on at by for with from as is are that this it me my please find show search look image images picture pictures want need '
        .'и или а но на в во под над у о об из от для по за с со к ко не это мне мой пожалуйста найди найдите покажи покажите подбери ищу хочу нужен нужна нужно нужны изображение изображения картинка картинки картинку';

    public function __construct(private ArtworkIndexingService $indexing) {}

    /** @return array{terms: list<string>, documents: int, load_ms: float, score_ms: float, rows: list<array{id: int, score: float, fields: array<string, string>}>}|null */
    public function rank(string $query, Builder $scope): ?array
    {
        $queryTokens = array_slice(self::tokens($query, 2048), 0, 12);
        $terms = array_values(array_unique($queryTokens));
        if ($terms === []) {
            return ['terms' => [], 'documents' => 0, 'load_ms' => 0.0, 'score_ms' => 0.0, 'rows' => []];
        }

        // The cap bounds hydration and tokenization. A larger scope must fall back as a whole.
        $loading = hrtime(true);
        $artworks = $scope->useWritePdo()->where('ai_indexing_enabled', true)->orderBy('id')->limit(self::MAX_DOCUMENTS + 1)
            ->with([
                'media' => fn ($builder) => $builder->useWritePdo()->where('collection_name', 'artworks'),
                'tags' => fn ($builder) => $builder->useWritePdo()->orderBy('name'),
                'aiMetadata' => fn ($builder) => $builder->useWritePdo()->select([
                    'artwork_id', 'status', 'content_hash', 'source_media_uuid', 'vision_model',
                    'summary', 'objects', 'themes', 'colors', 'styles', 'tags_ru', 'tags_en',
                ]),
            ])->get(['id', 'title', 'description', 'is_published', 'is_private', 'is_blocked', 'has_ai']);
        $documents = $artworks->count();
        if ($documents > self::MAX_DOCUMENTS) {
            return null;
        }

        $scoring = hrtime(true);

        $roots = array_combine($terms, array_map(self::root(...), $terms));
        $rootCache = $roots;
        $frequency = array_fill_keys($terms, 0);
        $matches = [];
        foreach ($artworks as $artwork) {
            $match = $this->matchDocument($this->fields($artwork), $roots, $queryTokens, $rootCache);
            if ($match['terms'] === []) {
                continue;
            }
            foreach (array_keys($match['terms']) as $term) {
                $frequency[$term]++;
            }
            $matches[$artwork->id] = $match;
        }

        $rows = [];
        foreach ($matches as $id => $match) {
            $score = 0.0;
            foreach ($match['terms'] as $term => [$strongest, $sum]) {
                $idf = 1 + log(1 + ($documents - $frequency[$term] + 0.5) / ($frequency[$term] + 0.5));
                $score += $idf * ($strongest + min(0.3 * $strongest, 0.15 * ($sum - $strongest)));
            }
            $coverage = count($match['terms']) / count($terms);
            $rows[] = ['id' => $id, 'score' => round($score * $coverage ** 2 + $match['phrase'], 6), 'fields' => $match['fields']];
        }
        usort($rows, fn ($left, $right) => ($right['score'] <=> $left['score']) ?: ($left['id'] <=> $right['id']));

        return ['terms' => $terms, 'documents' => $documents, 'load_ms' => round(($scoring - $loading) / 1e6, 3),
            'score_ms' => AssistantSearchDiagnostics::ms($scoring), 'rows' => $rows];
    }

    private function fields(Artwork $work): array
    {
        $fields = ['title' => $work->title, 'description' => $work->description];
        $metadata = $work->aiMetadata;
        $mediaUuid = $work->getFirstMedia('artworks')?->uuid;
        if ($metadata?->isIndexed() && is_string($metadata->vision_model) && $metadata->vision_model !== ''
            && is_string($mediaUuid) && $mediaUuid !== '' && $metadata->source_media_uuid === $mediaUuid
            && $metadata->content_hash === $this->indexing->contentHash($work, sortedTagNames: $work->tags->pluck('name')->all())) {
            $fields += $metadata->only(['summary', 'objects', 'themes', 'colors', 'styles', 'tags_ru', 'tags_en']);
        }

        return $fields;
    }

    private function matchDocument(array $fields, array $roots, array $queryTokens, array &$rootCache): array
    {
        $matched = [];
        $termScores = [];
        $phrase = 0.0;
        $queryPhrase = count($queryTokens) > 1 ? ' '.implode(' ', $queryTokens).' ' : null;
        foreach ($fields as $field => $value) {
            $isList = is_array($value);
            if ($isList) {
                $value = implode(' ', array_filter(array_slice($value, 0, 15), 'is_string'));
            }
            $tokens = self::tokens($value ?? '', 2048);
            if ($tokens === []) {
                continue;
            }
            $exact = array_fill_keys($tokens, true);
            $partial = [];
            foreach (array_keys($exact) as $token) {
                $partial[$rootCache[$token] ??= self::root((string) $token)] = true;
            }
            $weight = self::WEIGHTS[$field];
            foreach ($roots as $term => $root) {
                $isExact = isset($exact[$term]);
                if (! $isExact && ! isset($partial[$root])) {
                    continue;
                }
                if ($isExact || ! isset($matched[$field])) {
                    $matched[$field] = $isExact ? 'exact' : 'inflection';
                }
                $contribution = $weight * ($isExact ? 1.0 : 0.65);
                [$strongest, $sum] = $termScores[$term] ?? [0, 0];
                $termScores[$term] = [max($strongest, $contribution), $sum + $contribution];
            }
            if (! $isList && $queryPhrase !== null && str_contains(' '.implode(' ', $tokens).' ', $queryPhrase)) {
                $matched[$field] = 'phrase';
                $phrase = max($phrase, $weight * 0.8);
            }
        }
        if (count($queryTokens) > 1 && array_keys($matched) === ['colors']) {
            return ['terms' => [], 'fields' => [], 'phrase' => 0.0];
        }

        return ['terms' => $termScores, 'fields' => $matched, 'phrase' => $phrase];
    }

    public static function tokens(string $text, int $bytes = 2048): array
    {
        static $ignored = null;
        $ignored ??= array_fill_keys(explode(' ', self::STOP_WORDS), true);
        $plain = strip_tags(html_entity_decode(mb_strcut($text, 0, $bytes, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = preg_replace('~(?:https?://|www\.)\S+~iu', ' ', $plain) ?? '';
        $plain = str_replace('ё', 'е', mb_strtolower($plain, 'UTF-8'));
        preg_match_all('/[\pL\pN]+/u', $plain, $matches);

        return array_values(array_filter($matches[0], static fn ($term) => ! isset($ignored[$term])
            && (mb_strlen($term, 'UTF-8') >= 2 || ctype_digit($term)) && mb_strlen($term, 'UTF-8') <= 48));
    }

    private static function root(string $token): string
    {
        if (preg_match('/\A[а-я]{4,}\z/u', $token)) {
            $root = preg_replace('/(?:иями|ями|ами|ого|его|ому|ему|ыми|ими|ая|яя|ые|ие|ое|ее|ую|юю|ой|ей|ый|ий|ых|их|ам|ям|ах|ях|ов|ев|ом|ем|а|я|ы|и|у|ю|е|о|ь|й)\z/u', '', $token);

            return mb_strlen($root, 'UTF-8') >= 3 ? $root : $token;
        }

        return match (true) {
            (bool) preg_match('/\A[a-z]{3,}ies\z/', $token) => substr($token, 0, -3).'y',
            (bool) preg_match('/\A[a-z]{2,}(?:ches|shes|xes|ses|zes)\z/', $token) => substr($token, 0, -2),
            (bool) preg_match('/\A[a-z]{3,}(?<![sui])s\z/', $token) => substr($token, 0, -1),
            default => $token,
        };
    }
}
