<?php

namespace App\Services\ResearchDataset;

class DatasetQueryBuilder
{
    public function build(array $items, int $seed): array
    {
        $intents = [];
        $categories = array_unique(array_column($items, 'category'));
        foreach ($categories as $category) {
            if ($text = config('research_dataset.category_queries.'.$category)) {
                $intents['category:'.$category] = ['text' => $text, 'type' => 'thematic', 'category' => $category, 'concepts' => []];
            }
        }
        $concepts = config('research_dataset.concepts');
        $evidence = [];
        foreach ($items as $item) {
            $text = mb_strtolower(($item['source_metadata']['title'] ?? '').' '.$item['title'].' '.implode(' ', $item['tags']).' '.($item['source_metadata']['medium'] ?? ''));
            foreach ($concepts as $key => $c) {
                foreach ($c['terms'] as $term) {
                    if (preg_match('/(?<![\pL\pN])'.preg_quote($term, '/').'(?![\pL\pN])/iu', $text)) {
                        $evidence[$key][$item['dataset_item_id']] = $item;
                        break;
                    }
                }
            }
        }
        foreach ($evidence as $key => $matches) {
            $intents['concept:'.$key] = ['text' => array_intersect_key($concepts[$key], array_flip(['ru', 'en'])), 'type' => 'simple', 'category' => null, 'concepts' => [$key]];
        }
        foreach ($items as $item) {
            foreach ($evidence as $key => $matches) {
                if (isset($matches[$item['dataset_item_id']]) && $item['strata']['technique'] === 'watercolor') {
                    $intents['technique:watercolor+'.$key] = ['text' => ['ru' => 'Акварели, на которых изображены: '.$concepts[$key]['ru'], 'en' => 'Watercolors showing '.$concepts[$key]['en']], 'type' => 'complex', 'category' => null, 'concepts' => [$key]];
                }
            }
            if (isset($evidence['ships'][$item['dataset_item_id']]) && preg_match('/\bsailing\b/iu', implode(' ', $item['tags'])) && $item['strata']['period'] && (int) substr($item['strata']['period'], -4) < 1900 && $item['strata']['technique'] && $item['strata']['technique'] !== 'photograph') {
                $intents['historical:sailing-ships'] = ['text' => ['ru' => 'Найти исторические изображения кораблей, предпочтительно парусных, а не современные фотографии', 'en' => 'Find historical images of ships, preferably sailing ships rather than modern photographs'], 'type' => 'complex', 'category' => null, 'concepts' => ['ships']];
            }
        }
        foreach (array_keys($evidence) as $a) {
            foreach (array_keys($evidence) as $b) {
                if (strcmp($a, $b) >= 0 || ! array_intersect_key($evidence[$a], $evidence[$b])) {
                    continue;
                }
                $intents["concept:$a+$b"] = ['text' => ['ru' => 'Изображения: '.$concepts[$a]['ru'].' и '.$concepts[$b]['ru'], 'en' => 'Images with '.$concepts[$a]['en'].' and '.$concepts[$b]['en']], 'type' => 'complex', 'category' => null, 'concepts' => [$a, $b]];
            }
        }
        // Select intents before assigning splits. Equivalent phrasings share one intent.
        $buckets = [];
        foreach ($intents as $key => $intent) {
            $buckets[$intent['type']][$key] = $intent;
        }
        foreach ($buckets as &$bucket) {
            uksort($bucket, fn ($a, $b) => strcmp(hash('sha256', "$seed:$a"), hash('sha256', "$seed:$b")));
        } unset($bucket);
        $chosen = [];
        $limit = intdiv(config('research_dataset.query_target'), 2);
        while (count($chosen) < $limit) {
            $added = false;
            foreach (['simple', 'thematic', 'simple', 'complex'] as $type) {
                if (! empty($buckets[$type]) && count($chosen) < $limit) {
                    $key = array_key_first($buckets[$type]);
                    $chosen[$key] = $buckets[$type][$key];
                    unset($buckets[$type][$key]);
                    $added = true;
                }
            }
            if (! $added) {
                break;
            }
        }
        $keys = array_keys($chosen);
        usort($keys, fn ($a, $b) => strcmp(hash('sha256', "$seed:split:$a"), hash('sha256', "$seed:split:$b")));
        $development = array_flip(array_slice($keys, 0, (int) round(count($keys) * config('research_dataset.development_share'))));
        $queries = [];
        foreach ($chosen as $key => $intent) {
            foreach (['ru', 'en'] as $language) {
                $intentId = 'i-'.substr(hash('sha256', $key), 0, 16);
                $text = $intent['text'][$language];
                $type = $intent['type'];
                // A descriptive phrasing preserves the same semantic constraints and intent ID.
                if ($type === 'simple' && hexdec(substr(hash('sha256', $key), 0, 2)) % 2 === 0) {
                    $text = $language === 'ru' ? 'Ищу изображения, на которых можно увидеть: '.$text : 'Find images showing '.$text;
                    $type = 'descriptive';
                }
                $queries[] = ['query_id' => 'q-'.substr(hash('sha256', "$key:$language"), 0, 16), 'intent_id' => $intentId, 'text' => $text, 'language' => $language,
                    'type' => $type, 'base_type' => $type, 'split' => isset($development[$key]) ? 'development' : 'evaluation',
                    'category_hint' => $intent['category'], 'concept_hints' => $intent['concepts'], 'reviewed' => false, 'status' => 'draft', 'generation_basis' => 'source_metadata_support_not_relevance_judgment'];
            }
        }
        usort($queries, fn ($a, $b) => strcmp($a['query_id'], $b['query_id']));
        $english = array_keys(array_filter($queries, fn ($q) => $q['language'] === 'en'));
        usort($english, fn ($a, $b) => strcmp(hash('sha256', $seed.':cross:'.$queries[$a]['query_id']), hash('sha256', $seed.':cross:'.$queries[$b]['query_id'])));
        foreach (array_slice($english, 0, (int) round(count($queries) / 5)) as $index) {
            $queries[$index]['type'] = 'cross_lingual';
        }

        return $queries;
    }
}
