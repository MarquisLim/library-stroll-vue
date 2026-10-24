<?php

namespace App\Services\ArtworkImport;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ArtworkImageDownloader
{
    private const MAX_BYTES = 15 * 1024 * 1024;

    /** The caller owns the returned temporary file and must remove it. */
    public function download(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        if (parse_url($url, PHP_URL_SCHEME) !== 'https'
            || ! in_array($host, ['www.artic.edu', 'images.metmuseum.org', 'collectionapi.metmuseum.org'], true)) {
            throw new RuntimeException('Источник вернул изображение с неподдерживаемого хоста.');
        }

        $directory = storage_path('app/artwork-import-tmp');
        File::ensureDirectoryExists($directory);
        $path = tempnam($directory, 'import_');
        if ($path === false) {
            throw new RuntimeException('Не удалось создать временный файл изображения.');
        }

        try {
            $response = Http::withUserAgent('LibraryStroll artwork importer')->accept('image/*')
                ->connectTimeout(10)->timeout(60)->sink($path)
                ->withOptions([
                    'allow_redirects' => false,
                    'progress' => function ($total, $downloaded): void {
                        if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                            throw new RuntimeException('Изображение превышает лимит 15 MiB.');
                        }
                    },
                ])->get($url)->throw();

            clearstatcache(true, $path);
            if ($response->status() !== 200 || filesize($path) > self::MAX_BYTES) {
                throw new RuntimeException('Некорректный ответ при загрузке изображения (ожидался HTTP 200).');
            }
            $info = @getimagesize($path);
            if ($info === false || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
                || $info[0] * $info[1] > 40_000_000) {
                throw new RuntimeException('Файл не является допустимым JPEG/PNG/WebP или превышает 40 MP.');
            }

            return $path;
        } catch (Throwable $exception) {
            File::delete($path);
            throw $exception;
        }
    }
}
