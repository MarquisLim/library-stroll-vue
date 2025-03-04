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
        $artwork = Artwork::with(['media','user','tags','likes','collections'])
            ->withCount('comments','likes')
            ->findOrFail($id);

        // Увеличение просмотров
        $artwork->increment('views_count');
        $artwork->refresh();
        $artwork->loadCount('comments', 'likes');

        $artwork->liked_by_user = Auth::check() ? $artwork->likes()->where('user_id', Auth::id())->exists() : false;
        $artwork->in_collections = Auth::check()
            ? $artwork->collections()->where('user_id', Auth::id())->pluck('id')->toArray()
            : [];

        $author = $artwork->user;
        $collections = Auth::check() ? Collection::where('user_id', Auth::id())->get() : [];

        return Inertia::render('Artworks/ArtworksShow', [
            'artwork' => $artwork,
            'author' => $author,
            'collections' => $collections,
        ]);
    }

    public function like(Request $request, $id)
    {
        $user = Auth::user();
        $artwork = Artwork::findOrFail($id);

        // Проверяем, поставил ли пользователь уже лайк
        $liked = $artwork->likes()->where('user_id', $user->id)->exists();

        if($liked){
            // Удаляем лайк
            $artwork->likes()->where('user_id', $user->id)->delete();
        } else {
            // Добавляем лайк
            $artwork->likes()->create(['user_id' => $user->id]);
        }

        // Получаем обновлённое количество лайков
        $likesCount = $artwork->likes()->count();

        // Проверяем, поставил ли пользователь лайк после действия
        $likedByUser = $artwork->likes()->where('user_id', $user->id)->exists();

        return response()->json([
            'likes_count' => $likesCount,
            'liked' => $likedByUser
        ]);
    }

    public function addToCollection(Request $request, $id)
    {
        if(!Auth::check()){
            return response()->json(['error'=>'Not authorized'],403);
        }

        $artwork = Artwork::findOrFail($id);
        $collections = $request->collections ?? [];
        $artwork->collections()->sync($collections);

        return response()->json(['message'=>'Added to collection','in_collections'=>$artwork->collections()->where('user_id',Auth::id())->pluck('id')]);
    }

    public function authorWorks(Request $request, $id)
    {
        $page = $request->page ?? 1;
        $perPage = $request->per_page ?? 12; // Используем значение per_page из запроса или 12 по умолчанию

        $artworks = Artwork::where('user_id', $id)
            ->where('is_published', true)
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
