<?php

namespace App\Services\Search;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class AssistantArtworkSearch
{
    private const CANDIDATE_LIMIT = 20;

    public function __construct(private HybridSearchService $search) {}

    /** @return Collection<int, Artwork> */
    public function retrieve(string $query, User $user, bool $withMetadata = false): Collection
    {
        if (config('ai.assistant_lexical_metadata_enabled')) {
            $documents = config('ai.assistant_colbert_post_rrf_enabled')
                ? fn (Collection $ids) => $this->visible($ids, $user, withMetadata: true)
                : null;
            $candidates = $this->search->search($query, ['type' => 'image'], page: 1, perPage: self::CANDIDATE_LIMIT,
                assistantVisibility: fn (Collection $ids) => $this->visible($ids, $user)->pluck('id'), assistantDocuments: $documents);
        } else {
            $candidates = $this->search->search($query, ['type' => 'image'], page: 1, perPage: self::CANDIDATE_LIMIT);
        }
        $ids = $candidates['artworks']->take(self::CANDIDATE_LIMIT)->pluck('id')->unique();

        $trace = AssistantSearchDiagnostics::current();
        $started = $trace ? hrtime(true) : 0;
        $visible = $this->visible($ids, $user, $withMetadata);
        if ($trace) {
            $trace->stage('assistant_visibility', $started, $visible->count(), ['input_count' => $ids->count()]);
            foreach ($visible as $work) {
                $trace->candidate($work->id, ['retrieved' => true]);
            }
            foreach ($ids->diff($visible->pluck('id')) as $id) {
                $trace->exclude($id, 'assistant_visibility_or_media');
            }
        }

        return $visible;
    }

    /** @return Collection<int, Artwork> */
    public function visible(Collection $ids, User $user, bool $withMetadata = false): Collection
    {
        $query = Artwork::publiclyVisible()
            ->useWritePdo()
            ->where('type', 'image')
            ->where('ai_indexing_enabled', true)
            ->whereKey($ids->all())
            ->with(['media' => fn ($query) => $query->useWritePdo()->where('collection_name', 'artworks')]);
        if ($withMetadata) {
            $query->with([
                'aiMetadata' => fn ($query) => $query->useWritePdo(),
                'provenance' => fn ($query) => $query->useWritePdo()->select('id', 'artwork_id', 'creator_name'),
                'tags' => fn ($query) => $query->useWritePdo()->orderBy('name'),
            ]);
        }

        $artworks = $query->get()->keyBy('id');
        $gate = Gate::forUser($user);

        return $ids->map(fn ($id) => $artworks->get($id))
            ->filter(function (?Artwork $artwork) use ($gate): bool {
                if ($artwork === null || ! $gate->allows('view', $artwork)) {
                    return false;
                }

                $media = $artwork->getFirstMedia('artworks');

                return $media !== null && str_starts_with((string) $media->mime_type, 'image/')
                    && Storage::disk($media->disk)->fileExists($media->getPathRelativeToRoot());
            })
            ->values();
    }

    public static function card(Artwork $artwork): array
    {
        return [
            'artwork_id' => $artwork->id,
            'title' => $artwork->title,
            'url' => route('artworks.show', ['artwork' => $artwork->id], absolute: false),
        ];
    }
}
