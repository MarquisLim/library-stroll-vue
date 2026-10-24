<?php

namespace App\Console\Commands;

use App\Models\ArtworkImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CheckArtworkImportsCommand extends Command
{
    protected $signature = 'artworks:check-imports {--source= : Проверить только artic или met}';

    protected $description = 'Проверить файлы, миниатюры, теги и происхождение импортированных работ';

    public function handle(): int
    {
        if (! Schema::hasTable('artwork_imports')) {
            $this->error('Сначала выполните php artisan migrate.');

            return self::FAILURE;
        }
        if ($this->option('source') && ! in_array($this->option('source'), ['artic', 'met'], true)) {
            $this->error('Источник должен быть artic или met.');

            return self::INVALID;
        }

        $count = $failed = 0;
        $imports = ArtworkImport::with(['artwork.media', 'artwork.tags'])
            ->when($this->option('source'), fn ($q, $source) => $q->where('source', $source));

        foreach ($imports->lazyById(50) as $import) {
            $count++;
            $artwork = $import->artwork;
            $media = $artwork?->getFirstMedia('artworks');
            $path = $media?->getPath();
            $readable = is_string($path) && is_file($path) && is_readable($path);
            $expectedTags = $import->metadata['tags'] ?? [];
            $checks = [
                'оригинал' => $readable && @getimagesize($path) !== false,
                'SHA256' => $readable && hash_file('sha256', $path) === $import->image_sha256,
                'один файл' => $artwork?->getMedia('artworks')->count() === 1,
                'миниатюра' => $media !== null && $media->hasGeneratedConversion('thumb') && is_file($media->getPath('thumb')),
                'теги источника' => $expectedTags !== [] && $artwork !== null
                    && array_diff($expectedTags, $artwork->tags->pluck('name')->all()) === [],
                'происхождение' => filled($import->source_url) && filled($import->license),
            ];
            $problems = array_keys(array_filter($checks, fn ($ok) => ! $ok));
            if ($problems !== []) {
                $failed++;
                $this->warn("#{$import->artwork_id} {$import->source}:{$import->external_id} — ".implode(', ', $problems));
            }
        }

        $this->line("Проверено: {$count}; с ошибками: {$failed}.");
        if ($count === 0) {
            $this->warn('Импортированных работ ещё нет. Используйте php artisan artworks:import.');
        }

        return $failed > 0 || $count === 0 ? self::FAILURE : self::SUCCESS;
    }
}
