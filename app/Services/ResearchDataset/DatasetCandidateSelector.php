<?php

namespace App\Services\ResearchDataset;

use App\Models\ArtworkImport;
use App\Services\DemoContent\ContentItem;
use App\Services\DemoContent\DatasetIntegrity;
use App\Services\DemoContent\SourceRegistry;
use Illuminate\Support\Facades\File;

class DatasetCandidateSelector
{
    public function __construct(private DatasetMetadataExporter $exporter, private DatasetFiles $files) {}

    public function select(string $preset, bool $videos = false): array
    {
        if (! config('demo_content.presets.'.$preset)) {
            throw new \RuntimeException('Unknown source preset');
        }
        $items = [];
        $issues = [];
        $warnings = [];
        $hashes = [];
        $records = ArtworkImport::where('is_demo', true)->where(function ($q) use ($preset) {
            $q->where('metadata->dataset_preset', $preset);
            if ($preset === 'balanced') {
                $q->orWhereNull('metadata->dataset_preset');
            }
        })
            ->with(['artwork.media', 'artwork.tags', 'artwork.user'])->orderBy('source')->orderBy('external_id')->get();
        foreach ($records as $p) {
            $a = $p->artwork;
            $id = $p->source.':'.$p->external_id;
            if (! $a) {
                $issues[] = ['item' => $id, 'code' => 'missing_publication'];

                continue;
            }
            if (! $videos && $a->type !== 'image') {
                continue;
            }
            if (! $a->is_published || $a->is_private || $a->is_blocked || ! $a->published_at || ! $a->user || $a->user->is_blocked) {
                $warnings[] = ['item' => $id, 'code' => 'not_public'];

                continue;
            }
            try {
                $item = $this->exporter->export($p);
                $error = $this->problem($item) ?: app(DatasetIntegrity::class)->inspect($p);
                if ($error) {
                    $issues[] = ['item' => $id, 'code' => $error];

                    continue;
                }
                if (isset($hashes[$item['media_sha256']])) {
                    $warnings[] = ['item' => $id, 'code' => 'duplicate_hash', 'canonical' => $hashes[$item['media_sha256']]];

                    continue;
                }
                $hashes[$item['media_sha256']] = $id;
                foreach (['creation_date', 'medium', 'language'] as $field) {
                    if (! $item['source_metadata'][$field]) {
                        $warnings[] = ['item' => $id, 'code' => 'unknown_'.$field];
                    }
                }
                if ($item['tags_origin'] !== 'stored_source_metadata') {
                    $warnings[] = ['item' => $id, 'code' => 'raw_tags_unavailable'];
                }
                $items[] = $item;
            } catch (\Throwable) {
                $issues[] = ['item' => $id, 'code' => 'invalid_media_reference'];
            }
        }

        return ['items' => $items, 'audit' => [...$this->catalog($preset, $items, $videos), 'issues' => $issues, 'warnings' => $warnings, 'candidate_count' => count($records), 'eligible_count' => count($items), 'snapshot_sha256' => $this->files->hash($items)]];
    }

    public function problem(array $i): ?string
    {
        if (! in_array($i['source'] ?? '', array_keys(config('demo_content.sources')), true) || ! preg_match('/^[a-f0-9]{64}$/D', $i['media_sha256'] ?? '')) {
            return 'invalid_source_or_hash';
        }
        if (! in_array($i['media_type'] ?? '', ['image', 'video'], true)) {
            return 'unsupported_media_type';
        }
        if ($i['media_type'] === 'image' && (($i['media']['width'] ?? 0) <= 0 || ($i['media']['height'] ?? 0) <= 0 || ($i['image_sha256'] ?? '') !== $i['media_sha256'])) {
            return 'invalid_original_image';
        }
        if ($i['media_type'] === 'video' && (! ($i['media']['video']['preview_sha256'] ?? null) || ($i['media']['duration'] ?? 0) <= 0 || (string) ($i['media']['video']['preview_source_media_id'] ?? '') !== (string) $i['media']['id'])) {
            return 'invalid_video_preview';
        }
        if (! ($i['title'] ?? '') || ! ($i['description'] ?? '') || ! ($i['category'] ?? '') || ! ($i['tags'] ?? []) || ! ($i['source_metadata']['raw'] ?? [])) {
            return 'missing_metadata';
        }
        if (! $this->files->publicLink($i['source_url'] ?? null) || ! $this->files->publicLink($i['license_url'] ?? null) || ! $this->files->publicLink($i['source_media_url'] ?? null)) {
            return 'unsafe_provenance_link';
        }
        $creatorUrl = $i['source_metadata']['creator_url'] ?? null;
        if ($creatorUrl && ! $this->files->publicLink($creatorUrl)) {
            return 'unsafe_creator_link';
        }
        $c = new ContentItem($i['source'], $i['external_id'], $i['media_type'], $i['source_url'], $i['source_media_url'], $i['title'], $i['creator'], $i['license'], $i['license_url'], attribution_text: $i['attribution_text']);

        return app(SourceRegistry::class)->allowed($c) ? null : 'license';
    }

    public function catalog(string $preset, array $items, bool $videos = false): array
    {
        $manifests = [];
        $shortfall = [];
        $byArtwork = array_column($items, null, 'artwork_id');
        foreach (File::glob(config('demo_content.root').'/manifests/*.json') as $file) {
            $bytes = File::get($file);
            $m = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            if (($m['preset'] ?? 'balanced') !== $preset) {
                continue;
            }
            $name = basename($file);
            $manifests[$name] = hash('sha256', $bytes);
            $members = ArtworkImport::forDemoDataset((int) $m['seed'], $preset)->pluck('artwork_id')->all();
            $counts = [];
            foreach ($members as $id) {
                if (isset($byArtwork[$id])) {
                    $i = $byArtwork[$id];
                    $key = $i['media_type'] === 'image' ? $i['category'] : 'video';
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
            foreach ($m['quotas'] ?? [] as $category => $target) {
                if (($counts[$category] ?? 0) < $target) {
                    $shortfall[$name.':'.$category] = $target - ($counts[$category] ?? 0);
                }
            }
            if ($videos && ($counts['video'] ?? 0) < ($m['targets']['video'] ?? 0)) {
                $shortfall[$name.':video'] = $m['targets']['video'] - ($counts['video'] ?? 0);
            }
        }
        $running = false;
        $lockPath = config('demo_content.root').'/populate.lock';
        if (is_file($lockPath) && ($lock = fopen($lockPath, 'r'))) {
            $running = ! flock($lock, LOCK_SH | LOCK_NB);
            if (! $running) {
                flock($lock, LOCK_UN);
            } fclose($lock);
        }

        return ['preset' => $preset, 'source_manifests' => $manifests, 'shortfall' => $shortfall, 'import_running' => $running, 'catalog_complete' => (bool) $manifests && ! $shortfall && ! $running];
    }
}
