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
    /* --- ajax для модального окна --- */
    public function suggestions(Request $request)
    {
        $q    = trim($request->input('q',''));
        $type = $request->input('type','all');

        if ($q === '') {
            return response()->json(['suggestions' => []]);
        }

        $out = collect();

        /* --- Теги --- */
        if (in_array($type,['all','tag'])) {
            Tag::where('name','like',"%{$q}%")
                ->take(6)
                ->get()
                ->each(fn($t) => $out->push([
                    'id'   => $t->id,
                    'name' => $t->name,
                    'type' => 'tag',
                ]));
        }

        /* --- Авторы --- */
        if (in_array($type,['all','author'])) {
            User::where('name','like',"%{$q}%")
                ->take(6)
                ->get(['id','name','profile_photo_path'])
                ->each(fn($u) => $out->push([
                    'id'     => $u->id,
                    'name'   => $u->name,
                    'avatar' => $u->profile_photo_url,
                    'type'   => 'author',
                ]));
        }

        /* --- Артворки --- */
        if (in_array($type,['all','artwork'])) {
            Artwork::where('is_published',true)
                ->where('title','like',"%{$q}%")
                ->with('media')
                ->take(6)
                ->get()
                ->each(fn($a) => $out->push([
                    'id'    => $a->id,
                    'name'  => $a->title ?: 'Без названия',
                    'thumb' => $a->thumb_url,
                    'type'  => 'artwork',
                ]));
        }

        return response()->json(['suggestions' => $out->values()]);
    }

    /* --- Поиск ---  */
    public function index(Request $request)
    {
        $q = $request->get('q','');
        $userId = Auth::id();

        /*  --- Артворки --- */
        $artworks = Artwork::query()
            ->when($q, fn($qB)=>$qB->where('title','like',"%$q%"))
            ->with(['user','media','likes','collections'])
            ->withCount('likes')
            ->take(60)
            ->get()
            ->map(fn($a)=>$this->markUserData($a,$userId));

        /* --- Авторы --- */
        $authors = User::query()
            ->when($q, fn($qB)=>$qB->where('name','like',"%$q%"))
            ->take(60)
            ->get(['id','name','profile_photo_path']);

        /* --- Теги --- */
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

        /* --- Рекомендации --- */
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

