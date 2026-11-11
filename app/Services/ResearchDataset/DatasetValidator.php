<?php

namespace App\Services\ResearchDataset;

use App\Models\ArtworkImport;
use FFMpeg\FFProbe;

class DatasetValidator
{
    public function __construct(private DatasetFiles $files, private DatasetMetadataExporter $exporter) {}

    public function validate(string $name, ?string $version = null, bool $offline = false): array
    {
        $errors = [];
        $warnings = [];
        $blockers = [];
        $path = $version ? $this->files->version($name, $version) : $this->files->directory($name).'/draft';
        try {
            if ($version) {
                app(DatasetVersionManager::class)->readVersion($name, $version);
            }
            $manifest = $this->files->object($path.'/manifest.json');
            $items = $this->files->lines($path.'/artworks.jsonl');
            $queries = $this->files->lines($path.'/queries.jsonl');
            $pool = $this->files->lines($path.'/pool.jsonl');
            $policy = $version ? $manifest['validation_policy'] : array_replace($manifest['validation_policy'], array_intersect_key(config('research_dataset'), $manifest['validation_policy']));
            if ($manifest['corpus_sha256'] !== hash_file('sha256', $path.'/artworks.jsonl')) {
                $errors[] = 'corpus_snapshot_changed';
            }
            if ($manifest['item_ids'] !== array_column($items, 'dataset_item_id') || $manifest['actual_size'] !== count($items)) {
                $errors[] = 'corpus_membership_changed';
            }
            $ids = [];
            $hashes = [];
            $sources = [];
            $categories = [];
            foreach ($items as $item) {
                $id = $item['dataset_item_id'];
                if ($id !== $item['source'].':'.$item['external_id'] || isset($ids[$id])) {
                    $errors[] = 'duplicate_or_invalid_id:'.$id;
                } $ids[$id] = true;
                if (isset($hashes[$item['media_sha256']])) {
                    $errors[] = 'duplicate_sha256:'.$id;
                } $hashes[$item['media_sha256']] = true;
                if ($problem = app(DatasetCandidateSelector::class)->problem($item)) {
                    $errors[] = $problem.':'.$id;
                }
                $sources[$item['source']] = ($sources[$item['source']] ?? 0) + 1;
                $categories[$item['category']] = ($categories[$item['category']] ?? 0) + 1;
                foreach (['creation_date', 'medium', 'language'] as $field) {
                    if (! $item['source_metadata'][$field]) {
                        $warnings[] = 'unknown_'.$field.':'.$id;
                    }
                }
                if ($item['tags_origin'] !== 'stored_source_metadata') {
                    $warnings[] = 'raw_tags_unavailable:'.$id;
                }
                try {
                    if ($item['media']['preview_url'] !== '/storage/'.$item['media']['thumbnail_path']) {
                        $errors[] = 'unauthorized_preview_url:'.$id;
                    }
                    foreach (['original_path' => 'media_sha256', 'thumbnail_path' => null] as $field => $hashField) {
                        $file = $this->exporter->path($item['media'], $field);
                        $expected = $hashField ? $item[$hashField] : $item['media']['thumbnail_sha256'];
                        if (! is_file($file) || ! is_string($expected) || ! hash_equals($expected, hash_file('sha256', $file))) {
                            $errors[] = 'missing_or_changed_'.$field.':'.$id;

                            continue;
                        }
                        if (($field === 'thumbnail_path' || $item['media_type'] === 'image') && ! @getimagesize($file)) {
                            $errors[] = 'invalid_image:'.$id;
                        }
                    }
                    if ($item['media_type'] === 'video') {
                        $video = $item['media']['video'];
                        $previewPath = $this->exporter->path([...$item['media'], ...($video ?? [])], 'preview_path');
                        if (! is_file($previewPath) || ($video['preview_sha256'] ?? '') !== hash_file('sha256', $previewPath)) {
                            $errors[] = 'changed_video_preview:'.$id;
                        }
                        $probe = FFProbe::create(['ffprobe.binaries' => config('media-library.ffprobe_path'), 'timeout' => 30]);
                        foreach ([$this->exporter->path($item['media'], 'original_path'), $previewPath] as $videoPath) {
                            if ((float) $probe->format($videoPath)->get('duration') <= 0 || ! $probe->streams($videoPath)->videos()->first()) {
                                $errors[] = 'invalid_video_stream:'.$id;
                            }
                        }
                    }
                } catch (\Throwable) {
                    $errors[] = 'unauthorized_media_reference:'.$id;
                }
                if (! $offline) {
                    $record = ArtworkImport::with(['artwork.media', 'artwork.tags', 'artwork.user'])->where('source', $item['source'])->where('external_id', $item['external_id'])->first();
                    try {
                        if (! $record || $this->files->hash($this->exporter->export($record)) !== $this->files->hash($item) || $record->artwork->user?->is_blocked) {
                            $errors[] = 'live_metadata_drift:'.$id;
                        }
                    } catch (\Throwable) {
                        $errors[] = 'live_record_missing:'.$id;
                    }
                }
            }
            $queryIds = [];
            $intents = [];
            $membership = array_column($manifest['query_membership'], null, 'query_id');
            $queryTypes = [];
            $splits = [];
            foreach ($queries as $q) {
                $qid = $q['query_id'] ?? '';
                if (! preg_match('/^[a-zA-Z0-9_.-]{1,64}$/D', $qid) || isset($queryIds[$qid]) || ! is_string($q['text'] ?? null) || trim($q['text']) === '' || mb_strlen($q['text']) > 1000 || ! preg_match('/^[a-zA-Z0-9_.-]{1,64}$/D', $q['intent_id'] ?? '') || ! in_array($q['type'] ?? '', ['simple', 'thematic', 'complex', 'descriptive', 'cross_lingual'], true) || ! in_array($q['language'] ?? '', ['ru', 'en'], true) || ! in_array($q['split'] ?? '', ['development', 'evaluation'], true)) {
                    $errors[] = 'invalid_query:'.$qid;

                    continue;
                }
                $queryIds[$qid] = true;
                if (! isset($membership[$qid]) || $membership[$qid]['intent_id'] !== $q['intent_id'] || $membership[$qid]['split'] !== $q['split']) {
                    $errors[] = 'query_partition_changed:'.$qid;
                }
                if (isset($intents[$q['intent_id']]) && $intents[$q['intent_id']] !== $q['split']) {
                    $errors[] = 'intent_leakage:'.$q['intent_id'];
                }
                $intents[$q['intent_id']] = $q['split'];
                $splits[$q['split']] = ($splits[$q['split']] ?? 0) + 1;
                $queryTypes[$q['type']] = ($queryTypes[$q['type']] ?? 0) + 1;
                if (($q['reviewed'] ?? false) !== true) {
                    $blockers[] = 'query_needs_human_review:'.$qid;
                }
            }
            if (count($queries) !== count($membership)) {
                $errors[] = 'query_membership_changed';
            }
            $pairs = [];
            foreach ($pool as $p) {
                $key = $p['query_id'].'|'.$p['dataset_item_id'];
                if (! isset($queryIds[$p['query_id']], $ids[$p['dataset_item_id']]) || isset($pairs[$key])) {
                    $errors[] = 'invalid_pool_pair:'.$key;
                } $pairs[$key] = true;
            }
            $rows = app(RelevanceJudgmentService::class)->validateRows($this->files->csvRows($path.'/judgments.csv'), $queries, $items, false);
            $context = $this->files->object($path.'/judgment-context.json');
            if ($context['corpus_sha256'] !== $manifest['corpus_sha256']) {
                $errors[] = 'judgment_corpus_changed';
            }
            $byQuery = array_column($queries, null, 'query_id');
            foreach ($rows as $row) {
                if (($context['queries'][$row['query_id']] ?? '') !== app(RelevanceJudgmentService::class)->queryHash($byQuery[$row['query_id']])) {
                    $errors[] = 'judgment_query_changed:'.$row['query_id'];
                }
            }
            // Freeze policy is recorded in the version; do not let later config change its meaning.
            $oldPolicy = config('research_dataset');
            config(['research_dataset' => array_replace($oldPolicy, $policy)]);
            try {
                $judgments = app(RelevanceJudgmentService::class)->summary($rows, $queries, $pool, count($items));
            } finally {
                config(['research_dataset' => $oldPolicy]);
            }
            if ($judgments['sufficient_queries'] !== count($queries) || ! $queries) {
                $blockers[] = 'incomplete_independent_judgments';
            }
            if ($judgments['conflicting_pairs']) {
                $blockers[] = 'unresolved_annotator_disagreements';
            }
            if (count(array_filter($items, fn ($i) => $i['media_type'] === 'image')) < $policy['minimum_images']) {
                $blockers[] = 'insufficient_images_for_research';
            }
            if (count($queries) < $policy['minimum_queries'] || count($queries) > $policy['maximum_queries']) {
                $blockers[] = 'query_count_outside_policy';
            }
            if (count($intents) > 1 && (! isset($splits['development'], $splits['evaluation']))) {
                $errors[] = 'missing_query_partition';
            }
            if ($manifest['selection']['size_shortfall']) {
                $blockers[] = 'requested_corpus_size_not_reached';
            }
            if ($manifest['selection']['category_shortfall']) {
                $warnings[] = 'underrepresented_categories';
            }
            if (count($sources) < 2 || ($items && max($sources) / count($items) > .65)) {
                $warnings[] = 'source_concentration';
            }
            if ($policy['require_complete_catalog'] && (! $manifest['catalog_audit']['catalog_complete'] || $manifest['catalog_audit']['issues'])) {
                $blockers[] = 'source_catalog_incomplete_at_preparation';
            }
            if (! $version && ! $offline) {
                $current = app(DatasetCandidateSelector::class)->select($manifest['preset'], $manifest['include_videos']);
                if ($policy['require_complete_catalog'] && (! $current['audit']['catalog_complete'] || $current['audit']['issues'])) {
                    $blockers[] = 'source_catalog_currently_incomplete';
                }
            }

            return ['checked_at' => now()->utc()->toISOString(), 'name' => $name, 'version' => $version, 'offline' => $offline, 'structurally_valid' => ! $errors, 'ready_to_freeze' => ! $errors && ! $blockers, 'errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings)), 'blockers' => array_values(array_unique($blockers)), 'policy' => $policy,
                'counts' => ['items' => count($items), 'queries' => count($queries), 'judgments' => count($rows)], 'sources' => $sources, 'categories' => $categories, 'query_types' => $queryTypes, 'query_splits' => $splits, 'annotation' => $judgments];
        } catch (\Throwable $e) {
            return ['checked_at' => now()->utc()->toISOString(), 'name' => $name, 'version' => $version, 'structurally_valid' => false, 'ready_to_freeze' => false, 'errors' => ['invalid_dataset_artifact: '.$e->getMessage()], 'warnings' => $warnings, 'blockers' => $blockers];
        }
    }
}
