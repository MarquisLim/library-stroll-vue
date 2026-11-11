<?php

namespace App\Services\ResearchDataset;

use App\Models\Artwork;
use RuntimeException;

class RelevanceJudgmentService
{
    public const HEADER = ['query_id', 'dataset_item_id', 'relevance', 'annotator_id', 'judged_at'];

    public function __construct(private DatasetFiles $files) {}

    public function pool(array $items, array $queries, int $size, int $seed, array $existing = []): array
    {
        $result = [];
        foreach ($existing as $pair) {
            $key = $pair['query_id'].'|'.$pair['dataset_item_id'];
            $result[$key] = [...$pair, 'methods' => array_values(array_unique([...($result[$key]['methods'] ?? []), ...$pair['methods']]))];
        }
        $byLocal = array_column($items, 'dataset_item_id', 'artwork_id');
        foreach ($queries as $q) {
            // Same SQL predicate/ranking as the existing lexical branch, restricted to the fixed corpus.
            // Do not invoke HybridSearchService: it can contact the AI service.
            $lexical = Artwork::publiclyVisible()->whereIn('id', array_keys($byLocal))->where('title', 'like', '%'.$q['text'].'%')->withCount('likes')->orderByDesc('likes_count')->orderBy('id')->pluck('id')->all();
            $buckets = ['lexical' => array_map(fn ($id) => $byLocal[$id], $lexical), 'category' => [], 'random' => [], 'other_category' => []];
            $ordered = $items;
            usort($ordered, fn ($a, $b) => strcmp(hash('sha256', "$seed:{$q['query_id']}:{$a['dataset_item_id']}"), hash('sha256', "$seed:{$q['query_id']}:{$b['dataset_item_id']}")));
            foreach ($ordered as $i) {
                $buckets['random'][] = $i['dataset_item_id'];
                $match = $q['category_hint'] && $q['category_hint'] === $i['category'];
                if (! $q['category_hint'] && $q['concept_hints']) {
                    $text = mb_strtolower($i['title'].' '.implode(' ', $i['tags']));
                    foreach ($q['concept_hints'] as $concept) {
                        foreach (config('research_dataset.concepts.'.$concept.'.terms', []) as $term) {
                            if (preg_match('/(?<![\pL\pN])'.preg_quote($term, '/').'(?![\pL\pN])/iu', $text)) {
                                $match = true;
                                break 2;
                            }
                        }
                    }
                }
                $buckets[$match ? 'category' : 'other_category'][] = $i['dataset_item_id'];
            }
            $selected = [];
            foreach (config('research_dataset.pool_shares') as $method => $share) {
                foreach ($buckets[$method] as $id) {
                    $selected[$id][] = $method;
                    if (count(array_filter($selected, fn ($m) => in_array($method, $m))) >= (int) ceil($size * $share) || count($selected) >= min($size, count($items))) {
                        break;
                    }
                }
                if (count($selected) >= min($size, count($items))) {
                    break;
                }
            }
            foreach ($buckets['random'] as $id) {
                if (count($selected) >= min($size, count($items))) {
                    break;
                } $selected[$id] ??= ['random_fill'];
            }
            foreach ($selected as $id => $methods) {
                $key = $q['query_id'].'|'.$id;
                $result[$key] = ['query_id' => $q['query_id'], 'dataset_item_id' => $id, 'methods' => array_values(array_unique([...($result[$key]['methods'] ?? []), ...$methods])), 'initial_status' => 'unjudged'];
            }
        }
        ksort($result, SORT_STRING);

        return array_values($result);
    }

    public function validateRows(array $rows, array $queries, array $items, bool $assignTime = true): array
    {
        $queryIds = array_flip(array_column($queries, 'query_id'));
        $itemIds = array_flip(array_column($items, 'dataset_item_id'));
        $seen = [];
        $clean = [];
        foreach ($rows as $n => $r) {
            foreach (['query_id', 'dataset_item_id', 'relevance', 'annotator_id'] as $field) {
                if (! array_key_exists($field, $r)) {
                    throw new RuntimeException('Missing judgment column: '.$field);
                }
            }
            if (! isset($queryIds[$r['query_id']], $itemIds[$r['dataset_item_id']])) {
                throw new RuntimeException('Unknown judgment ID at row '.($n + 2));
            }
            if ($r['relevance'] === '') {
                continue;
            } // Blank is unjudged, never grade zero.
            if (! preg_match('/^[0-3]$/D', (string) $r['relevance']) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D', $r['annotator_id'])) {
                throw new RuntimeException('Invalid grade or annotator pseudonym at row '.($n + 2));
            }
            $key = $r['query_id'].'|'.$r['dataset_item_id'].'|'.$r['annotator_id'];
            if (isset($seen[$key])) {
                throw new RuntimeException('Duplicate annotator/query/item judgment');
            } $seen[$key] = true;
            $timestamp = $r['judged_at'] ?? '';
            if (! $assignTime && $timestamp === '') {
                throw new RuntimeException('Stored judgment lacks judged_at');
            }
            if ($timestamp !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $timestamp) || strtotime($timestamp) === false)) {
                throw new RuntimeException('Invalid judged_at timestamp');
            }
            if ($timestamp !== '') {
                new \DateTimeImmutable($timestamp);
                $dateErrors = \DateTimeImmutable::getLastErrors();
                if ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) {
                    throw new RuntimeException('Invalid calendar date in judged_at');
                }
            }
            $clean[] = ['query_id' => $r['query_id'], 'dataset_item_id' => $r['dataset_item_id'], 'relevance' => (int) $r['relevance'], 'annotator_id' => $r['annotator_id'], 'judged_at' => $timestamp ?: now()->utc()->toISOString()];
        }

        return $clean;
    }

    public function import(string $draft, string $input): array
    {
        $items = $this->files->lines($draft.'/artworks.jsonl');
        $queries = $this->files->lines($draft.'/queries.jsonl');
        $incoming = $this->validateRows($this->files->csvRows($input), $queries, $items);
        $existing = $this->validateRows($this->files->csvRows($draft.'/judgments.csv'), $queries, $items, false);
        $context = $this->files->object($draft.'/judgment-context.json');
        if ($context['corpus_sha256'] !== hash_file('sha256', $draft.'/artworks.jsonl')) {
            throw new RuntimeException('Judgment corpus changed');
        }
        $byQuery = array_column($queries, null, 'query_id');
        foreach ($existing as $r) {
            if (($context['queries'][$r['query_id']] ?? '') !== $this->queryHash($byQuery[$r['query_id']])) {
                throw new RuntimeException('Query changed after annotation; refresh/archive the draft');
            }
        }
        $indexed = [];
        $added = 0;
        $skipped = 0;
        foreach ($existing as $r) {
            $indexed[$r['query_id'].'|'.$r['dataset_item_id'].'|'.$r['annotator_id']] = $r;
        }
        foreach ($incoming as $r) {
            $key = $r['query_id'].'|'.$r['dataset_item_id'].'|'.$r['annotator_id'];
            if (isset($indexed[$key])) {
                if ($indexed[$key]['relevance'] !== $r['relevance']) {
                    throw new RuntimeException('Conflicting existing judgment; no rows imported');
                }
                $skipped++;

                continue;
            }
            $indexed[$key] = $r;
            $context['queries'][$r['query_id']] = $this->queryHash($byQuery[$r['query_id']]);
            $added++;
        }
        ksort($indexed, SORT_STRING);
        $this->files->write($draft.'/judgment-context.json', $this->files->json($context));
        $this->files->csv($draft.'/judgments.csv', self::HEADER, array_values($indexed));

        return ['added' => $added, 'existing' => $skipped, 'input_sha256' => hash_file('sha256', $input), 'imported_at' => now()->utc()->toISOString()];
    }

    public function export(string $draft): void
    {
        $items = $this->files->lines($draft.'/artworks.jsonl');
        $queries = $this->files->lines($draft.'/queries.jsonl');
        $pairs = $this->files->lines($draft.'/pool.jsonl');
        $this->files->csv($draft.'/annotation-template.csv', self::HEADER, $pairs);
        $escape = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows = [];
        $html = '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Research annotation</title><style>body{font:16px system-ui;max-width:1200px;margin:32px auto;padding:16px}table{border-collapse:collapse;width:100%}td,th{padding:12px;border:1px solid #ccc;text-align:left}img{max-width:180px;max-height:140px}code{overflow-wrap:anywhere}</style><h1>Ручная разметка — черновик</h1><p>Оценки: 0–3. Пустая пара — unjudged. Заполняйте копию annotation-template.csv; автоматических оценок нет.</p><h2>Запросы</h2><ul>';
        foreach ($queries as $q) {
            $html .= '<li><code>'.$escape($q['query_id']).'</code> ['.$escape($q['split']).'] '.$escape($q['text']).'</li>';
        }
        $html .= '</ul><h2>Корпус</h2><table><tr><th>ID / название</th><th>Превью</th><th>Происхождение</th></tr>';
        foreach ($items as $i) {
            $url = url($i['media']['preview_url']);
            $cell = fn ($s) => preg_match('/^[=+@-]/', (string) $s) ? "'".$s : $s;
            $rows[] = ['dataset_item_id' => $i['dataset_item_id'], 'title' => $cell($i['title']), 'creator' => $cell($i['creator'] ?? ''), 'category' => $i['category'], 'preview_url' => $url, 'source_url' => $i['source_url'], 'license' => $i['license']];
            $html .= '<tr><td><code>'.$escape($i['dataset_item_id']).'</code><p>'.$escape($i['title']).'</p></td><td><img loading="lazy" src="'.$escape($url).'" alt="'.$escape($i['title']).'"></td><td>'.$escape($i['creator'] ?: 'Автор неизвестен').'<p><a rel="noreferrer" href="'.$escape($i['source_url']).'">Источник</a> '.$escape($i['license']).'</p></td></tr>';
        }
        $this->files->write($draft.'/annotation.html', $html.'</table></html>');
        $this->files->csv($draft.'/artworks.csv', ['dataset_item_id', 'title', 'creator', 'category', 'preview_url', 'source_url', 'license'], $rows);
    }

    public function summary(array $rows, array $queries, array $pool, int $itemCount): array
    {
        $byPair = [];
        foreach ($rows as $r) {
            $byPair[$r['query_id'].'|'.$r['dataset_item_id']][] = $r;
        }
        $result = [];
        $conflicts = 0;
        $judged = 0;
        foreach ($queries as $q) {
            $pairs = array_filter($pool, fn ($p) => $p['query_id'] === $q['query_id']);
            $n = 0;
            $relevant = 0;
            foreach ($pairs as $p) {
                $grades = $byPair[$p['query_id'].'|'.$p['dataset_item_id']] ?? [];
                if (count($grades) < config('research_dataset.minimum_annotators')) {
                    continue;
                }
                $adjudication = array_values(array_filter($grades, fn ($r) => $r['annotator_id'] === config('research_dataset.adjudicator_id')));
                $unique = array_unique(array_column($grades, 'relevance'));
                if (count($unique) > 1 && ! $adjudication) {
                    $conflicts++;

                    continue;
                }
                $grade = $adjudication ? $adjudication[0]['relevance'] : reset($unique);
                $n++;
                if ($grade >= config('research_dataset.relevant_grade')) {
                    $relevant++;
                }
            }
            $judged += $n;
            $result[$q['query_id']] = ['pool_size' => count($pairs), 'judged' => $n, 'relevant' => $relevant, 'sufficient' => $n >= config('research_dataset.minimum_judged_per_query') && $relevant >= config('research_dataset.minimum_relevant_per_query') && count($pairs) > 0 && $n / count($pairs) >= config('research_dataset.minimum_pool_coverage')];
        }

        return ['per_query' => $result, 'sufficient_queries' => count(array_filter($result, fn ($r) => $r['sufficient'])), 'conflicting_pairs' => $conflicts, 'judged_pool_pairs' => $judged, 'pool_pairs' => count($pool), 'unjudged_corpus_pairs' => count($queries) * $itemCount - count($byPair)];
    }

    public function queryHash(array $query): string
    {
        return $this->files->hash(array_intersect_key($query, array_flip(['query_id', 'intent_id', 'text', 'language', 'split'])));
    }
}
