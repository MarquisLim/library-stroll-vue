<?php

namespace App\Services\ArtworkImport;

use App\Services\DemoContent\MediaUrlPolicy;
use App\Services\DemoContent\SourceHttp;
use FFMpeg\FFProbe;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ArtworkImageDownloader
{
    private const MAX_BYTES = 15 * 1024 * 1024;

    /** The caller owns the returned temporary file and must remove it. */
    public function download(string $url, ?string $source = null, string $type = 'image'): string
    {
        $hosts = $source ? config("demo_content.sources.$source.allowed_media_hosts", []) : array_merge(config('demo_content.sources.met.allowed_media_hosts'), config('demo_content.sources.artic.allowed_media_hosts'));
        $options = app(MediaUrlPolicy::class)->options($url, $hosts);
        $maxBytes = config($type === 'video' ? 'demo_content.max_video_bytes' : 'demo_content.max_image_bytes', self::MAX_BYTES);

        $directory = storage_path('app/artwork-import-tmp');
        File::ensureDirectoryExists($directory);
        $path = tempnam($directory, 'import_');
        if ($path === false) {
            throw new RuntimeException('Не удалось создать временный файл изображения.');
        }

        try {
            if ($source) {
                app(SourceHttp::class)->throttle($source);
                app(SourceHttp::class)->record($source, 'download_attempts');
            }
            $response = Http::withUserAgent(config('demo_content.user_agent'))->accept($type === 'video' ? 'video/*' : 'image/*')
                ->retry(config("demo_content.sources.$source.retry_attempts", 1) + 1, function ($attempt, $exception) use ($source) {
                    $header = $exception instanceof RequestException ? $exception->response->header('Retry-After') : null;
                    $seconds = $header ? (ctype_digit($header) ? (int) $header : max(0, strtotime($header) - time())) : config("demo_content.sources.$source.retry_delay", 500) / 1000 * (2 ** ($attempt - 1));
                    if ($seconds > 30) {
                        throw new RuntimeException('Media source paused for Retry-After');
                    }
                    if ($source) {
                        app(SourceHttp::class)->record($source, 'media_retries');
                        if ($exception instanceof ConnectionException) {
                            app(SourceHttp::class)->record($source, 'network_errors');
                        }
                    }

                    return (int) ($seconds * 1000);
                }, fn ($exception) => $exception instanceof ConnectionException || ($exception instanceof RequestException && in_array($exception->response->status(), [429, 500, 502, 503, 504])), throw: false)
                ->connectTimeout(config("demo_content.sources.$source.connect_timeout", 10))->timeout(config("demo_content.sources.$source.request_timeout", 60))->sink($path)
                ->withOptions([...$options,
                    'progress' => function ($total, $downloaded) use ($maxBytes): void {
                        if ($total > $maxBytes || $downloaded > $maxBytes) {
                            throw new RuntimeException('Изображение превышает лимит 15 MiB.');
                        }
                    },
                ])->get($url)->throw();

            clearstatcache(true, $path);
            if ($response->status() !== 200 || filesize($path) > $maxBytes) {
                throw new RuntimeException('Некорректный ответ при загрузке изображения (ожидался HTTP 200).');
            }
            if ($type === 'video') {
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
                if (! in_array($mime, ['video/mp4', 'video/webm'], true)) {
                    throw new RuntimeException('Unsupported video MIME');
                }
                $probe = FFProbe::create(['ffprobe.binaries' => config('media-library.ffprobe_path'), 'timeout' => 30]);
                $duration = (float) $probe->format($path)->get('duration');
                if ($duration <= 0 || ! is_finite($duration) || $duration > config('demo_content.max_video_seconds') || ! $probe->streams($path)->videos()->first()) {
                    throw new RuntimeException('Invalid video duration/stream');
                }
                if ($source) {
                    app(SourceHttp::class)->record($source, 'downloads_successful');
                }

                return $path;
            }
            $info = @getimagesize($path);
            if ($info === false || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
                || config('demo_content.max_pixels', 40_000_000) < $info[0] * $info[1]) {
                throw new RuntimeException('Файл не является допустимым JPEG/PNG/WebP или превышает 40 MP.');
            }

            if ($source) {
                app(SourceHttp::class)->record($source, 'downloads_successful');
            }

            return $path;
        } catch (Throwable $exception) {
            if ($source) {
                app(SourceHttp::class)->record($source, 'media_errors');
                if ($exception instanceof ConnectionException) {
                    app(SourceHttp::class)->record($source, 'network_errors');
                }
            }
            File::delete($path);
            throw $exception;
        }
    }
}
