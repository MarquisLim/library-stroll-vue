<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Artwork;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class SearchController extends Controller
{
    public function suggestions(Request $request)
    {
        $q = $request->get('q');
        if ($q) {
            // Поиск по тегам
            $tags = Tag::where('name','like',"%$q%")->take(5)->get(['id','name']);
            // Поиск по авторам (юзерам)
            $users = User::where('name','like',"%$q%")->take(5)->get(['id','name']);
            // Поиск по работам
            $artworks = Artwork::where('title','like',"%$q%")->take(5)->get(['id','title as name']);

            $suggestions = collect();
            $suggestions->push(...$tags->map(fn($t)=>['id'=>$t->id,'name'=>$t->name,'type'=>'tag']));
            $suggestions->push(...$users->map(fn($u)=>['id'=>$u->id,'name'=>$u->name,'type'=>'author']));
            $suggestions->push(...$artworks->map(fn($a)=>['id'=>$a->id,'name'=>$a->name,'type'=>'artwork']));

            $suggestions = $suggestions->take(10)->values();
        } else {
            // Рекомендуемые - популярные теги или случайные работы
            $tags = Tag::orderBy('popularity','desc')->take(10)->get(['id','name']);
            $suggestions = $tags->map(fn($t)=>['id'=>$t->id,'name'=>$t->name,'type'=>'tag']);
        }

        return response()->json(['suggestions'=>$suggestions]);
    }

    public function index(Request $request)
    {
        $q = $request->get('q', '');
        $userId = Auth::id();

        $artworks = collect();
        $authors = collect();
        $tagResults = collect();

        if ($q) {
            // Ищем работы
            $artworks = Artwork::with(['user','media','likes','collections'])
                ->withCount('likes')
                ->where('title','like',"%$q%")
                ->take(20)->get();

            // Помечаем лайкнутость и коллекции
            if ($userId) {
                foreach ($artworks as $a) {
                    $a->liked_by_user = $a->likes->where('user_id',$userId)->isNotEmpty();
                    $a->in_collections = $a->collections->where('user_id',$userId)->pluck('id')->toArray();
                }
            }

            // Авторы
            $authors = User::where('name','like',"%$q%")->take(20)->get();

            // Теги
            $foundTags = Tag::where('name','like',"%$q%")->take(5)->get();
            $tagArtworks = collect();
            foreach ($foundTags as $tag) {
                $tagArt = $tag->artworks()->with(['user','media','likes','collections'])->withCount('likes')->take(10)->get();
                if ($userId) {
                    foreach ($tagArt as $ta) {
                        $ta->liked_by_user = $ta->likes->where('user_id',$userId)->isNotEmpty();
                        $ta->in_collections = $ta->collections->where('user_id',$userId)->pluck('id')->toArray();
                    }
                }
                $tagArtworks = $tagArtworks->concat($tagArt);
            }
            $tagResults = $tagArtworks->unique('id')->values();
        }

        // Если нет q или ничего не найдено, покажем рекомендованные (случайные)
        $recommended = collect();
        if(!$q || ($artworks->isEmpty() && $authors->isEmpty() && $tagResults->isEmpty())) {
            $recommended = Artwork::with(['user','media','likes','collections'])
                ->withCount('likes')
                ->inRandomOrder()->take(12)->get();
            if ($userId) {
                foreach ($recommended as $r) {
                    $r->liked_by_user = $r->likes->where('user_id',$userId)->isNotEmpty();
                    $r->in_collections = $r->collections->where('user_id',$userId)->pluck('id')->toArray();
                }
            }
        }

        $collections = [];
        if ($userId) {
            $collections = \App\Models\Collection::where('user_id',$userId)->get();
        }

        return Inertia::render('Search/Index', [
            'query' => $q,
            'artworks' => $artworks,
            'authors' => $authors,
            'tagResults' => $tagResults,
            'recommended' => $recommended,
            'collections' => $collections
        ]);
    }

}
