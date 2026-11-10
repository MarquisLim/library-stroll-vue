<?php

namespace App\Console\Commands;

use App\Services\ArtworkImport\ArtworkImageDownloader;
use App\Services\DemoContent\Manifest;
use App\Services\DemoContent\SourceHttp;
use App\Services\DemoContent\SourceRegistry;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;

class DemoSourcesCheckCommand extends Command
{
    protected $signature = 'demo:sources-check {--media} {--source=}';

    protected $description = 'Read-only API/CDN diagnostics without catalog or AI writes';

    public function handle(SourceRegistry $registry, SourceHttp $http): int
    {
        if (! app()->environment(['local', 'testing'])) {
            return self::FAILURE;
        }
        $http->reset();
        $rows = [];
        $report = [];
        $failed = false;
        $selected = $this->option('source') ? explode(',', $this->option('source')) : array_keys(config('demo_content.sources'));
        if (array_diff($selected, array_keys(config('demo_content.sources')))) {
            return self::INVALID;
        }
        foreach ($selected as $source) {
            $c = config("demo_content.sources.$source");
            $r = ['source' => $source, 'api' => 'not checked', 'media' => 'not checked', 'status' => 'Disabled', 'key' => $source === 'smithsonian' ? ($c['api_key'] ? 'set' : 'missing') : 'not required'];
            if ($c['enabled'] && ($source !== 'smithsonian' || $c['api_key'])) {
                $limits = ["demo_content.sources.$source.max_pages" => $c['max_pages'], "demo_content.sources.$source.page_size" => $c['page_size'] ?? 25];
                config(["demo_content.sources.$source.max_pages" => config('demo_content.diagnostic_max_pages', 1), "demo_content.sources.$source.page_size" => config('demo_content.diagnostic_page_size', 10)]);
                try {
                    $eligible = null;
                    $n = 0;
                    foreach ($registry->get($source)->candidates(config("demo_content.diagnostic_queries.$source", 'landscape'), 'image') as $item) {
                        if ($registry->allowed($item) && $item->original_tags && $item->source_url && $item->license_url && $item->media_url) {
                            $eligible = $item;
                            break;
                        }
                        if (++$n >= config('demo_content.diagnostic_candidates', 5)) {
                            break;
                        }
                    }
                    $r['api'] = 'OK';
                    $r['status'] = $eligible ? 'API checked; media not tested' : 'No eligible metadata';
                    if ($eligible && $this->option('media')) {
                        try {
                            $path = app(ArtworkImageDownloader::class)->download($eligible->media_url, $source);
                            try {
                                $r['media'] = 'OK';
                                $r['status'] = 'Ready';
                                $r['sample'] = ['external_id' => $eligible->external_id, 'source_url' => $eligible->source_url, 'license' => $eligible->license];
                            } finally {
                                @unlink($path);
                            }
                        } catch (\Throwable $e) {
                            $r['media'] = $e instanceof RequestException ? (string) $e->response->status() : 'validation/network';
                            $r['status'] = 'Media unavailable';
                            $r['error'] = get_class($e);
                            $failed = true;
                        }
                    } elseif (! $eligible) {
                        $failed = true;
                    }
                } catch (\Throwable $e) {
                    $r['api'] = $e instanceof RequestException ? (string) $e->response->status() : 'network/response';
                    $r['status'] = 'Unavailable';
                    $r['error'] = get_class($e);
                    $failed = true;
                } finally {
                    config($limits);
                }
            } elseif ($c['enabled']) {
                $r['status'] = 'Missing key; skipped';
            }
            $report[] = $r;
            $rows[] = [$source, $r['key'], $r['api'], $r['media'], $r['status']];
        }
        $this->table(['Source', 'Key', 'API', 'Media', 'Status'], $rows);
        $path = config('demo_content.root').'/reports/sources-'.now()->format('Ymd-His-u').'.json';
        app(Manifest::class)->save($path, ['checked_at' => now()->toISOString(), 'sources' => $report, 'stats' => $http->metrics]);
        $this->line($path);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
