<?php

namespace App\Services\Search;

class AssistantPreviousDates
{
    private const CENTURIES = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII',
        'XIII', 'XIV', 'XV', 'XVI', 'XVII', 'XVIII', 'XIX', 'XX', 'XXI'];

    private const CENTURY = '/\A([IVX]{1,5}|[1-9]\d?)(?:[-‑]?(?:й|м|го|th|st|nd|rd))?\s*(?:век(?:а|е|у|ом)?|century)(?!\pL)/iu';

    public function evidence(string $query, array $entries): array
    {
        if (! preg_match('/\b(?:век(?:а|е|у|ом)?|дат[ауые]|год(?:а|у|ом)?|период|когда|centur(?:y|ies)|date|year|period|when|\d{4})\b/iu', $query)) {
            return $entries;
        }

        // Only a single, explicitly requested year or century can filter a historical citation.
        if (preg_match('/\b(?:не|кроме|до|после|not|except|before|after)\b|[0-9ivx]\s*[-–—]\s*[0-9ivx]/iu', $query)) {
            return [];
        }
        preg_match_all('/(?<![\pL\d])[IVX]{1,5}(?:[-‑]?(?:й|м|го))?\s+век(?:а|е|у|ом)?(?!\pL)|(?<![\pL\d])\d{1,2}(?:[-‑]?(?:й|м|го|th|st|nd|rd))?\s+(?:век(?:а|е|у|ом)?|century)(?!\pL)|\b\d{4}\b/iu', $query, $dates);
        $wantsDate = preg_match('/\b(?:когда|when|дат[ауые]|date|year)\b/iu', $query);
        if (count($dates[0]) > 1 || ($dates[0] === [] && ! $wantsDate)) {
            return [];
        }
        $target = $dates[0] === [] ? null : $this->period($dates[0][0]);
        if ($dates[0] !== [] && $target === null) {
            return [];
        }

        return array_filter($entries, function (array $entry) use ($target): bool {
            if ($entry['reference']['fact_ref'] !== 'f2'
                || preg_match_all('/(?:\A|\s)(?:date(?: of creation)?|creation date|дата(?: создания)?|год(?: создания)?):\s*([^.!;?]+)/iu', $entry['fact']['value'], $date) !== 1) {
                return false;
            }

            $period = $this->period(trim($date[1][0]));

            return $period !== null && ($target === null || ($period[0] >= $target[0] && $period[1] <= $target[1]));
        });
    }

    private function period(string $text): ?array
    {
        if (preg_match('/\A[1-9]\d{3}\z/', $text)) {
            return [(int) $text, (int) $text];
        }
        if (! preg_match(self::CENTURY, $text, $match) || strlen($match[0]) !== strlen($text)) {
            return null;
        }
        $ordinal = ctype_digit($match[1]) ? (int) $match[1] : array_search(strtoupper($match[1]), self::CENTURIES, true);
        $century = ctype_digit($match[1]) ? $ordinal : ($ordinal === false ? 0 : $ordinal + 1);

        return $century >= 1 && $century <= 21 ? [($century - 1) * 100 + 1, $century * 100] : null;
    }
}
