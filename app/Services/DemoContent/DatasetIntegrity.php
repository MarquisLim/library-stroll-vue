<?php

namespace App\Services\DemoContent;

use App\Models\ArtworkImport;
use App\Services\ArtworkImport\ArtworkImageDownloader;
use Illuminate\Support\Facades\File;
use Spatie\MediaLibrary\Conversions\FileManipulator;

class DatasetIntegrity
{
    public function inspect(ArtworkImport $record): ?string
    {
        $media = $record->artwork?->getFirstMedia('artworks');
        if (! $media || ! is_file($media->getPath())) {
            return 'missing_original';
        }
        if (hash_file('sha256', $media->getPath()) !== ($record->media_sha256 ?: $record->image_sha256)) {
            return 'hash_mismatch';
        }
        if (! is_file($media->getPath('thumb')) || ! @getimagesize($media->getPath('thumb'))) {
            return 'missing_thumbnail';
        }

        return null;
    }

    public function repair(ArtworkImport $record, ContentItem $item): void
    {
        $problem = $this->inspect($record);
        $media = $record->artwork?->getFirstMedia('artworks');
        if (! $media || $problem === 'hash_mismatch') {
            throw new \RuntimeException('Manual review required; existing bytes will not be overwritten');
        }
        if ($problem === 'missing_original') {
            $path = app(ArtworkImageDownloader::class)->download($item->media_url, $item->source, $item->media_type);
            try {
                if (hash_file('sha256', $path) !== ($record->media_sha256 ?: $record->image_sha256)) {
                    throw new \RuntimeException('Source file changed; repair refused');
                }
                File::ensureDirectoryExists(dirname($media->getPath()));
                if (! copy($path, $media->getPath())) {
                    throw new \RuntimeException('Cannot restore original');
                }
            } finally {
                @unlink($path);
            }
        }
        if (! is_file($media->getPath('thumb')) || ! @getimagesize($media->getPath('thumb'))) {
            app(FileManipulator::class)->createDerivedFiles($media, ['thumb']);
        }
        if ($this->inspect($record)) {
            throw new \RuntimeException('Repair incomplete');
        }
    }
}
