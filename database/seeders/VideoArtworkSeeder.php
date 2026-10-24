<?php

namespace Database\Seeders;

use App\Jobs\ProcessVideoPreviewJob;
use App\Models\Artwork;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\Support\SeedVideoResolver;
use Illuminate\Database\Seeder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class VideoArtworkSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command->warn('VideoArtworkSeeder is available only in local/testing environments.');

            return;
        }

        $videos = new SeedVideoResolver();
        $created = 0;
        $ready = 0;

        foreach ($this->definitions() as $definition) {
            $user = User::withoutGlobalScopes()
                ->where('email', $definition['user'])
                ->first();

            if ($user === null) {
                $this->command->warn("Video author [{$definition['user']}] was not found.");
                continue;
            }

            $artwork = Artwork::withoutGlobalScopes()
                ->where('user_id', $user->id)
                ->where('title', $definition['title'])
                ->first();
            $sourceMedia = $artwork?->getFirstMedia('artworks');

            if ($sourceMedia === null) {
                $videoPath = $videos->get(
                    $definition['key'],
                    $definition['file'],
                    $definition['urls'],
                );

                if ($videoPath === null) {
                    $this->command->warn(
                        "Video «{$definition['title']}» skipped: "
                        .($videos->lastError() ?? 'source is unavailable')
                    );
                    continue;
                }

                $artwork ??= Artwork::create([
                    'user_id' => $user->id,
                    'title' => $definition['title'],
                ]);

                $artwork->update($this->attributes($definition));

                try {
                    $sourceMedia = $artwork->addMedia($videoPath)
                        ->preservingOriginal()
                        ->toMediaCollection('artworks');
                    $created++;
                } catch (Throwable $exception) {
                    $this->command->warn(
                        "Unable to attach video «{$definition['title']}»: {$exception->getMessage()}"
                    );
                    continue;
                }
            } else {
                $artwork->update($this->attributes($definition));
            }

            $tagIds = Tag::whereIn('name', $definition['tags'])->pluck('id');
            $artwork->tags()->syncWithoutDetaching($tagIds);

            if (! $this->isProcessed($artwork, $sourceMedia)) {
                try {
                    ProcessVideoPreviewJob::dispatchSync($sourceMedia);
                    $sourceMedia->refresh();
                    $artwork->refresh();
                } catch (Throwable $exception) {
                    $this->command->warn(
                        "Preview for «{$definition['title']}» was not generated: {$exception->getMessage()}"
                    );
                }
            }

            if ($this->isProcessed($artwork, $sourceMedia)) {
                $ready++;
            } else {
                $this->command->warn(
                    "Video «{$definition['title']}» exists, but its preview or duration is missing."
                );
            }
        }

        $this->command->info(
            "VideoArtworkSeeder: {$created} attached, {$ready}/".count($this->definitions()).' ready.'
        );
    }

    private function attributes(array $definition): array
    {
        return [
            'description' => $definition['description'],
            'type' => 'video',
            'is_published' => true,
            'is_private' => false,
            'is_adult' => false,
            'has_ai' => $definition['has_ai'] ?? false,
            'allow_download' => $definition['allow_download'] ?? true,
            'allow_comments' => true,
            'views_count' => $definition['views'],
            'published_at' => now()->subDays($definition['days_ago']),
        ];
    }

    private function isProcessed(Artwork $artwork, Media $sourceMedia): bool
    {
        if (! str_starts_with((string) $sourceMedia->mime_type, 'video/')) {
            return false;
        }

        $path = $sourceMedia->getPath();
        if (! is_file($path) || ! is_readable($path)) {
            return false;
        }

        $preview = $artwork->getMedia('preview')->first(
            fn (Media $media): bool => (string) $media->getCustomProperty('source_media_id')
                === (string) $sourceMedia->getKey()
        );

        return $preview !== null
            && is_file($preview->getPath())
            && $sourceMedia->hasCustomProperty('duration')
            && (int) $sourceMedia->getCustomProperty('duration') > 0;
    }

    private function definitions(): array
    {
        return [
            [
                'key' => 'flower-meadow',
                'file' => 'flower-meadow.mp4',
                'urls' => [
                    'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
                ],
                'user' => 'nature@libstroll.test',
                'title' => 'Цветы на ветру',
                'description' => 'Короткое видео цветущего луга: белые цветы колышутся на ветру.',
                'tags' => ['природа', 'цветы', 'видео'],
                'views' => 980,
                'days_ago' => 24,
            ],
            [
                'key' => 'sintel-trailer',
                'file' => 'sintel-trailer.mp4',
                'urls' => [
                    'https://media.w3.org/2010/05/sintel/trailer.mp4',
                ],
                'user' => 'dragon@libstroll.test',
                'title' => 'Sintel — fantasy short',
                'description' => 'Фрагмент открытого анимационного фильма Sintel: героиня отправляется на поиски дракона.',
                'tags' => ['фантастика', 'дракон', 'фэнтези', '3D'],
                'views' => 1540,
                'days_ago' => 38,
                'allow_download' => false,
            ],
            [
                'key' => 'bunny-trailer',
                'file' => 'bunny-trailer.mp4',
                'urls' => [
                    'https://media.w3.org/2010/05/bunny/trailer.mp4',
                ],
                'user' => 'pixelwitch@libstroll.test',
                'title' => 'Big Buck Bunny — animation',
                'description' => 'Фрагмент открытого 3D-анимационного фильма Big Buck Bunny.',
                'tags' => ['3D', 'иллюстрация', 'цифровая живопись'],
                'views' => 720,
                'days_ago' => 12,
            ],
        ];
    }
}
