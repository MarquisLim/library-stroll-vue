<?php

namespace App\Actions\Artwork;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Tag;
use App\Models\User;
use App\Services\AiService\ArtworkIndexingService;
use Illuminate\Support\Facades\DB;

class UpdateArtwork
{
    public function handle(Artwork $artwork, User $actor, array $data): Artwork
    {
        $artwork = DB::transaction(function () use ($artwork, $actor, $data) {
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

        $relevantKeys = ['title', 'description', 'tags', 'is_private', 'has_ai'];
        $hasRelevantChange = collect($relevantKeys)->contains(fn ($key) => array_key_exists($key, $data));

        if (! $hasRelevantChange) {
            return;
        }

        app(ArtworkIndexingService::class)->dispatch($artwork);
    }
}
