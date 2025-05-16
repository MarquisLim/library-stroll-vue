<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ArtworkController extends Controller
{
    public function show($id)
    {
        $artwork = Artwork::with(['media', 'user', 'tags', 'likes', 'collections'])
            ->withCount('comments', 'likes')
            ->findOrFail($id);

        if ($artwork->is_private && (!Auth::check() || Auth::id() !== $artwork->user_id)) {
            abort(403, 'Вы не можете просматривать этот арт');
        }

        $myChats = auth()->user()
            ->conversations()
            ->with('users')
            ->get()
            ->map(function ($c) {
                $partner = $c->type === 'dialog'
                    ? $c->users->firstWhere('id', '!=', auth()->id())
                    : null;

                return [
                    'id' => $c->id,
                    'title' => $c->type === 'dialog'
                        ? ($partner->name ?? '—')
                        : ($c->title ?: 'Группа'),
                    'avatar_url' => $c->type === 'dialog'
                        ? ($partner->profile_photo_url ?? '/images/default-avatar.png')
                        : ($c->users->first()?->profile_photo_url ?? '/images/default-avatar.png'),
                    'type' => $c->type,
                ];
            });

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
        return Inertia::render('Artworks/ArtworksShow', [
            'artwork' => $artwork,
            'author' => $author,
            'collections' => $collections,
            'myChats' => $myChats,
        ]);
    }

    public function similar($id, Request $request)
    {
        $page = (int)$request->get('page', 1);
        $perPage = (int)$request->get('per_page', 12);

        $art = Artwork::with('tags')->findOrFail($id);
        $tagIds = $art->tags->pluck('id');

        $query = Artwork::query()
            ->where('is_published', true)
            ->where('is_private', false)
            ->where('id', '!=', $art->id)
            ->when($tagIds->count(), fn($q) => $q->whereHas('tags', fn($t) => $t->whereIn('tags.id', $tagIds)))
            // если тегов нет - fallback по похожему названию
            ->when(!$tagIds->count() && $art->title,
                fn($q) => $q->where('title', 'like', '%' . $art->title . '%'))
            ->with(['media', 'user'])
            ->withCount('likes')
            ->orderBy('likes_count', 'desc');

        $total = $query->count();
        $similar = $query->skip(($page - 1) * $perPage)
            ->take($perPage)->get();

        // liked / in_collections
        if (Auth::check()) {
            foreach ($similar as $s) {
                $s->liked_by_user = $s->likes()->where('user_id', Auth::id())->exists();
                $s->in_collections = $s->collections()->where('user_id', Auth::id())->pluck('id');
            }
        }

        return response()->json([
            'artworks' => $similar,
            'hasMore' => $page * $perPage < $total,
        ]);
    }


    public function like(Request $request, $id)
    {
        $user = Auth::user();
        $artwork = Artwork::findOrFail($id);

        $liked = $artwork->likes()->where('user_id', $user->id)->exists();

        if ($liked) {
            $artwork->likes()->where('user_id', $user->id)->delete();
        } else {
            $artwork->likes()->create(['user_id' => $user->id]);
        }

        $likesCount = $artwork->likes()->count();

        $likedByUser = $artwork->likes()->where('user_id', $user->id)->exists();

        return response()->json([
            'likes_count' => $likesCount,
            'liked' => $likedByUser
        ]);
    }

    public function addToCollection(Request $request, $id)
    {
        if (!Auth::check()) {
            return response()->json(['error' => 'Not authorized'], 403);
        }

        $artwork = Artwork::findOrFail($id);
        $collections = $request->collections ?? [];
        $artwork->collections()->sync($collections);

        return response()->json(['message' => 'Added to collection', 'in_collections' => $artwork->collections()->where('user_id', Auth::id())->pluck('id')]);
    }

    public function authorWorks(Request $request, $id)
    {
        $page = $request->page ?? 1;
        $perPage = $request->per_page ?? 12;
        $excludeArtworkId = $request->exclude_artwork_id;

        $query = Artwork::where('user_id', $id)
            ->where('is_published', true)
            ->where('is_private', false);

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
