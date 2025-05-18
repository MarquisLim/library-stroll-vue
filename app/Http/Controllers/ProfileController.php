<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ProfileController extends Controller
{
    public function show(User $user)
    {
        $userId = Auth::id();
        $isOwner = $userId === $user->id;

        $artworks = Artwork::where('user_id', $user->id)
            ->when(!$isOwner, function ($query) {
                $query->where('is_published', '!=', 0)
                    ->where('is_private', false);
            })
            ->with(['user', 'media', 'likes', 'collections'])
            ->withCount('likes')
            ->get()
            ->map(function($art) use ($userId) {
                $art->liked_by_user = $userId ? $art->likes->where('user_id', $userId)->isNotEmpty() : false;
                $art->in_collections = $userId ? $art->collections->pluck('id')->toArray() : [];
                return $art;
            });

        $collections = Collection::where('user_id', $user->id)
            ->when(!$isOwner, function ($query) {
                $query->where('is_private', false);
            })
            ->withCount('artworks')
            ->with(['artworks.media' => function($q) {
                $q->limit(3);
            }])
            ->get();

        // Коллекции текущего авторизованного пользователя
        $userCollections = Collection::where('user_id', Auth::id())
            ->with(['artworks' => function($q) {
                $q->where('is_published', true)->with('media');
            }])
            ->get();

        return inertia('Profile/ProfileShow', [
            'profileUser' => $user->only('id', 'name', 'profile_photo_url', 'created_at'),
            'artworks' => $artworks,
            'collections' => $collections,
            'isOwner' => $isOwner,
            'userCollections' => $userCollections
        ]);
    }


    public function likes(User $user)
    {
        $userId = Auth::id();

        $likedArtworks = Artwork::whereHas('likes', function($q) use ($user) {
            $q->where('user_id', $user->id);
        })
            ->with(['user','media','likes','collections'])
            ->withCount('likes')
            ->get()
            ->map(function($art) use ($userId) {
                $art->liked_by_user = $userId ? $art->likes->where('user_id', $userId)->isNotEmpty() : false;
                $art->in_collections = $userId ? $art->collections->pluck('id')->toArray() : [];
                return $art;
            });

        return response()->json($likedArtworks);
    }

}
