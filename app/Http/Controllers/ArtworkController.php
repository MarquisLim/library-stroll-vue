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
        $artwork = Artwork::with('media', 'user')
            ->withCount('comments') // Добавлено для получения comments_count
            ->findOrFail($id);

        // Увеличение количества просмотров
        $artwork->increment('views_count');
        $artwork->refresh();

        // Определение, поставил ли пользователь лайк
        $artwork->liked_by_user = Auth::check() ? $artwork->likes()->where('user_id', Auth::id())->exists() : false;

        // Определение, находится ли артворк в коллекциях пользователя
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
        $artwork->collections()->syncWithoutDetaching($collections);

        return response()->json(['message'=>'Added to collection','in_collections'=>$artwork->collections()->where('user_id',Auth::id())->pluck('id')]);
    }

    public function authorWorks(Request $request, $id)
    {
        $page = $request->page ?? 1;
        $perPage = 10;

        $artworks = Artwork::where('user_id', $id)
            ->where('is_published', true)
            ->with(['media', 'user'])
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
