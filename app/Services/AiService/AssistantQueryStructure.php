<?php

namespace App\Services\AiService;

use JsonException;
use stdClass;

final class AssistantQueryStructure
{
    private const NUMBER_WORDS = [
        'zero ноль', 'one один одна одно одного одну', 'two два две двух', 'three три трех', 'four четыре четырех',
        'five пять пяти', 'six шесть шести', 'seven семь семи', 'eight восемь восьми', 'nine девять девяти', 'ten десять десяти',
    ];

    private const COUNT_CUES = [
        'at_least' => 'не менее|не меньше|минимум|как минимум|at least|no fewer than',
        'at_most' => 'не более|не больше|максимум|до|at most|no more than|up to',
        'approx' => 'примерно|около|приблизительно|about|roughly|around|approximately',
        'range' => 'между|от|between|from|[-–—]|to',
        'vague' => 'несколько|нескольких|много|several|a few|few|some|many',
    ];

    public static function decode(string $json, string $query): ?array
    {
        if (strlen($json) > 16384) {
            return null;
        }
        try {
            $plan = json_decode($json, flags: JSON_THROW_ON_ERROR, depth: 10);
        } catch (JsonException) {
            return null;
        }
        if (! self::keys($plan, ['objects', 'conditions', 'ambiguities'])
            || ! self::boundedList($plan->objects, 6) || ! self::boundedList($plan->conditions, 12)
            || ! self::boundedList($plan->ambiguities, 4)) {
            return null;
        }
        foreach ($plan->objects as $object) {
            if (! self::keys($object, ['name'], ['role', 'mode', 'cue', 'count'])
                || ! self::span($object->name, $query)
                || ! self::enum($object, 'role', 'primary', ['primary', 'additional'])
                || ! self::mode($object, ['required', 'preferred'], $query)
                || (isset($object->count) && ! self::count($object->count, $query))) {
                return null;
            }
        }
        $seen = [];
        foreach ($plan->conditions as $item) {
            if (! self::condition($item, count($plan->objects), $query)) {
                return null;
            }
            $key = json_encode([$item->kind, mb_strtolower($item->text, 'UTF-8'), $item->target ?? null, $item->other ?? null, $item->mode ?? 'required']);
            if (isset($seen[$key])) {
                return null;
            }
            $seen[$key] = true;
        }
        foreach ($plan->ambiguities as $ambiguity) {
            if (! self::keys($ambiguity, ['kind', 'text']) || ! self::span($ambiguity->text, $query)
                || ! in_array($ambiguity->kind, ['missing_subject', 'reference', 'quantity', 'attribute', 'relation', 'other'], true)) {
                return null;
            }
        }

        return json_decode($json, true, 10, JSON_THROW_ON_ERROR);
    }

    public static function wouldClarify(array $plan): bool
    {
        return $plan['objects'] === [] && $plan['conditions'] === []
            && array_intersect(['missing_subject', 'reference'], array_column($plan['ambiguities'], 'kind')) !== [];
    }

    private static function condition(mixed $item, int $objects, string $query): bool
    {
        if (! self::keys($item, ['kind', 'text'], ['target', 'other', 'mode', 'cue']) || ! self::span($item->text, $query)
            || ! in_array($item->kind, ['attribute', 'color', 'background_color', 'media', 'style', 'scene', 'relation', 'presence'], true)
            || ! self::mode($item, ['required', 'preferred', 'excluded', 'preferred_excluded'], $query)) {
            return false;
        }
        $target = $item->target ?? null;
        $other = $item->other ?? null;

        return (in_array($item->kind, ['attribute', 'color', 'relation'], true)
                ? is_int($target) && $target >= 1 && $target <= $objects : $target === null)
            && ($item->kind === 'relation' ? is_int($other) && $other >= 1 && $other <= $objects && $other !== $target : $other === null)
            && ($item->kind !== 'presence' || in_array($item->mode ?? null, ['excluded', 'preferred_excluded'], true))
            && ($item->kind !== 'background_color' || (bool) preg_match('/\bфон\pL*\b|\b(?:background|fondo|fond|against)\b|背景/iu', $query));
    }

    private static function count(mixed $count, string $query): bool
    {
        if (! self::keys($count, ['op', 'text'], ['n', 'max']) || ! self::span($count->text, $query)
            || ! in_array($count->op, ['exact', ...array_keys(self::COUNT_CUES)], true)) {
            return false;
        }
        $n = $count->n ?? null;
        $max = $count->max ?? null;
        $op = $count->op;
        if (($op === 'vague' ? $n !== null : ! is_int($n) || $n < 0 || $n > 1000)
            || ($op === 'range' ? ! is_int($max) || $max <= $n || $max > 1000 : $max !== null)) {
            return false;
        }
        $text = str_replace('ё', 'е', mb_strtolower($count->text, 'UTF-8'));
        preg_match_all('/[\pL\pN_]+/u', $text, $words);
        $numbers = [];
        $values = [];
        foreach (self::NUMBER_WORDS as $value => $forms) {
            $values += array_fill_keys(explode(' ', $forms), $value);
        }
        foreach ($words[0] as $word) {
            if (isset($values[$word])) {
                $numbers[] = $values[$word];
            } elseif (preg_match('/\A[0-9]{1,4}\z/', $word)) {
                $numbers[] = (int) $word;
            }
        }
        $expected = match ($op) {'vague' => [], 'range' => [$n, $max], default => [$n]};
        if ($numbers !== $expected) {
            return false;
        }
        if ($op !== 'exact') {
            return self::cue(self::COUNT_CUES[$op], $text);
        }
        $qualified = implode('|', self::COUNT_CUES);

        return ! self::cue($qualified, $text)
            && ! preg_match('~(?:'.$qualified.')\s+'.self::spanPattern($count->text).'~iu', $query);
    }

    private static function mode(stdClass $item, array $allowed, string $query): bool
    {
        if (! self::enum($item, 'mode', 'required', $allowed)) {
            return false;
        }
        $mode = $item->mode ?? 'required';
        $cue = $item->cue ?? null;
        if ($cue === null) {
            return $mode === 'required';
        }

        return self::span($cue, $query)
            && (! str_contains($mode, 'preferred') || self::cue('желательно|предпочтительно|лучше|preferably|ideally|if possible|de preferencia', $cue))
            && (! str_contains($mode, 'excluded') || self::cue('без|кроме|не|а не|нет|without|no|not|except|excluding|sin|sans|なし|ない', $cue));
    }

    private static function keys(mixed $item, array $required, array $optional = []): bool
    {
        if (! $item instanceof stdClass) {
            return false;
        }
        $actual = array_keys((array) $item);

        return array_diff($required, $actual) === [] && array_diff($actual, [...$required, ...$optional]) === [];
    }

    private static function enum(stdClass $item, string $key, string $default, array $allowed): bool
    {
        return in_array(property_exists($item, $key) ? $item->{$key} : $default, $allowed, true);
    }

    private static function boundedList(mixed $list, int $limit): bool
    {
        return is_array($list) && count($list) <= $limit;
    }

    private static function cue(string $alternatives, string $text): bool
    {
        return (bool) preg_match('~(?<![a-zа-я0-9])(?:'.$alternatives.')(?![a-zа-я0-9])~iu', $text);
    }

    private static function span(mixed $value, string $query): bool
    {
        return is_string($value) && $value !== '' && mb_strlen($value, 'UTF-8') <= 96
            && ! preg_match('/\A\s|\s\z|[\x00-\x1f\x7f<>]|:\/\//u', $value)
            && (bool) preg_match('~'.self::spanPattern($value).'~iu', $query);
    }

    private static function spanPattern(string $value): string
    {
        $boundary = '[a-zA-Z\x{0400}-\x{052f}0-9]';

        return (preg_match('/\A'.$boundary.'/u', $value) ? '(?<![\pL\pN_])' : '')
            .preg_quote($value, '~').(preg_match('/'.$boundary.'\z/u', $value) ? '(?![\pL\pN_])' : '');
    }
}
