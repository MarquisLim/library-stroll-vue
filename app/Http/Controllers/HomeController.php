<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class HomeController extends Controller
{
    /* ---Главная страница--- */
    public function index()
    {
        $userId = Auth::id();

        // Cache only scalar IDs. Reload visibility and personal state on every visit.
        $recentIds = Cache::remember('home:recentArtworkIds:v1', 300, fn () => Artwork::publiclyVisible()
            ->orderByDesc('published_at')
            ->take(20)
            ->pluck('id')
            ->all());

        $recentArtworks = Artwork::publiclyVisible()
            ->whereIn('id', $recentIds)
            ->with(['media', 'user', 'likes', 'collections' => function ($query) use ($userId) {
                $query->where('user_id', $userId);
            }])
            ->withCount('likes')
            ->orderByDesc('published_at')
            ->get()
            ->map(function ($artwork) {
                if ($first = $artwork->media->first()) {
                    $first->thumbnail_url = $first->getUrl('thumb');
                }

                return $artwork;
            });

        $collections = Collection::where('user_id', $userId)
            ->with(['artworks' => function ($q) {
                $q->where('is_published', true)->with('media');
            }])
            ->get();

        return Inertia::render('Home', [
            'recentArtworks' => $recentArtworks,
            'collections' => $collections,
        ]);
    }
}
