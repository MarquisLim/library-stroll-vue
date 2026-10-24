<?php

namespace App\Services\ArtworkImport;

use App\Models\Artwork;
use App\Models\ArtworkImport;
use App\Models\Collection;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Role;
use Throwable;

class ArtworkImporter
{
    public function __construct(private readonly ArtworkImageDownloader $downloader) {}

    public function exists(SourceArtwork $source): bool
    {
        return ArtworkImport::where('source', $source->source)->where('external_id', $source->externalId)->exists();
    }

    public function import(SourceArtwork $source, ?User $owner, ?Collection $collection, bool $publish): Artwork
    {
        $path = $this->downloader->download($source->imageUrl);
        $media = null;

        try {
            return DB::transaction(function () use ($source, $owner, $collection, $publish, $path, &$media) {
                $owner ??= User::firstOrCreate(['email' => 'museum-imports@libstroll.test'], [
                    'name' => 'Музейные произведения',
                    'description' => 'Импорт открытых музейных коллекций. Автор оригинала указан в описании работы.',
                    'password' => Str::random(64),
                ]);
                if (! $owner->exists || $owner->is_blocked) {
                    throw new \RuntimeException('Владелец импорта недоступен или заблокирован.');
                }
                if ($owner->wasRecentlyCreated) {
                    $owner->assignRole(Role::findOrCreate('User', 'web'));
                }

                $artwork = Artwork::create([
                    'user_id' => $owner->id,
                    'title' => mb_substr($source->title, 0, 255),
                    'description' => $source->description(),
                    'type' => 'image', 'is_published' => $publish,
                    'published_at' => $publish ? now() : null,
                    'is_private' => false, 'is_adult' => false, 'has_ai' => false,
                    'allow_download' => true, 'allow_comments' => true,
                ]);
                ArtworkImport::create([
                    'artwork_id' => $artwork->id, 'source' => $source->source,
                    'external_id' => $source->externalId, 'source_url' => $source->sourceUrl,
                    'image_url' => $source->imageUrl, 'license' => $source->license,
                    'image_sha256' => hash_file('sha256', $path), 'metadata' => $source->metadata(),
                ]);

                $mime = getimagesize($path)['mime'];
                $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
                try {
                    $media = $artwork->addMedia($path)->preservingOriginal()
                        ->usingName(mb_substr($source->title, 0, 255))
                        ->usingFileName($source->source.'-'.$source->externalId.'.'.$extension)
                        ->withCustomProperties(['import_source' => $source->source, 'external_id' => $source->externalId])
                        ->toMediaCollection('artworks');
                } catch (Throwable $exception) {
                    // Conversions may fail after the media row and original file
                    // were created, before toMediaCollection returns the Media.
                    foreach ($artwork->media()->get() as $incompleteMedia) {
                        app(config('media-library.file_remover_class'))->removeAllFiles($incompleteMedia);
                    }
                    throw $exception;
                }

                $artwork->tags()->sync(array_map(
                    fn (string $tag): int => Tag::firstOrCreate(['name' => $tag])->id,
                    $source->tags,
                ));
                if ($collection !== null) {
                    if ($collection->user_id !== $owner->id) {
                        throw new \RuntimeException('Коллекция должна принадлежать владельцу импортируемых работ.');
                    }
                    $artwork->collections()->attach($collection);
                }

                return $artwork;
            });
        } catch (Throwable $exception) {
            // A SQL rollback cannot undo files already copied by Media Library.
            if ($media instanceof Media) {
                app(config('media-library.file_remover_class'))->removeAllFiles($media);
            }
            throw $exception;
        } finally {
            File::delete($path);
        }
    }
}
