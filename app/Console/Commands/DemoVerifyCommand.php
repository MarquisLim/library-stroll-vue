<?php

namespace App\Console\Commands;

use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Models\ArtworkImport;
use App\Models\Collection;
use App\Models\User;
use App\Services\DemoContent\ContentItem;
use App\Services\DemoContent\Manifest;
use App\Services\DemoContent\SourceRegistry;
use FFMpeg\FFProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class DemoVerifyCommand extends Command
{
    protected $signature = 'demo:verify';

    protected $description = 'Independently verify demo files, provenance, collections and absence of AI processing';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Restricted to local/testing');

            return self::FAILURE;
        }
        $issues = [];
        $counts = ['profiles' => 0, 'avatars' => 0, 'images' => 0, 'videos' => 0, 'collections' => 0];
        $categories = [];
        $hashes = [];
        foreach (User::withoutGlobalScopes()->where('is_demo', true)->cursor() as $u) {
            $counts['profiles']++;
            $path = $u->profile_photo_path;
            if ($path && Storage::disk('public')->exists($path) && @getimagesize(Storage::disk('public')->path($path))) {
                $counts['avatars']++;
            } else {
                $issues[] = "User $u->id: missing/invalid avatar";
            }
            if (! str_ends_with($u->email, '.test') || ! $u->description) {
                $issues[] = "User $u->id: invalid demo profile";
            }
        }
        foreach (Artwork::withoutGlobalScopes()->where('is_demo', true)->with(['media', 'tags', 'provenance', 'user', 'aiMetadata'])->lazyById(25) as $a) {
            $p = $a->provenance;
            $m = $a->getFirstMedia('artworks');
            $valid = true;
            $check = function (bool $ok, string $message) use (&$issues, &$valid, $a) {
                if (! $ok) {
                    $issues[] = "Artwork $a->id: $message";
                    $valid = false;
                }
            };
            $check((bool) $a->title && (bool) $a->description && (bool) $a->published_at && (bool) $a->is_published, 'incomplete publication');
            $check((bool) $a->user?->is_demo && $a->tags->isNotEmpty(), 'owner/tags');
            $check(! $a->indexingEnabled() && ! $a->aiMetadata && ! AiProcessingRun::where('artwork_id', $a->id)->exists(), 'unexpected AI state');
            $check((bool) $p?->is_demo && (bool) $p?->creator_name && (bool) $p?->license_url && (bool) $p?->primary_category, 'provenance');
            if ($p) {
                $item = new ContentItem($p->source, $p->external_id, $a->type, $p->source_url, $p->image_url, $a->title, $p->creator_name, $p->license, $p->license_url, attribution_text: $p->attribution_text);
                $check((bool) app(SourceRegistry::class)->allowed($item), 'license');
                $categories[$p->primary_category] = ($categories[$p->primary_category] ?? 0) + 1;
            }
            $path = $m?->getPath();
            $exists = $path && is_file($path);
            $check((bool) $exists, 'original missing');
            if ($exists) {
                $hash = hash_file('sha256', $path);
                $check($hash === ($p?->media_sha256 ?: $p?->image_sha256), 'SHA256 mismatch');
                $check(! isset($hashes[$hash]), 'duplicate media');
                $hashes[$hash] = true;
                $check($a->type === 'video' || @getimagesize($path) !== false, 'invalid image');
            }
            $check($m && $m->hasGeneratedConversion('thumb') && is_file($m->getPath('thumb')) && @getimagesize($m->getPath('thumb')) !== false, 'thumbnail');
            if ($a->type === 'video') {
                $preview = $a->getFirstMedia('preview');
                $check($preview && is_file($preview->getPath()) && $m?->getCustomProperty('duration') > 0 && (string) $preview->getCustomProperty('source_media_id') === (string) $m?->id, 'video preview/duration');
                try {
                    $probe = FFProbe::create(['ffprobe.binaries' => config('media-library.ffprobe_path'), 'timeout' => 30]);
                    $check($exists && (float) $probe->format($path)->get('duration') > 0 && $preview && (float) $probe->format($preview->getPath())->get('duration') > 0, 'video integrity');
                } catch (\Throwable) {
                    $check(false, 'FFprobe validation failed');
                }
            }
            if ($valid) {
                $counts[$a->type === 'video' ? 'videos' : 'images']++;
            }
        }
        $shortfalls = [];
        foreach (File::glob(config('demo_content.root').'/manifests/*.json') as $file) {
            $manifest = json_decode(File::get($file), true);
            foreach ($manifest['collections'] ?? [] as $id) {
                $col = Collection::find($id);
                if (! $col || ! $col->artworks()->where('is_demo', true)->exists()) {
                    $issues[] = "Collection $id missing/empty";
                } else {
                    $counts['collections']++;
                }
            }
            foreach ($manifest['quotas'] ?? [] as $category => $target) {
                $actual = ArtworkImport::where('is_demo', true)->where('metadata->dataset_seed', $manifest['seed'])->where('primary_category', $category)->whereHas('artwork', fn ($q) => $q->where('type', 'image'))->count();
                if ($actual < $target) {
                    $shortfalls[basename($file).':'.$category] = $target - $actual;
                }
            }
            $actualVideos = ArtworkImport::where('is_demo', true)->where('metadata->dataset_seed', $manifest['seed'])->whereHas('artwork', fn ($q) => $q->where('type', 'video'))->count();
            if ($actualVideos < ($manifest['targets']['video'] ?? 0)) {
                $shortfalls[basename($file).':video'] = $manifest['targets']['video'] - $actualVideos;
            }
        }
        if (! $counts['profiles']) {
            $issues[] = 'No demo profiles';
        }
        $result = ['checked_at' => now()->toISOString(), 'counts' => $counts, 'categories' => $categories, 'issues' => $issues, 'shortfalls' => $shortfalls];
        $path = config('demo_content.root').'/reports/verify-'.now()->format('Ymd-His').'.json';
        app(Manifest::class)->save($path, $result);
        $this->table(['Показатель', 'Количество'], array_map(fn ($k, $n) => [$k, $n], array_keys($counts), $counts));
        foreach ($issues as $issue) {
            $this->warn($issue);
        }$this->line('Недобор квот: '.array_sum($shortfalls));
        $this->info($path);

        return $issues || $shortfalls ? self::FAILURE : self::SUCCESS;
    }
}
