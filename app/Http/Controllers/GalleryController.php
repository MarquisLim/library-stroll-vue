<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class GalleryController extends Controller
{
    public function index()
    {
        $userId = Auth::id();

        $artworks = Artwork::where('is_published', true)
            ->orderByDesc('updated_at')
            ->with(['media', 'user', 'likes', 'collections' => function($q) use ($userId) {
                if($userId){
                    $q->where('user_id', $userId);
                }
            }])
            ->withCount('likes')
            ->take(20)
            ->get()
            ->map(function($artwork) use ($userId) {
                $artwork->liked_by_user = $userId ? $artwork->likes->where('user_id', $userId)->isNotEmpty() : false;
                $artwork->in_collections = $userId ? $artwork->collections->pluck('id')->toArray() : [];
                $artwork->media->map(function($mediaItem) {
                    $mediaItem->thumbnail_url = $mediaItem->getUrl('thumb');
                    return $mediaItem;
                });
                return $artwork;
            });

        $collections = Collection::where('user_id', Auth::id())
            ->with(['artworks' => function($q) {
                $q->where('is_published', true)->with('media');
            }])
            ->get();

        $popularTags = Tag::withCount('artworks')
            ->with(['artworks' => function($q){
                $q->with('media')->take(1);
            }])
            ->orderByDesc('artworks_count')
            ->take(20)
            ->get()
            ->map(fn($tag)=>[
                'id'   => $tag->id,
                'name' => $tag->name,
                'thumb'=> optional(
                    optional($tag->artworks->first())->media->first()
                )->getUrl('thumb')
            ]);

        return inertia('Gallery/GalleryIndex',[
            'artworks' => $artworks,
            'collections' => $collections,
            'popularTags' => $popularTags
        ]);
    }

    public function loadMore(Request $r)
    {
        $perPage = 20;
        $page    = max((int)$r->input('page', 1), 1);

        /* --------- фильтры из query-строки --------- */
        $category = $r->input('category', 'all');
        $ai       = $r->input('ai',        null);
        $sort     = $r->input('sort',      'popular');
        $tagName  = $r->input('tag',       null);

        $userId = Auth::id();

        $q = Artwork::query()
            ->where(fn($query) =>
            $query->where('is_published', true)
                ->orWhere(fn($q2) =>
                $q2->where('user_id', $userId)
                    ->where('is_private', false)
                )
            );

        /* --- 1. Category --- */
        if ($category === 'images') $q->where('type', 'image');
        if ($category === 'videos') $q->where('type', 'video');

        /* --- 2. AI --- (boolean `has_ai`) */
        if ($ai !== null) $q->where('has_ai', (bool)$ai);

        /* --- 3. Tag --- */
        if ($tagName && $tagName !== 'all') {
            $q->whereHas('tags', fn($t) => $t->where('name', $tagName));
        }

        /* --- 4. Sort --- */
        match ($sort) {
            'views'   => $q->orderByDesc('views_count'),
            'latest'  => $q->orderByDesc('updated_at'),
            default   => $q->orderByDesc('likes_count'), // popular
        };

        /* --- 5. Scroll Infinity --- */
        $artworks = $q->with(['media', 'user', 'likes',
            'collections' => fn($c) => $c->where('user_id', $userId)])
            ->withCount('likes')
            ->skip(($page-1)*$perPage)
            ->take($perPage)
            ->get()
            ->each(function($a) use($userId){
                $a->liked_by_user = $userId && $a->likes->where('user_id',$userId)->isNotEmpty();
                $a->in_collections= $userId ? $a->collections->pluck('id') : [];
            });

        return response()->json(['artworks'=>$artworks]);
    }
}
