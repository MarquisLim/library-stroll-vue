<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class GalleryController extends Controller
{
    public function index()
    {
        $userId = Auth::id();

        $artworks = Artwork::where('is_published', true)
            ->orderByDesc('created_at')
            ->with(['media', 'user', 'likes', 'collections' => function($q) use ($userId) {
                if($userId){
                    $q->where('user_id', $userId);
                }
            }])
            ->withCount('likes') // Добавляем likes_count
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

        $collections = [];
        if(Auth::check()){
            $collections = Collection::where('user_id', Auth::id())->get();
        }

        return inertia('Gallery/GalleryIndex',[
            'artworks' => $artworks,
            'collections' => $collections
        ]);
    }

    public function loadMore(Request $request)
    {
        $page = $request->page ?? 2;
        $perPage = 40;
        $userId = Auth::id();

        $artworks = Artwork::where('is_published', true)
            ->with(['media', 'user', 'likes', 'collections' => function($q) use ($userId) {
                if($userId){
                    $q->where('user_id', $userId);
                }
            }])
            ->withCount('likes') // Добавляем likes_count
            ->orderByDesc('created_at')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->map(function($artwork) use ($userId) {
                $artwork->liked_by_user = $userId ? $artwork->likes->where('user_id', $userId)->isNotEmpty() : false;
                $artwork->in_collections = $userId ? $artwork->collections->pluck('id')->toArray() : [];
                return $artwork;
            });

        return response()->json(['artworks' => $artworks]);
    }
}
