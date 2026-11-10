<?php

namespace App\Console\Commands;

use App\Models\ArtworkImport;
use App\Models\Collection;
use App\Services\DemoContent\ContentItem;
use App\Services\DemoContent\DemoImporter;
use App\Services\DemoContent\DemoProfiles;
use App\Services\DemoContent\Manifest;
use App\Services\DemoContent\SourceRegistry;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;

class DemoPopulateCommand extends Command
{
    protected $signature = 'demo:populate {--preset=balanced} {--images=600} {--videos=20} {--authors=45} {--source=} {--seed=42} {--batch=25} {--dry-run} {--resume} {--verify}';

    protected $description = 'Populate a reproducible licensed demo catalog without AI processing';

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
            $process = function (array $entry, string $key) use (&$state, &$failures, &$paused, $users, $seed, $importer, $manifests, $path, $categories) {
                $source = $entry['item']['source'];
                if (! in_array($source, $state['sources'], true) || isset($paused[$source])) {
                    return;
                }
                $state['entries'][$key] = $entry;
                $state['entries'][$key]['status'] = 'processing';
                $manifests->save($path, $state);
                try {
                    $item = new ContentItem(...$entry['item']);
                    $accepted = array_filter($state['entries'], fn ($e) => $e['status'] === 'imported' && $e['category'] === $entry['category'] && $e['item']['media_type'] === $item->media_type);
                    $target = $item->media_type === 'image' ? $state['quotas'][$entry['category']] : max(1, (int) ceil($state['targets']['video'] / count($categories)));
                    $creatorCount = count(array_filter($accepted, fn ($e) => mb_strtolower($e['item']['creator_name'] ?? '') === mb_strtolower($item->creator_name ?? '')));
                    $sourceCount = count(array_filter($accepted, fn ($e) => $e['item']['source'] === $source));
                    $providers = array_filter(array_intersect($categories[$entry['category']]['sources'], $state['sources']), fn ($s) => in_array($item->media_type, config("demo_content.sources.$s.supported_media_types")));
                    if (app(SourceRegistry::class)->allowed($item) && ($creatorCount >= max(2, (int) ceil($target * config('demo_content.max_creator_share'))) || (count($providers) > 1 && $sourceCount >= max(1, (int) ceil($target * config('demo_content.max_source_share')))))) {
                        $state['entries'][$key] = [...$entry, 'status' => 'diversity'];
                        $manifests->save($path, $state);

                        return;
                    }
                    $index = array_search($entry['category'], array_keys($categories));
                    $owners = array_values(array_filter($users, fn ($u, $i) => $i % count($categories) === $index, ARRAY_FILTER_USE_BOTH));
                    $owner = ($owners ?: $users)[hexdec(substr(hash('sha256', "$seed:$key"), 0, 6)) % count($owners ?: $users)];
                    $result = $importer->import($item, $owner, $entry['category'], $seed);
                    $existing = ArtworkImport::where('source', $item->source)->where('external_id', $item->external_id)->first();
                    if ($result['status'] === 'existing' && $existing?->is_demo && ($existing->metadata['dataset_seed'] ?? null) === $seed) {
                        $result = ['status' => 'imported', 'artwork_id' => $existing->artwork_id];
                    }
                    $state['entries'][$key] = [...$entry, ...$result];
                    $failures[$source] = 0;
                } catch (\Throwable $e) {
                    $state['entries'][$key] = [...$entry, 'status' => 'media_error', 'error' => get_class($e), 'http_status' => $e instanceof RequestException ? $e->response->status() : null];
                    if (($failures[$source] = ($failures[$source] ?? 0) + 1) >= config('demo_content.failure_threshold')) {
                        $paused[$source] = true;
                    }
                }
                $manifests->save($path, $state);
            };
            if ($this->option('resume')) {
                foreach ($state['entries'] as $key => $entry) {
                    $type = $entry['item']['media_type'];
                    $done = count(array_filter($state['entries'], fn ($e) => $e['status'] === 'imported' && $e['item']['media_type'] === $type));
                    if ($done < $state['targets'][$type] && in_array($entry['status'], ['processing', 'media_error'])) {
                        $process($entry, $key);
                    }
                }
            }
            $count = function ($type, $category = null) use (&$state) {
                return count(array_filter($state['entries'], fn ($e) => $e['status'] === 'imported' && $e['item']['media_type'] === $type && (! $category || $e['category'] === $category)));
            };
            foreach (['image', 'video'] as $type) {
                foreach ($categories as $category => $theme) {
                    if ($count($type) >= $state['targets'][$type]) {
                        break;
                    }
                    $target = $type === 'image' ? $quotas[$category] : max(1, (int) ceil($state['targets']['video'] / count($categories)));
                    $preferred = array_values(array_intersect($theme['sources'], $sources));
                    usort($preferred, fn ($a, $b) => (crc32("$seed:$category:$a") / config("demo_content.sources.$a.weight")) <=> (crc32("$seed:$category:$b") / config("demo_content.sources.$b.weight")));
                    foreach ($preferred as $source) {
                        if (isset($paused[$source]) || ! in_array($type, config("demo_content.sources.$source.supported_media_types"))) {
                            continue;
                        }
                        foreach ($theme['queries'] as $query) {
                            if ($count($type, $category) >= $target || $count($type) >= $state['targets'][$type]) {
                                break;
                            }
                            try {
                                foreach ($registry->get($source)->candidates($query, $type) as $item) {
                                    if (isset($paused[$source]) || $count($type, $category) >= $target || $count($type) >= $state['targets'][$type]) {
                                        break;
                                    }
                                    $key = $item->source.':'.$item->external_id;
                                    if (isset($state['entries'][$key])) {
                                        continue;
                                    }
                                    $text = mb_strtolower($item->original_title.' '.implode(' ', $item->original_tags));
                                    $relevant = false;
                                    foreach ($theme['queries'] as $term) {
                                        if (str_contains($text, mb_strtolower($term))) {
                                            $relevant = true;
                                        }
                                    }
                                    if (! $relevant) {
                                        continue;
                                    }
                                    $process(['item' => (array) $item, 'category' => $category, 'query' => $query, 'status' => 'pending'], $key);
                                    if (++$checked % (int) $this->option('batch') === 0) {
                                        $this->line("Обработано кандидатов: $checked");
                                    }
                                }
                            } catch (\Throwable $e) {
                                $state['api_errors'][] = ['source' => $source, 'category' => $category, 'error' => get_class($e), 'http_status' => $e instanceof RequestException ? $e->response->status() : null];
                                $paused[$source] = true;
                                $manifests->save($path, $state);
                                break;
                            }
                        }
                    }
                }
            }
            $collectionIds = $state['collections'] ?? [];
            foreach ([false, true] as $selection) {
                foreach ($categories as $category => $theme) {
                    $ids = ArtworkImport::where('is_demo', true)->where('primary_category', $category)->where('metadata->dataset_seed', $seed)->pluck('artwork_id')->all();
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

            return array_sum($state['shortfall']) || $state['video_shortfall'] ? self::FAILURE : self::SUCCESS;
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
