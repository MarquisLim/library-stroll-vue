<?php

namespace App\Services\ResearchDataset;

use App\Models\ArtworkImport;
use App\Services\DemoContent\ContentItem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DatasetMetadataExporter
{
    public function path(array $media, string $field): string
    {
        $relative = $media[$field] ?? '';
        if (! in_array($media['disk'] ?? '', config('research_dataset.allowed_disks'), true) || ! is_string($relative) || $relative === '' || str_contains($relative, '..') || str_contains($relative, '\\') || str_contains($relative, "\0") || str_starts_with($relative, '/') || str_contains($relative, ':')) {
            throw new RuntimeException('Unauthorized local media reference');
        }
        $disk = Storage::disk($media['disk']);
        $root = realpath($disk->path(''));
        $path = $disk->path($relative);
        $resolved = realpath($path);
        if ($resolved && (! $root || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Media escapes allowed storage');
        }

        return $path;
    }

    public function export(ArtworkImport $record): array
    {
        $a = $record->artwork;
        $m = $a?->getFirstMedia('artworks');
        if (! $a || ! $m || ! in_array($m->disk, config('research_dataset.allowed_disks'), true)) {
            throw new RuntimeException('Missing artwork/media');
        }
        $root = rtrim(Storage::disk($m->disk)->path(''), '/\\').DIRECTORY_SEPARATOR;
        $local = ['id' => $m->id, 'uuid' => $m->uuid, 'disk' => $m->disk, 'original_path' => str_replace('\\', '/', substr($m->getPath(), strlen($root))), 'thumbnail_path' => str_replace('\\', '/', substr($m->getPath('thumb'), strlen($root)))];
        $original = $this->path($local, 'original_path');
        $thumb = $this->path($local, 'thumbnail_path');
        $preview = $a->getFirstMedia('preview');
        $video = null;
        if ($a->type === 'video' && $preview && $preview->disk === $m->disk) {
            $video = ['preview_media_id' => $preview->id, 'preview_source_media_id' => $preview->getCustomProperty('source_media_id'), 'preview_path' => str_replace('\\', '/', substr($preview->getPath(), strlen($root)))];
            $previewPath = $this->path([...$local, ...$video], 'preview_path');
            $video['preview_sha256'] = is_file($previewPath) ? hash_file('sha256', $previewPath) : null;
        }
        $dimensions = is_file($original) && $a->type === 'image' ? @getimagesize($original) : false;
        $raw = $record->metadata['original'] ?? [];
        $allowed = ['id', 'title', 'artist', 'date', 'medium', 'tags', 'language', 'classification', 'style', 'object_name', 'mime', 'license', 'license_url', 'creator', 'creator_url', 'attribution', 'foreign_landing_url', 'extmetadata', 'usage', 'topics', 'nasa_id', 'keywords', 'center', 'photographer', 'secondary_creator', 'date_created'];
        $raw = $this->clean(array_intersect_key(is_array($raw) ? $raw : [], array_flip($allowed)));
        $rawTags = $raw['tags'] ?? $raw['topics'] ?? $raw['keywords'] ?? null;
        if (is_array($rawTags)) {
            $rawTags = array_values(array_filter(array_map(fn ($t) => ContentItem::text(is_array($t) ? ($t['name'] ?? '') : $t), $rawTags)));
        } else {
            $rawTags = null;
        }
        $tags = array_values(array_unique($rawTags ?: ($record->metadata['tags'] ?? [])));
        sort($tags, SORT_STRING);
        $normalizedTags = $a->tags->pluck('name')->sort(SORT_STRING)->values()->all();
        $source = ['title' => $raw['title'] ?? null, 'description' => $record->original_description, 'creator' => $record->creator_name, 'creator_url' => $record->creator_url, 'creation_date' => $record->original_date, 'medium' => $record->original_medium, 'categories' => $raw['topics'] ?? [], 'language' => $record->metadata['language'] ?? null, 'tags' => $rawTags ?: null, 'raw' => $raw];
        $width = $dimensions ? $dimensions[0] : null;
        $height = $dimensions ? $dimensions[1] : null;
        $technique = null;
        foreach (config('research_dataset.techniques') as $term) {
            if (preg_match('/\b'.preg_quote($term, '/').'\b/iu', ($record->original_medium ?? '').' '.implode(' ', $tags))) {
                $technique = $term;
                break;
            }
        }
        $period = preg_match('/(?<!\d)(1\d{3}|20\d{2})(?!\d)/', $record->original_date ?? '', $date) ? 'first-recorded-year-century-'.(intdiv((int) $date[1], 100) * 100) : null;

        return ['dataset_item_id' => $record->source.':'.$record->external_id, 'artwork_id' => $a->id, 'source' => $record->source, 'external_id' => $record->external_id,
            'title' => $a->title, 'description' => $record->original_description ?: $a->description, 'creator' => $record->creator_name, 'category' => $record->primary_category, 'tags' => $tags,
            'license' => $record->license, 'license_url' => $record->license_url, 'source_url' => $record->source_url, 'source_media_url' => $record->image_url, 'attribution_text' => $record->attribution_text,
            'image_sha256' => $a->type === 'image' ? ($record->media_sha256 ?: $record->image_sha256) : null, 'media_sha256' => $record->media_sha256 ?: $record->image_sha256, 'media_type' => $a->type,
            'source_metadata' => $source, 'tags_origin' => $rawTags ? 'stored_source_metadata' : 'importer_normalized_fallback',
            'normalized_metadata' => ['title' => $a->title, 'description' => $a->description, 'tags' => $normalizedTags, 'is_published' => (bool) $a->is_published, 'is_private' => (bool) $a->is_private, 'is_blocked' => (bool) $a->is_blocked, 'published_at' => $a->published_at],
            'strata' => ['technique' => $technique, 'period' => $period, 'orientation' => $width && $height ? ($width > $height * 1.1 ? 'landscape' : ($height > $width * 1.1 ? 'portrait' : 'square')) : null],
            'media' => [...$local, 'width' => $width, 'height' => $height, 'mime' => $m->mime_type, 'thumbnail_sha256' => is_file($thumb) ? hash_file('sha256', $thumb) : null, 'preview_url' => '/storage/'.$local['thumbnail_path'], 'duration' => $m->getCustomProperty('duration'), 'video' => $video],
            'imported_at' => $record->created_at?->toISOString()];
    }

    private function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $v) {
                if (! is_string($key) || ! preg_match('/secret|password|token|api_key|email|user_id/i', $key)) {
                    $result[$key] = $this->clean($v);
                }
            }

            return $result;
        }

        return is_string($value) ? ContentItem::text($value) : $value;
    }
}
