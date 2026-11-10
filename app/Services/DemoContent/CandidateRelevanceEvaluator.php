<?php

namespace App\Services\DemoContent;

class CandidateRelevanceEvaluator
{
    public function evaluate(ContentItem $item, string $category, array $theme): array
    {
        $fields = ['title' => $item->original_title, 'subject_tags' => implode(' ', $item->original_tags), 'categories' => implode(' ', $item->categories), 'classification' => implode(' ', array_filter([$item->medium, $item->original_metadata['classification'] ?? null, $item->original_metadata['style'] ?? null, $item->original_metadata['object_name'] ?? null]))];
        if ($item->original_description && in_array(mb_strtolower($item->license), ['cc0', 'public domain', 'cc0 / public domain'])) {
            $fields['description'] = $item->original_description;
        }
        $terms = array_unique([...($theme['synonyms'] ?? []), ...($theme['queries'] ?? [])]);
        $matched = [];
        $hits = [];
        $score = 0;
        foreach ($fields as $field => $text) {
            $text = $this->normalize($text);
            foreach ($theme['exclude'] ?? [] as $term) {
                if ($this->matches($text, $this->normalize($term))) {
                    return ['accepted' => false, 'score' => 0, 'matched_fields' => [], 'category' => $category, 'reason' => 'excluded_metadata', 'rule_version' => 2];
                }
            }
            foreach ($terms as $term) {
                if ($this->matches($text, $this->normalize($term))) {
                    $hits[] = $term;
                    $matched[] = $field;
                    break;
                }
            }
        }
        foreach (array_unique($matched) as $field) {
            $score += config("demo_content.relevance.weights.$field", 1);
        }

        return ['accepted' => $score >= config('demo_content.relevance.threshold', 2), 'score' => $score, 'matched_fields' => array_values(array_unique($matched)), 'matched_terms' => array_values(array_unique($hits)), 'category' => $category, 'rule_version' => 2];
    }

    private function normalize(string $text): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(ContentItem::text($text))));
    }

    private function matches(string $text, string $term): bool
    {
        return $term !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/u', $text) === 1;
    }
}
