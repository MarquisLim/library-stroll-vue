<?php

namespace App\Console\Commands;

use App\Models\ArtworkImport;
use App\Models\Collection;
use App\Services\DemoContent\CandidateRelevanceEvaluator;
use App\Services\DemoContent\ContentItem;
use App\Services\DemoContent\DatasetIntegrity;
use App\Services\DemoContent\DemoImporter;
use App\Services\DemoContent\DemoProfiles;
use App\Services\DemoContent\Manifest;
use App\Services\DemoContent\SourceHttp;
use App\Services\DemoContent\SourceRegistry;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;

class DemoPopulateCommand extends Command
{
    protected $signature = 'demo:populate {--preset=balanced} {--images=600} {--videos=20} {--authors=45} {--source=} {--seed=42} {--batch=25} {--dry-run} {--resume} {--verify} {--recheck-skipped} {--repair-media}';

    protected $description = 'Populate a reproducible licensed demo catalog without AI processing';

    private function belongs(ArtworkImport $record, int $seed): bool
    {
        return $record->is_demo && ($record->metadata['dataset_seed'] ?? null) === $seed && ($record->metadata['dataset_preset'] ?? 'balanced') === $this->option('preset');
    }

    public function handle(Manifest $manifests, SourceRegistry $registry, DemoImporter $importer, DemoProfiles $profiles): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Demo content is restricted to local/testing.');

            return self::FAILURE;
        }
        $categories = config('demo_content.presets.'.$this->option('preset'));
        if (! $categories) {
            $this->error('Unknown preset');

            return self::INVALID;
        }
        foreach (['images' => [0, 5000], 'videos' => [0, 100], 'authors' => [1, 100], 'batch' => [1, 100], 'seed' => [0, 2147483647]] as $key => [$min,$max]) {
            $value = filter_var($this->option($key), FILTER_VALIDATE_INT);
            if ($value === false || $value < $min || $value > $max) {
                $this->error("Invalid $key");

                return self::INVALID;
            }
        }
        if ($this->option('verify') && $this->option('dry-run')) {
            $this->error('--verify cannot be combined with --dry-run');

            return self::INVALID;
        }
        if ($this->option('verify')) {
            return $this->call('demo:verify');
        }
        $seed = (int) $this->option('seed');
        $sources = array_keys(config('demo_content.sources'));
        if ($this->option('source')) {
            $sources = explode(',', $this->option('source'));
            if (array_diff($sources, array_keys(config('demo_content.sources')))) {
                $this->error('Unknown source');

                return self::INVALID;
            }
        }
        $sources = array_values(array_filter($sources, fn ($s) => config("demo_content.sources.$s.enabled") && ($s !== 'smithsonian' || config("demo_content.sources.$s.api_key"))));
        if (! $sources) {
            $this->error('No enabled sources with required credentials');

            return self::FAILURE;
        }
        $quotas = $manifests->quotas($categories, (int) $this->option('images'));
        foreach ($sources as $source) {
            config(["demo_content.sources.$source.page_size" => (int) $this->option('batch')]);
        }
        if ($this->option('dry-run')) {
            $this->table(['Категория', 'Изображений', 'Источники'], array_map(fn ($k, $n) => [$categories[$k]['label'], $n, implode(',', array_intersect($sources, $categories[$k]['sources']))], array_keys($quotas), $quotas));
            $this->info('Dry run: no network, database, avatars or files. Videos: '.$this->option('videos'));

            return self::SUCCESS;
        }
        $root = config('demo_content.root');
        File::ensureDirectoryExists($root);
        $lock = fopen($root.'/populate.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Another demo import is running');

            return self::FAILURE;
        }
        $path = $manifests->path($seed, $this->option('preset'));
        $state = is_file($path) ? json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR) : ['seed' => $seed, 'preset' => $this->option('preset'), 'entries' => [], 'api_errors' => []];
        $state['targets'] = ['image' => (int) $this->option('images'), 'video' => (int) $this->option('videos')];
        $state['quotas'] = $quotas;
        $state['sources'] = $sources;
        $paused = [];
        $failures = [];
        $checked = 0;
        try {
            $users = $profiles->prepare((int) $this->option('authors'), $seed, $categories);
            $state['users'] = array_map(fn ($u) => $u->id, $users);
            $manifests->save($path, $state);

            $http = app(SourceHttp::class);
            $http->reset();
            config(['demo_content.active_preset' => $this->option('preset')]);
            $evaluator = app(CandidateRelevanceEvaluator::class);
            $integrity = app(DatasetIntegrity::class);
            $state['run_started_at'] = now()->toISOString();
            $state['run_counters'] = [];
            $previousRules = $state['rules_hash'] ?? null;
            $state['rules_hash'] = hash('sha256', serialize([$categories, config('demo_content.relevance'), $state['targets'], $sources, config('demo_content.max_creator_share'), config('demo_content.max_source_share'), config('demo_content.small_quota_threshold'), array_map(fn ($s) => config("demo_content.sources.$s.allowed_media_hosts"), $sources)]));
            $reconsider = $this->option('recheck-skipped') || ($this->option('resume') && $previousRules !== $state['rules_hash']);
            foreach (ArtworkImport::select('source', 'external_id')->cursor() as $record) {
                $http->known[$record->source.':'.$record->external_id] = true;
            }
            foreach ($state['entries'] as $key => &$entry) {
                $http->known[$key] = true;
                if (! in_array($entry['status'], ['imported', 'processing', 'repair_required'])) {
                    continue;
                }
                $record = ArtworkImport::where('source', $entry['item']['source'])->where('external_id', $entry['item']['external_id'])->first();
                if (! $record) {
                    if ($entry['status'] === 'imported') {
                        $entry['status'] = 'deleted';
                    }

                    continue;
                }
                if (! $this->belongs($record, $seed)) {
                    $entry['status'] = 'existing';

                    continue;
                }
                $problem = $integrity->inspect($record);
                if ($problem && $this->option('repair-media')) {
                    try {
                        $integrity->repair($record, new ContentItem(...$entry['item']));
                        $problem = null;
                    } catch (\Throwable $e) {
                        $entry['repair_error'] = get_class($e);
                    }
                }
                $entry['status'] = $problem ? 'repair_required' : 'imported';
                $entry['problem'] = $problem;
                $entry['artwork_id'] = $record->artwork_id;
            }
            unset($entry);
            $count = function ($type, $category = null) use (&$state) {
                return count(array_filter($state['entries'], fn ($e) => $e['status'] === 'imported' && $e['item']['media_type'] === $type && (! $category || $e['category'] === $category)));
            };
            $checkpoint = function (bool $force = false) use (&$state, &$checked, $http, $manifests, $path) {
                if ($force || $checked % config('demo_content.checkpoint_every', 25) === 0) {
                    $state['source_stats'] = $http->metrics;
                    $manifests->save($path, $state);
                }
            };
            $progress = function ($source, $category) use (&$state, $http, $count) {
                $counts = array_count_values(array_column($state['entries'], 'status'));
                $this->line('Изображения: '.$count('image').' / '.$state['targets']['image'].'; видео: '.$count('video').' / '.$state['targets']['video']);
                $this->line('API кандидатов: '.array_sum(array_column($http->metrics, 'api_candidates')).'; оценено: '.($state['run_counters']['evaluated'] ?? 0).'; подходят по теме: '.($state['run_counters']['relevant'] ?? 0));
                $this->line('Попыток скачивания: '.array_sum(array_column($http->metrics, 'download_attempts')).'; файлов загружено: '.array_sum(array_column($http->metrics, 'downloads_successful')).'; новых публикаций: '.($state['run_counters']['imported'] ?? 0));
                $this->line('Причины/результаты manifest: '.json_encode($counts, JSON_UNESCAPED_UNICODE)."; источник: $source; тема: $category");
            };
            $exhausted = [];
            $sourceFull = function ($source, $category, $type) use (&$state, $categories, &$paused, &$exhausted) {
                $target = $type === 'image' ? ($state['quotas'][$category] ?? 0) : max(1, (int) ceil($state['targets']['video'] / count($categories)));
                if ($target <= config('demo_content.small_quota_threshold', 10)) {
                    return false;
                }
                $providers = array_filter(array_intersect($categories[$category]['sources'], $state['sources']), fn ($s) => ! isset($paused[$s]) && ! isset($exhausted["$s:$category:$type"]) && in_array($type, config("demo_content.sources.$s.supported_media_types")));
                if (count($providers) <= 1) {
                    return false;
                }
                $n = count(array_filter($state['entries'], fn ($e) => $e['status'] === 'imported' && $e['category'] === $category && $e['item']['media_type'] === $type && $e['item']['source'] === $source));

                return $n >= ceil($target * config('demo_content.max_source_share'));
            };
            $process = function (array $entry, string $key) use (&$state, &$checked, &$failures, &$paused, $users, $seed, $importer, $categories, $registry, $evaluator, $checkpoint, $progress, $count, $sourceFull) {
                $source = $entry['item']['source'];
                $category = $entry['category'];
                $item = new ContentItem(...$entry['item']);
                if (! in_array($source, $state['sources'], true) || isset($paused[$source])) {
                    return;
                }
                $target = $item->media_type === 'image' ? ($state['quotas'][$category] ?? 0) : max(1, (int) ceil($state['targets']['video'] / count($categories)));
                if ($count($item->media_type, $category) >= $target || $count($item->media_type) >= $state['targets'][$item->media_type]) {
                    return;
                }
                $record = ArtworkImport::where('source', $source)->where('external_id', $item->external_id)->first();
                if ($record) {
                    $state['entries'][$key] = [...$entry, 'status' => $this->belongs($record, $seed) ? 'imported' : 'existing', 'artwork_id' => $record->artwork_id];
                    $checkpoint(true);

                    return;
                }
                $evaluation = $evaluator->evaluate($item, $category, $categories[$category]);
                $state['run_counters']['evaluated'] = ($state['run_counters']['evaluated'] ?? 0) + 1;
                if ($evaluation['accepted']) {
                    $state['run_counters']['relevant'] = ($state['run_counters']['relevant'] ?? 0) + 1;
                }
                $entry['evaluation'] = $evaluation;
                $entry['rules_hash'] = $state['rules_hash'];
                $checked++;
                $status = null;
                $reason = null;
                if (! $registry->allowed($item)) {
                    $status = 'license';
                    $reason = 'license_not_permitted';
                } elseif (! $evaluation['accepted']) {
                    $status = 'irrelevant';
                    $reason = 'no_metadata_match';
                } elseif (! $item->original_title || ! $item->original_tags || parse_url($item->source_url, PHP_URL_SCHEME) !== 'https' || parse_url($item->license_url ?? '', PHP_URL_SCHEME) !== 'https' || ! $item->media_url) {
                    $status = 'metadata';
                    $reason = 'required_metadata_missing';
                }
                $accepted = array_filter($state['entries'], fn ($e) => $e['status'] === 'imported' && $e['category'] === $category && $e['item']['media_type'] === $item->media_type);
                $creatorCount = $registry->creator($item) ? count(array_filter($accepted, fn ($e) => mb_strtolower($e['item']['creator_name'] ?? '') === mb_strtolower($item->creator_name))) : 0;
                if (! $status && ($sourceFull($source, $category, $item->media_type) || ($target > config('demo_content.small_quota_threshold', 10) && $creatorCount >= max(2, (int) ceil($target * config('demo_content.max_creator_share')))))) {
                    $status = 'diversity';
                    $reason = 'adaptive_share_limit';
                }
                if ($status) {
                    $state['entries'][$key] = [...$entry, 'status' => $status, 'reason' => $reason];
                    $checkpoint();
                } else {
                    $state['entries'][$key] = [...$entry, 'status' => 'processing'];
                    $checkpoint(true);
                    try {
                        $index = array_search($category, array_keys($categories));
                        $owners = array_values(array_filter($users, fn ($u, $i) => $i % count($categories) === $index, ARRAY_FILTER_USE_BOTH));
                        $owner = ($owners ?: $users)[hexdec(substr(hash('sha256', "$seed:$key"), 0, 6)) % count($owners ?: $users)];
                        $result = $importer->import($item, $owner, $category, $seed);
                        $state['entries'][$key] = [...$entry, ...$result];
                        if ($result['status'] === 'imported') {
                            $state['run_counters']['imported'] = ($state['run_counters']['imported'] ?? 0) + 1;
                        }
                        $failures[$source] = 0;
                    } catch (\Throwable $e) {
                        $status = $e instanceof \RuntimeException && str_contains($e->getMessage(), 'Unsafe media URL') ? 'unsafe_media' : 'media_error';
                        $state['entries'][$key] = [...$entry, 'status' => $status, 'error' => get_class($e), 'http_status' => $e instanceof RequestException ? $e->response->status() : null];
                        if ($status === 'media_error' && ($failures[$source] = ($failures[$source] ?? 0) + 1) >= config('demo_content.failure_threshold')) {
                            $paused[$source] = true;
                            $this->warn("$source: media unavailable, HTTP ".($state['entries'][$key]['http_status'] ?? 'network/validation'));
                        }
                    }
                    $checkpoint(true);
                }
                if ($checked % (int) $this->option('batch') === 0) {
                    $progress($source, $category);
                }
            };
            if ($this->option('resume') || $this->option('recheck-skipped')) {
                foreach ($state['entries'] as $key => $entry) {
                    $retry = in_array($entry['status'], ['pending', 'processing', 'media_error']);
                    if ($reconsider) {
                        $retry = $retry || in_array($entry['status'], ['metadata', 'diversity', 'irrelevant', 'unsafe_media']);
                    }
                    if ($retry && isset($categories[$entry['category']])) {
                        $process($entry, $key);
                    }
                }
            }
            $searched = [];
            foreach (['image', 'video'] as $type) {
                foreach ($categories as $category => $theme) {
                    $target = $type === 'image' ? $quotas[$category] : max(1, (int) ceil($state['targets']['video'] / count($categories)));
                    if ($count($type) >= $state['targets'][$type]) {
                        break;
                    }
                    foreach ($state['entries'] as $key => $entry) {
                        if ($entry['status'] === 'irrelevant' && $entry['category'] !== $category && $entry['item']['media_type'] === $type) {
                            if ($evaluator->evaluate(new ContentItem(...$entry['item']), $category, $theme)['accepted']) {
                                $process([...$entry, 'category' => $category], $key);
                            }
                        }
                    }
                    $preferred = array_values(array_intersect($theme['sources'], $sources));
                    usort($preferred, fn ($a, $b) => (crc32("$seed:$category:$a") / config("demo_content.sources.$a.weight")) <=> (crc32("$seed:$category:$b") / config("demo_content.sources.$b.weight")));
                    // On resume, probe alternatives before returning to a source already at its share limit.
                    $preferred = array_merge(array_values(array_filter($preferred, fn ($s) => ! $sourceFull($s, $category, $type))), array_values(array_filter($preferred, fn ($s) => $sourceFull($s, $category, $type))));
                    $deferred = [];
                    foreach ($preferred as $source) {
                        if ($count($type, $category) >= $target || $count($type) >= $state['targets'][$type]) {
                            break;
                        }
                        if (isset($paused[$source]) || ! in_array($type, config("demo_content.sources.$source.supported_media_types")) || $sourceFull($source, $category, $type)) {
                            continue;
                        }
                        foreach ($theme['queries'] as $query) {
                            if ($count($type, $category) >= $target || $count($type) >= $state['targets'][$type] || $sourceFull($source, $category, $type)) {
                                break;
                            }
                            $searchKey = "$source:$type:$query";
                            if (isset($searched[$searchKey])) {
                                continue;
                            }$searched[$searchKey] = true;
                            try {
                                $iterator = $registry->get($source)->candidates($query, $type);
                                // Test quota before advancing the generator: advancing may fetch the next detail/page.
                                $iterator = is_array($iterator) ? new \ArrayIterator($iterator) : ($iterator instanceof \Iterator ? $iterator : new \IteratorIterator($iterator));
                                $iterator->rewind();
                                while ($iterator->valid()) {
                                    $item = $iterator->current();
                                    $key = $item->source.':'.$item->external_id;
                                    if (! isset($state['entries'][$key])) {
                                        $process(['item' => (array) $item, 'category' => $category, 'query' => $query, 'status' => 'pending'], $key);
                                    }
                                    $http->known[$key] = true;
                                    if ($sourceFull($source, $category, $type)) {
                                        $deferred[$source] = [$iterator, $query];
                                        break;
                                    }
                                    if (isset($paused[$source]) || $count($type, $category) >= $target || $count($type) >= $state['targets'][$type]) {
                                        break;
                                    }
                                    $iterator->next();
                                }
                            } catch (\Throwable $e) {
                                $state['api_errors'][] = ['source' => $source, 'category' => $category, 'error' => get_class($e), 'http_status' => $e instanceof RequestException ? $e->response->status() : null];
                                $paused[$source] = true;
                                $this->warn("$source: API paused, HTTP ".($e instanceof RequestException ? $e->response->status() : 'network/response'));
                                $checkpoint(true);
                            }
                        }
                        if (! isset($deferred[$source])) {
                            $exhausted["$source:$category:$type"] = true;
                        }
                    }
                    foreach ($deferred as $source => [$iterator,$query]) {
                        if (isset($paused[$source]) || $sourceFull($source, $category, $type) || $count($type, $category) >= $target || $count($type) >= $state['targets'][$type]) {
                            continue;
                        }
                        try {
                            $iterator->next();
                            while ($iterator->valid()) {
                                $item = $iterator->current();
                                $key = $item->source.':'.$item->external_id;
                                if (! isset($state['entries'][$key])) {
                                    $process(['item' => (array) $item, 'category' => $category, 'query' => $query, 'status' => 'pending'], $key);
                                }
                                $http->known[$key] = true;
                                if (isset($paused[$source]) || $count($type, $category) >= $target || $count($type) >= $state['targets'][$type]) {
                                    break;
                                }
                                $iterator->next();
                            }
                        } catch (\Throwable $e) {
                            $state['api_errors'][] = ['source' => $source, 'category' => $category, 'error' => get_class($e), 'http_status' => $e instanceof RequestException ? $e->response->status() : null];
                            $paused[$source] = true;
                        }
                    }
                }
            }
            $checkpoint(true);
            $progress('complete', 'complete');
            $collectionIds = $state['collections'] ?? [];
            foreach ([false, true] as $selection) {
                foreach ($categories as $category => $theme) {
                    $ids = ArtworkImport::forDemoDataset($seed, $this->option('preset'))->where('primary_category', $category)->pluck('artwork_id')->all();
                    if (! $ids) {
                        continue;
                    }
                    if ($selection && count($ids) < 4) {
                        continue;
                    }
                    if (count($collectionIds) >= 24 && ! isset($collectionIds[$category.':'.(int) $selection])) {
                        continue;
                    }
                    $key = $category.':'.(int) $selection;
                    if (isset($collectionIds[$key])) {
                        continue;
                    } // Never overwrite edited collections.
                    $col = Collection::create(['user_id' => $users[array_search($category, array_keys($categories)) % count($users)]->id, 'name' => $theme['label'].($selection ? ' — избранное' : ''), 'is_private' => false]);
                    $col->artworks()->attach($selection ? array_slice($ids, 0, (int) ceil(count($ids) / 2)) : $ids);
                    $collectionIds[$key] = $col->id;
                    $state['collections'] = $collectionIds;
                    $manifests->save($path, $state);
                }
            }
            $state['source_stats'] = $http->metrics;
            $state['category_stats'] = [];
            foreach ($categories as $cat => $theme) {
                $state['category_stats'][$cat] = ['imported' => $count('image', $cat), 'target' => $quotas[$cat]];
            }
            foreach ($state['entries'] as $entry) {
                $source = $entry['item']['source'];
                $category = $entry['category'];
                $status = $entry['status'];
                $state['source_stats'][$source]['dataset_outcomes'][$status] = ($state['source_stats'][$source]['dataset_outcomes'][$status] ?? 0) + 1;
                $state['category_stats'][$category]['dataset_outcomes'][$status] = ($state['category_stats'][$category]['dataset_outcomes'][$status] ?? 0) + 1;
            }
            $state['run_counters']['network_errors'] = array_sum(array_column($http->metrics, 'network_errors'));
            $state['run_counters']['downloads_successful'] = array_sum(array_column($http->metrics, 'downloads_successful'));
            $state['run_counters']['retry_attempts'] = array_sum(array_column($http->metrics, 'retries')) + array_sum(array_column($http->metrics, 'media_retries'));
            $state['counts'] = array_count_values(array_column($state['entries'], 'status'));
            $state['shortfall'] = [];
            foreach ($quotas as $cat => $n) {
                $state['shortfall'][$cat] = max(0, $n - $count('image', $cat));
            }
            $state['video_shortfall'] = max(0, $state['targets']['video'] - $count('video'));
            $manifests->save($path, $state);
            $report = $root.'/reports/'.$this->option('preset')."-$seed-".now()->format('Ymd-His').'.json';
            $manifests->save($report, $state);
            $this->table(['Результат', 'Количество'], array_map(fn ($k, $n) => [$k, $n], array_keys($state['counts']), $state['counts']));
            $this->info("Manifest: $path\nReport: $report");

            $damaged = count(array_filter($state['entries'], fn ($e) => $e['status'] === 'repair_required'));
            if ($damaged) {
                $this->warn("Повреждено записей: $damaged. Используйте --resume --repair-media или проверьте изменения вручную.");
            }
            if (array_sum($state['shortfall']) || $state['video_shortfall']) {
                $this->warn('Целевое количество пока не достигнуто; причины сохранены в отчёте.');
            }

            return array_sum($state['shortfall']) || $state['video_shortfall'] || $damaged ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $state['fatal_error'] = get_class($e);
            $manifests->save($path, $state);
            $manifests->save($root.'/reports/failure-'.$seed.'-'.now()->format('Ymd-His').'.json', $state);
            $this->error('Import stopped: '.get_class($e).'. Check report and runtime dependencies.');

            return self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
