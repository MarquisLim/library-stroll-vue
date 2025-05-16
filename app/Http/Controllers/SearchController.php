<?php

namespace App\Http\Controllers;

use App\Models\Collection;
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
            $tags = Tag::withCount('artworks')
                ->orderByDesc('artworks_count')
                ->take(10)
                ->get(['id','name']);
            // Поиск по авторам (юзерам)
            $users = User::where('name','like',"%$q%")->take(5)->get(['id','name']);
            // Поиск по работам
            $artworks = Artwork::where('title','like',"%$q%")->take(5)->get(['id','title as name']);

            $suggestions = collect();
            $suggestions->push(...$tags->map(fn($t)=>[
                'id'=>$t->id,
                'name'=>$t->name,
                'type'=>'tag',
                'hits' => $t->artworks_count]));
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
        $q = $request->get('q','');
        $userId = Auth::id();

        /* ---------- 1. Работы ---------- */
        $artworks = Artwork::query()
            ->when($q, fn($qB)=>$qB->where('title','like',"%$q%"))
            ->with(['user','media','likes','collections'])
            ->withCount('likes')
            ->take(60)
            ->get()
            ->map(fn($a)=>$this->markUserData($a,$userId));

        /* ---------- 2. Авторы ---------- */
        $authors = User::query()
            ->when($q, fn($qB)=>$qB->where('name','like',"%$q%"))
            ->take(60)
            ->get(['id','name','profile_photo_path']);

        /* ---------- 3. Теги ---------- */
        $tags = Tag::query()
            ->when($q, fn($qb) => $qb->where('name','like',"%$q%"))
            ->with(['artworks' => function($qArt) use ($userId) {
                $qArt->with(['user','media','likes','collections'])
                    ->withCount('likes')
                    ->take(6);
            }])
            ->withCount('artworks')
            ->orderByDesc('artworks_count')
            ->take(60)
            ->get()
            ->each(fn($tag) => $tag->artworks
                ->transform(fn($a) => $this->markUserData($a,$userId)));

        /* ---------- recommended ---------- */
        $recommended = !$q
            ? Artwork::with(['user','media'])->inRandomOrder()->take(30)->get()
                ->map(fn($a)=>$this->markUserData($a,$userId))
            : collect();

        $collections = Collection::where('user_id', Auth::id())
            ->with(['artworks' => function($q) {
                $q->where('is_published', true)->with('media');
            }])
            ->get();

        return Inertia::render('Search/Index', compact(
            'q','artworks','authors','tags','recommended','collections'
        ));
    }

    private function markUserData($art,$userId)
    {
        if(!$userId) return $art;
        $art->liked_by_user = $art->likes->where('user_id',$userId)->isNotEmpty();
        $art->in_collections = $art->collections->where('user_id',$userId)->pluck('id');
        return $art;
    }


}
