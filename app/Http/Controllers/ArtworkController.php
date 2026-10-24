<?php

namespace App\Http\Controllers;

use App\Contracts\AiServiceClient;
use App\Http\Requests\AddArtworkToCollectionsRequest;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Models\Collection;
use App\Models\Models\Complaint\ComplaintType;
use App\Notifications\ArtworkLiked;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ArtworkController extends Controller
{
    public function show($id)
    {
        $artwork = Artwork::with(['media', 'user', 'tags', 'likes', 'collections'])
            ->where('is_published', true)
            ->withCount('comments', 'likes')
            ->findOrFail($id);

        $this->authorize('view', $artwork);

        if (Auth::check()) {
            $myChats = Auth::user()
                ->conversations()
                ->with('users')
                ->get()
                ->map(function ($c) {
                    $partner = $c->type === 'dialog'
                        ? $c->users->firstWhere('id', '!=', Auth::id())
                        : null;

                    return [
                        'id'         => $c->id,
                        'title'      => $c->type === 'dialog'
                            ? ($partner->name ?? '—')
                            : ($c->title ?: 'Группа'),
                        'avatar_url' => $c->type === 'dialog'
                            ? ($partner->profile_photo_url ?? '/images/default-avatar.png')
                            : ($c->users->first()?->profile_photo_url ?? '/images/default-avatar.png'),
                        'type'       => $c->type,
                    ];
                });
        } else {
            $myChats = collect();
        }

        $artwork->increment('views_count');
        $artwork->refresh();
        $artwork->loadCount('comments', 'likes');

        $artwork->liked_by_user = Auth::check() ? $artwork->likes()->where('user_id', Auth::id())->exists() : false;
        $artwork->in_collections = Auth::check()
            ? $artwork->collections()->where('user_id', Auth::id())->pluck('id')->toArray()
            : [];

        $author = $artwork->user;
        $collections = Collection::where('user_id', Auth::id())
            ->with(['artworks' => function ($q) {
                $q->where('is_published', true)->with('media');
            }])
            ->get();
        $complaintTypes = ComplaintType::select('slug', 'name')->get();
        return Inertia::render('Artworks/ArtworksShow', [
            'artwork' => $artwork,
            'author' => $author,
            'collections' => $collections,
            'myChats' => $myChats,
            'complaintTypes' => $complaintTypes,
        ]);
    }

    public function similar($id, Request $request)
    {
        $page = max(1, (int) $request->get('page', 1));
        $perPage = max(1, min(50, (int) $request->get('per_page', 12)));

        $art = Artwork::with('tags')->findOrFail($id);
        $this->authorize('view', $art);

        $similar = $this->semanticSimilar($art, $perPage, $page);

        if ($similar === null) {
            $similar = $this->tagBasedSimilar($art, $perPage, $page);
        }

        if (Auth::check()) {
            foreach ($similar['artworks'] as $s) {
                $s->liked_by_user = $s->likes()->where('user_id', Auth::id())->exists();
                $s->in_collections = $s->collections()->where('user_id', Auth::id())->pluck('id');
            }
        }

        return response()->json([
            'artworks' => $similar['artworks'],
            'hasMore' => $similar['hasMore'],
        ]);
    }

    private function semanticSimilar(Artwork $art, int $perPage, int $page): ?array
    {
        if (! config('ai.enabled')) {
            return null;
        }

        $metadata = $art->aiMetadata;
        if (! $metadata || ! $metadata->isIndexed()) {
            return null;
        }

        try {
            $aiClient = app(AiServiceClient::class);
            $result = $aiClient->similar($art->id, limit: 100);
            $candidateIds = $result->artworkIds();

            if (empty($candidateIds)) {
                return null;
            }

            $artworks = Artwork::publiclyVisible()
                ->whereIn('id', $candidateIds)
                ->where('id', '!=', $art->id)
                ->with(['media', 'user'])
                ->withCount('likes')
                ->get()
                ->sortBy(function ($artwork) use ($candidateIds) {
                    return array_search($artwork->id, $candidateIds);
                })
                ->values();

            return [
                'artworks' => $artworks->forPage($page, $perPage)->values(),
                'hasMore' => $artworks->count() > $page * $perPage,
            ];
        } catch (\Throwable $e) {
            Log::warning('Semantic similar failed, falling back to tag-based.', [
                'artwork_id' => $art->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function tagBasedSimilar(Artwork $art, int $perPage, int $page): array
    {
        $tagIds = $art->tags->pluck('id');

        $query = Artwork::publiclyVisible()
            ->where('id', '!=', $art->id)
            ->when($tagIds->count(), fn ($q) => $q->whereHas('tags', fn ($t) => $t->whereIn('tags.id', $tagIds)))
            ->when(! $tagIds->count() && $art->title,
                fn ($q) => $q->where('title', 'like', '%' . $art->title . '%'))
            ->with(['media', 'user'])
            ->withCount('likes')
            ->orderBy('likes_count', 'desc');

        $total = $query->count();
        $artworks = $query->skip(($page - 1) * $perPage)
            ->take($perPage)->get();

        return [
            'artworks' => $artworks,
            'hasMore' => $page * $perPage < $total,
        ];
    }


    public function like(Request $request, $id)
    {
        $user = Auth::user();
        $artwork = Artwork::findOrFail($id);
        $this->authorize('view', $artwork);

        $liked = $artwork->likes()->where('user_id', $user->id)->exists();

        if ($liked) {
            $artwork->likes()->where('user_id', $user->id)->delete();
        } else {
            $artwork->likes()->create(['user_id' => $user->id]);
            $artwork->user->notify(new ArtworkLiked($artwork, $user));
        }

        $likesCount = $artwork->likes()->count();

        $likedByUser = $artwork->likes()->where('user_id', $user->id)->exists();

        return response()->json([
            'likes_count' => $likesCount,
            'liked' => $likedByUser
        ]);
    }

    public function addToCollection(AddArtworkToCollectionsRequest $request, $id)
    {
        $artwork = Artwork::findOrFail($id);
        $this->authorize('view', $artwork);

        $selectedIds = collect($request->validated('collections', []))
            ->map(fn ($id) => (int) $id)
            ->all();

        DB::transaction(function () use ($artwork, $selectedIds, $request) {
            $ownedCollectionIds = Collection::where('user_id', $request->user()->id)
                ->pluck('id');

            $artwork->collections()->detach($ownedCollectionIds);
            $artwork->collections()->syncWithoutDetaching($selectedIds);
        });

        return response()->json([
            'message' => 'Added to collection',
            'in_collections' => $artwork->collections()
                ->where('user_id', $request->user()->id)
                ->pluck('collections.id'),
        ]);
    }

    public function authorWorks(Request $request, $id)
    {
        $page = $request->page ?? 1;
        $perPage = $request->per_page ?? 12;
        $excludeArtworkId = $request->exclude_artwork_id;

        $query = Artwork::publiclyVisible()->where('user_id', $id);

        if ($excludeArtworkId) {
            $query->where('id', '!=', $excludeArtworkId);
        }

        $artworks = $query
            ->with(['media', 'user'])
            ->withCount('likes')
            ->orderBy('created_at', 'desc')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        if (Auth::check()) {
            foreach ($artworks as $a) {
                $a->liked_by_user = $a->likes()->where('user_id', Auth::id())->exists();
                $a->in_collections = $a->collections()->where('user_id', Auth::id())->pluck('id')->toArray();
            }
        }

        return response()->json(['artworks' => $artworks]);
    }
}
