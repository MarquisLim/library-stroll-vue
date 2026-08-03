<?php

namespace App\Jobs;

use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\Format\Video\X264;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class ProcessVideoPreviewJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public bool $deleteWhenMissingModels = true;

    public function __construct(protected Media $media) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        $mediaId = $this->media->getKey();
        $tmpFile = null;

        try {
            if ($mediaId === null) {
                throw new RuntimeException('The source media has no persisted identifier.');
            }

            $media = Media::query()->find($mediaId);

            if ($media === null) {
                throw new RuntimeException("Source media [{$mediaId}] no longer exists.");
            }

            if ($media->collection_name !== 'artworks') {
                throw new RuntimeException(
                    "Source media [{$mediaId}] belongs to unexpected collection [{$media->collection_name}]."
                );
            }

            if (! str_starts_with((string) $media->mime_type, 'video/')) {
                throw new RuntimeException(
                    "Source media [{$mediaId}] is not a video (MIME: {$media->mime_type})."
                );
            }

            $model = $media->model()->withoutGlobalScopes()->first();

            if ($model === null) {
                throw new RuntimeException("The model for source media [{$mediaId}] no longer exists.");
            }

            if ((string) $model->getFirstMedia('artworks')?->getKey() !== (string) $mediaId) {
                Log::info('Skipping stale video preview job.', [
                    'media_id' => $mediaId,
                    'model_type' => $media->model_type,
                    'model_id' => $media->model_id,
                ]);

                return;
            }

            $existingPreview = $model->getMedia('preview')->first(
                fn (Media $preview): bool => (string) $preview->getCustomProperty('source_media_id') === (string) $mediaId
            );

            if ($existingPreview !== null && $media->hasCustomProperty('duration')) {
                return;
            }

            $source = $media->getPath();

            if (! is_file($source) || ! is_readable($source)) {
                throw new RuntimeException(
                    "Source file for media [{$mediaId}] is missing or unreadable at [{$source}]."
                );
            }

            $ffmpegPath = $this->configuredBinaryPath('ffmpeg_path');
            $ffprobePath = $this->configuredBinaryPath('ffprobe_path');

            $ffprobe = FFProbe::create([
                'ffprobe.binaries' => $ffprobePath,
            ]);
            $duration = (float) $ffprobe->format($source)->get('duration');

            if (! is_finite($duration) || $duration <= 0) {
                throw new RuntimeException(
                    "FFprobe returned invalid duration [{$duration}] for media [{$mediaId}]."
                );
            }

            if ($existingPreview === null) {
                $start = random_int(0, max(0, (int) floor($duration) - 3));
                $tmpDir = config('media-library.temporary_directory_path')
                    ?: storage_path('media-library/temp');

                if (! is_dir($tmpDir) && ! mkdir($tmpDir, 0755, true) && ! is_dir($tmpDir)) {
                    throw new RuntimeException("Unable to create video preview temp directory [{$tmpDir}].");
                }

                if (! is_writable($tmpDir)) {
                    throw new RuntimeException("Video preview temp directory is not writable [{$tmpDir}].");
                }

                $tmpFile = $tmpDir.DIRECTORY_SEPARATOR
                    ."preview_{$mediaId}_".bin2hex(random_bytes(8)).'.mp4';

                $ffmpeg = FFMpeg::create([
                    'ffmpeg.binaries' => $ffmpegPath,
                    'ffprobe.binaries' => $ffprobePath,
                ]);
                $video = $ffmpeg->open($source);
                $video->filters()
                    ->clip(TimeCode::fromSeconds($start), TimeCode::fromSeconds(6));

                $format = new X264('aac');
                $format->setKiloBitrate(300);
                $format->setAdditionalParameters(['-preset', 'veryfast']);

                $video->save($format, $tmpFile);

                $model->refresh();
                if ((string) $model->getFirstMedia('artworks')?->getKey() !== (string) $mediaId) {
                    Log::info('Discarding preview generated for replaced media.', [
                        'media_id' => $mediaId,
                        'model_type' => $media->model_type,
                        'model_id' => $media->model_id,
                    ]);

                    return;
                }

                $model->addMedia($tmpFile)
                    ->usingName('preview')
                    ->withCustomProperties(['source_media_id' => $mediaId])
                    ->toMediaCollection('preview');
            }

            $media->setCustomProperty('duration', (int) round($duration));
            $media->save();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Video preview processing failed for media [{$mediaId}]: {$exception->getMessage()}",
                previous: $exception
            );
        } finally {
            if ($tmpFile !== null && is_file($tmpFile) && ! unlink($tmpFile)) {
                Log::warning('Unable to remove temporary video preview file.', [
                    'media_id' => $mediaId,
                    'temporary_file' => $tmpFile,
                ]);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Video preview job failed permanently.', [
            'media_id' => $this->media->getKey(),
            'attempts' => $this->attempts(),
            'exception' => $exception?->getMessage(),
            'exception_class' => $exception ? $exception::class : null,
        ]);
    }

    private function configuredBinaryPath(string $key): string
    {
        $path = config("media-library.{$key}");

        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException(
                "Media Library configuration [media-library.{$key}] is empty; configure it in the environment."
            );
        }

        return $path;
    }
}
