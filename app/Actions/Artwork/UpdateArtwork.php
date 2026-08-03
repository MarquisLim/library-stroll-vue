<?php

namespace App\Actions\Artwork;

use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Models\Collection;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateArtwork
{
    public function handle(Artwork $artwork, User $actor, array $data): Artwork
    {
        return DB::transaction(function () use ($artwork, $actor, $data) {
            $fields = [
                'title',
                'description',
                'is_adult',
                'has_ai',
                'is_private',
                'allow_download',
                'allow_comments',
            ];

            $artwork->fill(collect($data)->only($fields)->all());
            $artwork->save();

            if (array_key_exists('tags', $data)) {
                $tagIds = collect($data['tags'])
                    ->map(fn ($name) => trim($name))
                    ->filter()
                    ->unique(fn ($name) => mb_strtolower($name))
                    ->map(fn ($name) => Tag::firstOrCreate(['name' => $name])->id)
                    ->all();

                $artwork->tags()->sync($tagIds);
            }

            if (array_key_exists('collections', $data)) {
                $ownedCollectionIds = Collection::where('user_id', $actor->id)->pluck('id');
                $artwork->collections()->detach($ownedCollectionIds);
                $artwork->collections()->syncWithoutDetaching($data['collections']);
            }

            $artwork->load('media', 'tags', 'collections');

            return $artwork;
        });

        $this->maybeDispatchReindex($artwork, $data);

        return $artwork;
    }

    private function maybeDispatchReindex(Artwork $artwork, array $data): void
    {
        if (! config('ai.enabled') || ! config('ai.auto_reindex_on_update')) {
            return;
        }

        if (! $artwork->is_published) {
            return;
        }

        $relevantKeys = ['title', 'description', 'tags'];
        $hasRelevantChange = collect($relevantKeys)->contains(fn ($key) => array_key_exists($key, $data));

        if (! $hasRelevantChange) {
            return;
        }

        $media = $artwork->getFirstMedia('artworks');
        $contentHash = md5(implode('|', [
            $artwork->title, $artwork->description, $media?->uuid,
        ]));

        $existing = $artwork->aiMetadata;
        if ($existing && $existing->content_hash === $contentHash) {
            return;
        }

        ArtworkAiMetadata::updateOrCreate(
            ['artwork_id' => $artwork->id],
            ['status' => ArtworkAiMetadata::STATUS_PENDING],
        );

        AnalyzeAndIndexArtworkJob::dispatch(
            $artwork->id, $contentHash, $media?->uuid,
        );
    }
}
