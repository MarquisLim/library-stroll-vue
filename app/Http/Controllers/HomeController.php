<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;


class HomeController extends Controller
{
    public function index()
    {
        $userId = Auth::id();

        $recentArtworks = Cache::remember("home:recentArtworks:{$userId}", 300, function() use ($userId) {
            return   Artwork::where('is_published', true)
                ->where('is_private', false)
                ->with(['media', 'user', 'likes', 'collections' => function($q) use ($userId) {
                    if($userId){
                        $q->where('user_id', $userId);
                    }
                }])
                ->withCount('likes')
                ->orderByDesc('updated_at')
                ->take(20)
                ->get()
                ->map(function($artwork) use ($userId) {
                    $artwork->liked_by_user = $userId
                        ? $artwork->likes->pluck('user_id')->contains($userId)
                        : false;
                    if ($first = $artwork->media->first()) {
                        $first->thumbnail_url = $first->getUrl('thumb');
                    }
                    return $artwork;
                });
        });

        $collections = Collection::where('user_id', $userId)
            ->with(['artworks' => function($q) {
                $q->where('is_published', true)->with('media');
            }])
            ->get();

        return Inertia::render('Home', [
            'recentArtworks' => $recentArtworks,
            'collections' => $collections,
        ]);
    }

}
