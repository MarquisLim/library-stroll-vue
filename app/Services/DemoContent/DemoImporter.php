<?php

namespace App\Services\DemoContent;

use App\Jobs\ProcessVideoPreviewJob;
use App\Models\Artwork;
use App\Models\ArtworkImport;
use App\Models\Tag;
use App\Models\User;
use App\Services\ArtworkImport\ArtworkImageDownloader;
use App\Services\ArtworkImport\SourceTags;
use Illuminate\Support\Facades\DB;

class DemoImporter
{
    public function import(ContentItem $item, User $owner, string $category, int $seed): array
    {
        if (ArtworkImport::where('source', $item->source)->where('external_id', $item->external_id)->exists()) {
            return ['status' => 'existing'];
        }
        if (! app(SourceRegistry::class)->allowed($item)) {
            return ['status' => 'license'];
        }
        $tags = app(SourceTags::class)->normalize($item->original_tags);
        if (! $owner->is_demo || ! $item->original_title || parse_url($item->source_url, PHP_URL_SCHEME) !== 'https' || parse_url($item->license_url ?? '', PHP_URL_SCHEME) !== 'https' || ! $item->media_url || ! $tags) {
            return ['status' => 'metadata'];
        }
        $path = app(ArtworkImageDownloader::class)->download($item->media_url, $item->source, $item->media_type);
        $artwork = null;
        try {
            $hash = hash_file('sha256', $path);
            if (ArtworkImport::where('media_sha256', $hash)->orWhere('image_sha256', $hash)->exists()) {
                return ['status' => 'duplicate'];
            }

            return DB::transaction(function () use ($item, $owner, $category, $seed, $tags, $path, $hash, &$artwork) {
                try {
                    $artwork = new Artwork(['user_id' => $owner->id, 'title' => mb_substr($item->original_title, 0, 255), 'description' => $item->description(),
                        'type' => $item->media_type, 'is_published' => false, 'is_private' => false, 'is_adult' => false, 'has_ai' => false, 'allow_download' => true, 'allow_comments' => true]);
                    $artwork->forceFill(['is_demo' => true, 'ai_indexing_enabled' => false])->save();
                    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
                    $ext = match ($mime) {
                        'image/jpeg' => 'jpg','image/png' => 'png','image/webp' => 'webp','video/mp4' => 'mp4','video/webm' => 'webm',default => throw new \RuntimeException('Unsupported MIME')
                    };
                    $media = $artwork->addMedia($path)->preservingOriginal()->usingFileName('demo-'.hash('sha256', $item->source.':'.$item->external_id).'.'.$ext)->toMediaCollection('artworks');
                    if ($item->media_type === 'video') {
                        (new ProcessVideoPreviewJob($media))->handle();
                    }
                    if (! $media->hasGeneratedConversion('thumb') || ! is_file($media->getPath('thumb'))) {
                        throw new \RuntimeException('Missing thumbnail');
                    }
                    $artwork->tags()->sync(array_map(fn ($tag) => Tag::firstOrCreate(['name' => $tag])->id, $tags));
                    ArtworkImport::create(['artwork_id' => $artwork->id, 'source' => $item->source, 'external_id' => $item->external_id, 'source_url' => $item->source_url,
                        'image_url' => $item->media_url, 'license' => $item->license, 'image_sha256' => $hash, 'media_sha256' => $hash,
                        'creator_name' => app(SourceRegistry::class)->creator($item), 'creator_url' => $item->creator_url, 'license_url' => $item->license_url, 'attribution_text' => $item->attribution_text,
                        'original_description' => $item->original_description, 'original_date' => $item->creation_date, 'original_medium' => $item->medium,
                        'primary_category' => $category, 'is_demo' => true, 'metadata' => ['original' => $item->original_metadata, 'tags' => $tags, 'language' => $item->language, 'dataset_seed' => $seed, 'dataset_preset' => config('demo_content.active_preset', 'balanced')]]);
                    $days = hexdec(substr(hash('sha256', "$seed:{$item->source}:{$item->external_id}"), 0, 6)) % 730;
                    $days %= max(1, (int) $owner->created_at->diffInDays(now()));
                    $publishedAt = now()->subDays($days);
                    $artwork->forceFill(['created_at' => $publishedAt, 'is_published' => true, 'published_at' => $publishedAt])->save();

                    return ['status' => 'imported', 'artwork_id' => $artwork->id, 'sha256' => $hash];
                } catch (\Throwable $e) {
                    if ($artwork) {
                        foreach ($artwork->media()->get() as $m) {
                            app(config('media-library.file_remover_class'))->removeAllFiles($m);
                        }
                    }
                    throw $e;
                }
            });
        } catch (\Throwable $e) {
            if ($artwork) {
                foreach ($artwork->media()->get() as $m) {
                    app(config('media-library.file_remover_class'))->removeAllFiles($m);
                }
            }
            throw $e;
        } finally {
            @unlink($path);
        }
    }
}
