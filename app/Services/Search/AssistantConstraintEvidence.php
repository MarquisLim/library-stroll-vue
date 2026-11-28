<?php

namespace App\Services\Search;

use App\Models\Artwork;
use App\Services\AiService\ArtworkIndexingService;

class AssistantConstraintEvidence
{
    public const UNKNOWN = 'insufficient_evidence';

    private const NUMBERS = [
        'zero' => 0, 'ноль' => 0, 'one' => 1, 'single' => 1, 'один' => 1, 'одна' => 1,
        'two' => 2, 'два' => 2, 'две' => 2, 'three' => 3, 'три' => 3, 'four' => 4, 'четыре' => 4,
        'five' => 5, 'пять' => 5, 'six' => 6, 'шесть' => 6, 'seven' => 7, 'семь' => 7,
        'eight' => 8, 'восемь' => 8, 'nine' => 9, 'девять' => 9, 'ten' => 10, 'десять' => 10,
    ];

    private const NEGATIVE = '(?:no|without|not any|not a|not an|без|нет)';

    public function __construct(private ArtworkIndexingService $indexing) {}

    public function conditions(array $plan): array
    {
        $targets = array_map(fn ($object) => AssistantConstraintTerms::forms('object', $object['name']), $plan['objects']);
        $result = [];
        foreach ($plan['objects'] as $i => $object) {
            $weight = $object['role'] ?? 'primary';
            $mode = $object['mode'] ?? 'required';
            $result['object:'.($i + 1)] = $this->condition('object', $object['name'], $mode, $weight);
            if (isset($object['count'])) {
                $count = $object['count'];
                $result['count:'.($i + 1)] = $this->condition('count', $count['text'], $mode, 'condition',
                    target: $targets[$i], count: $count);
            }
        }
        foreach ($plan['conditions'] as $i => $item) {
            $result['condition:'.($i + 1)] = $this->condition($item['kind'], $item['text'], $item['mode'] ?? 'required',
                'condition', target: $targets[($item['target'] ?? 0) - 1] ?? [],
                other: $targets[($item['other'] ?? 0) - 1] ?? []);
        }
        $seen = [];

        return array_filter($result, function (array $item) use (&$seen) {
            if (isset($seen[$item['key']])) {
                return false;
            }
            $seen[$item['key']] = true;

            return true;
        });
    }

    private function condition(string $kind, string $text, string $mode, string $weight, array $target = [], array $other = [], ?array $count = null): array
    {
        $forms = AssistantConstraintTerms::forms($kind, $text);
        if ($kind === 'presence' && $forms !== []) {
            $named = preg_replace('/^(?:without|no|not|без|нет) /u', '', AssistantConstraintTerms::normalize($text));
            $forms = AssistantConstraintTerms::forms($kind, $named);
        }
        $key = [$kind, $target[0] ?? '', $other[0] ?? '', str_contains($mode, 'excluded') ? 'excluded' : 'positive'];
        $key[] = $count === null ? ($forms[0] ?? AssistantConstraintTerms::normalize($text))
            : [$count['op'], $count['n'] ?? null, $count['max'] ?? null];

        return compact('kind', 'text', 'mode', 'weight', 'forms', 'target', 'other', 'count') + ['key' => json_encode($key, JSON_THROW_ON_ERROR)];
    }

    public function evaluate(Artwork $artwork, array $conditions): array
    {
        $meta = $artwork->aiMetadata;
        $uuid = $artwork->getFirstMedia('artworks')?->uuid;
        if (! $meta?->isIndexed() || ! is_string($meta->vision_model) || trim($meta->vision_model) === ''
            || ! $uuid || $meta->source_media_uuid !== $uuid
            || $meta->content_hash !== $this->indexing->contentHash($artwork, sortedTagNames: $artwork->tags->pluck('name')->all())) {
            return array_map(fn () => self::decision(self::UNKNOWN, 'stale_or_missing_vision'), $conditions);
        }
        $summary = array_map($this->fragment(...), preg_split('/[.!?;\r\n]+/u', mb_strcut($meta->summary ?? '', 0, 3072, 'UTF-8')) ?: []);
        $fields = [];
        foreach ($meta->only(['objects', 'themes', 'colors', 'styles', 'tags_ru', 'tags_en']) as $field => $values) {
            $fields[$field] = array_map($this->fragment(...), array_filter(array_slice($values ?? [], 0, 24), 'is_string'));
        }

        return array_map(fn ($condition) => $this->judge($condition, $summary, $fields), $conditions);
    }

    private function fragment(string $value): array
    {
        return [AssistantConstraintTerms::normalize($value), AssistantSearchDiagnostics::publicText(trim($value), 144)];
    }

    private function judge(array $item, array $summary, array $fields): array
    {
        ['kind' => $kind, 'forms' => $forms, 'mode' => $mode, 'target' => $target, 'other' => $other, 'count' => $count] = $item;
        if (($kind !== 'count' && $forms === []) || (in_array($kind, ['count', 'relation', 'attribute', 'color'], true) && $target === [])
            || ($kind === 'relation' && $other === [])) {
            return self::decision(self::UNKNOWN, 'unsupported_binding');
        }
        $term = AssistantConstraintTerms::pattern($forms);
        $subject = AssistantConstraintTerms::pattern($target);
        $second = AssistantConstraintTerms::pattern($other);
        $positive = [];
        $negative = [];
        foreach ($summary as [$sentence, $fragment]) {
            if ($sentence === '' || $this->contains($sentence, 'perhaps|possibly|maybe|unclear|возможно|предположительно')) {
                continue;
            }
            $evidence = ['field' => 'summary', 'fragment' => $fragment];
            [$yes, $no] = match ($kind) {
                'object', 'presence' => $this->presence($sentence, $term),
                'count' => $this->quantity($sentence, $subject, $count),
                'color', 'background_color' => $this->color($sentence, $kind, $forms, $subject),
                'attribute' => $this->attribute($sentence, $term, $subject, $forms[0]),
                'relation' => $this->relation($sentence, $term, $subject, $second, $forms),
                'style', 'media', 'scene' => $this->described($sentence, $term, $kind),
            };
            if ($yes) {
                $positive['summary'] ??= $evidence;
            }
            if ($no) {
                $negative['summary'] ??= $evidence;
            }
        }
        $usable = match ($kind) {
            'object', 'presence' => ['objects', 'tags_ru', 'tags_en'],
            'style', 'media' => ['styles'],
            'scene' => ['themes', 'objects'],
            default => [],
        };
        foreach ($usable as $field) {
            foreach ($fields[$field] ?? [] as [$normalized, $fragment]) {
                if (in_array($normalized, $forms, true)) {
                    $positive[$field] = ['field' => $field, 'fragment' => $fragment];
                    break;
                }
            }
        }
        if ($positive !== [] && $negative !== []) {
            return self::decision(self::UNKNOWN, 'conflicting_metadata', [reset($positive), reset($negative)]);
        }
        if ($positive === [] && $negative === []) {
            if (in_array($kind, ['color', 'background_color'], true)) {
                foreach ($fields['colors'] ?? [] as [$normalized, $fragment]) {
                    if ($this->contains($normalized, $term)) {
                        return self::decision(self::UNKNOWN, 'palette_without_binding', [['field' => 'colors', 'fragment' => $fragment]]);
                    }
                }
            }

            return self::decision(self::UNKNOWN, 'no_explicit_evidence');
        }
        $excluded = str_contains($mode, 'excluded');
        $confirmed = ($positive !== []) !== $excluded;

        return self::decision($confirmed ? 'confirmed' : 'contradicted', $positive === [] ? 'explicit_contrary_statement' : 'explicit_match',
            array_slice(array_values($positive ?: $negative), 0, 2));
    }

    private function contains(string $sentence, string $pattern): bool
    {
        return preg_match('~(?<![\pL\pN])(?:'.$pattern.')(?![\pL\pN])~u', $sentence) === 1;
    }

    private function presence(string $sentence, string $term): array
    {
        $negative = self::NEGATIVE.' (?:(?:any|visible|discernible) )?';
        $scope = '(?: in (?:the|this) (?:image|scene|picture|frame|view)| in view| anywhere| в кадре| на изображении)?';
        $absent = $this->contains($sentence, $negative.$term.'(?: (?:is |are )?(?:visible|present|shown))?'.$scope.'$');
        // Negating an attribute or presence in one area says nothing about the object in the rest of the image.
        $affirmative = preg_replace('~(?<![\pL\pN])'.$negative.'(?:'.AssistantConstraintTerms::colorForms().' )?'.$term.'(?![\pL\pN])~u',
            '', $sentence);

        return [$this->contains($affirmative, $term), $absent];
    }

    private function quantity(string $sentence, string $subject, array $requested): array
    {
        if ($this->contains($sentence, 'not|no|without|без|нет|other|additional|остальных')) {
            return [false, false];
        }
        $number = '(?:'.implode('|', array_keys(self::NUMBERS)).'|[0-9]{1,3})';
        $qualifier = 'at least|at most|about|approximately|around|roughly|more than|less than|close to|не менее|не более|около|примерно|более|менее';
        $yes = $requested['op'] === 'vague' && $this->contains($sentence, '(?:several|a few|несколько) '.$subject)
            && $this->contains(AssistantConstraintTerms::normalize($requested['text']), 'several|a few|few|несколько|нескольких');
        $no = false;
        preg_match_all('~(?<![\pL\pN])(?:(?<qualifier>'.$qualifier.') )?(?<n>'.$number.')(?: '.AssistantConstraintTerms::colorForms().')? '.$subject.'(?![\pL\pN])~u',
            $sentence, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if ($match['qualifier'] !== '' || $this->contains($sentence, 'between|от|additional|others|остальных')) {
                continue;
            }
            $n = self::NUMBERS[$match['n']] ?? (int) $match['n'];
            $applies = match ($requested['op']) {
                'exact' => $n === $requested['n'],
                'at_least' => $n >= $requested['n'],
                'at_most' => $n <= $requested['n'],
                'range' => $n >= $requested['n'] && $n <= $requested['max'],
                default => null,
            };
            $yes = $yes || $applies === true;
            $no = $no || $applies === false;
        }

        return [$yes, $no];
    }

    private function color(string $sentence, string $kind, array $forms, string $subject): array
    {
        if ($this->contains($sentence, 'not|no|without|без|нет')) {
            return [false, false];
        }
        $color = AssistantConstraintTerms::colorForms();
        $colors = $color.'(?: (?:and|и) '.$color.')?';
        $target = $kind === 'background_color' ? '(?:background|backdrop|фон|фоне)' : $subject;
        $bound = '~(?<![\pL\pN])(?:(?<before>'.$colors.') '.$target.'|'.$target.' (?:is|are|was|has color|имеет цвет|выглядит) (?<after>'.$colors.'))(?![\pL\pN])~u';
        preg_match_all($bound, $sentence, $matches, PREG_SET_ORDER);
        $yes = $no = false;
        foreach ($matches as $match) {
            $palette = preg_split('/ (?:and|и) /u', $match['before'] ?: $match['after']);
            foreach ($palette as $value) {
                $matchesColor = in_array($value, $forms, true);
                $yes = $yes || $matchesColor;
                $no = $no || ! $matchesColor;
            }
        }

        return [$yes, $no];
    }

    private function attribute(string $sentence, string $term, string $subject, string $canonical): array
    {
        $positive = $this->contains($sentence, $term.' '.$subject.'|'.$subject.' (?:is|are|with|has|made of|с) (?:a |an )?'.$term);
        $negative = $this->contains($sentence, $subject.' (?:is not|without|has no|без) (?:a |any )?'.$term);
        if ($canonical === 'bearded') {
            $positive = $positive || $this->contains($sentence, $subject.' with (?:a )?(?:short |long |full |dark |white )?beard');
            $negative = $negative || $this->contains($sentence, '(?:clean shaven|безбородый) '.$subject.'|'.$subject.' (?:is clean shaven|without (?:a )?beard|без бороды)');
        }

        return [$positive, $negative];
    }

    private function relation(string $sentence, string $term, string $subject, string $other, array $forms): array
    {
        if ($this->contains($sentence, 'not|no|without|без|нет')) {
            return [false, false];
        }
        $opposite = AssistantConstraintTerms::oppositeRelation($forms);
        $bind = fn ($cue) => $this->contains($sentence, $subject.' (?:(?:is|are) )?'.$cue.' (?:a |an |the )?'.$other);

        return [$bind($term), $opposite !== [] && $bind(AssistantConstraintTerms::pattern($opposite))];
    }

    private function described(string $sentence, string $term, string $kind): array
    {
        if ($kind === 'scene') {
            return [$this->contains($sentence, '(?:in|at|within|inside|в|на) (?:a |the )?'.$term.'|'.$term.' (?:scene|landscape|setting)'), false];
        }
        $yes = $this->contains($sentence, '^(?:(?:a|an|the|this) )?'.$term.'|(?:is|shows|depicts) (?:a |an )?'.$term.'|'.$term.' (?:style|technique)');

        return [$yes, $this->contains($sentence, '(?:not|rather than|а не) (?:a |an )?'.$term)];
    }

    private static function decision(string $state, string $reason, array $evidence = []): array
    {
        return compact('state', 'reason', 'evidence');
    }
}
