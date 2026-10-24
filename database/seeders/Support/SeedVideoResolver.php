<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Facades\Http;
use Throwable;

class SeedVideoResolver
{
    private string $localDirectory;

    private string $cacheDirectory;

    private ?string $lastError = null;

    public function __construct()
    {
        $this->localDirectory = database_path('seeders/videos');
        $this->cacheDirectory = storage_path('app/seed-media/videos');

        if (! is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0755, true);
        }
    }

    public function get(string $key, string $localFile, array $urls): ?string
    {
        $this->lastError = null;

        $local = $this->localDirectory.DIRECTORY_SEPARATOR.$localFile;
        if (is_file($local)) {
            return $this->validate($local) ? $local : null;
        }

        $cached = $this->findCached($key);
        if ($cached !== null) {
            return $this->validate($cached) ? $cached : null;
        }

        foreach ($urls as $url) {
            try {
                $response = Http::withHeaders([
                    'Accept' => 'video/*',
                    'User-Agent' => 'LibraryStroll local database seeder',
                ])
                    ->connectTimeout(8)
                    ->timeout(60)
                    ->retry(2, 500)
                    ->get($url)
                    ->throw();

                $body = $response->body();
                if (strlen($body) < 20_000 || strlen($body) > 20 * 1024 * 1024) {
                    $this->lastError = "Video [{$url}] has an invalid size.";
                    continue;
                }

                $mime = strtolower(strtok((string) $response->header('Content-Type'), ';') ?: '');
                $extension = str_contains($mime, 'webm') ? 'webm' : 'mp4';
                $path = $this->cacheDirectory.DIRECTORY_SEPARATOR
                    .$this->safeName($key).'.'.$extension;
                file_put_contents($path, $body);

                if ($this->validate($path)) {
                    return $path;
                }

                @unlink($path);
            } catch (Throwable $exception) {
                $this->lastError = $exception->getMessage();
            }
        }

        return null;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    private function validate(string $path): bool
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->lastError = "Video file [{$path}] is missing or unreadable.";

            return false;
        }

        $size = filesize($path);
        if ($size === false || $size < 20_000 || $size > 20 * 1024 * 1024) {
            $this->lastError = "Video file [{$path}] must be between 20 KB and 20 MB.";

            return false;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! is_string($mime) || ! str_starts_with($mime, 'video/')) {
            $this->lastError = "File [{$path}] has invalid video MIME [{$mime}].";

            return false;
        }

        return true;
    }

    private function findCached(string $key): ?string
    {
        $files = glob(
            $this->cacheDirectory.DIRECTORY_SEPARATOR.$this->safeName($key).'.{mp4,webm,mov}',
            GLOB_BRACE,
        ) ?: [];

        return $files[0] ?? null;
    }

    private function safeName(string $value): string
    {
        return preg_replace('/[^a-z0-9_-]+/i', '_', $value) ?: 'video';
    }
}
