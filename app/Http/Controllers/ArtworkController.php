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
        $artwork = Artwork::with('media','user')->findOrFail($id);
        // increment views
        $artwork->increment('views_count');
        $artwork->refresh();

        $artwork->liked_by_user = Auth::check() ? $artwork->likes()->where('user_id',Auth::id())->exists() : false;
        $artwork->in_collections = Auth::check()
            ? $artwork->collections()->where('user_id',Auth::id())->pluck('id')->toArray()
            : [];

        $author = $artwork->user;
        $collections = Auth::check() ? Collection::where('user_id',Auth::id())->get() : [];

        return inertia('Artworks/ArtworksShow',[
            'artwork'=>$artwork,
            'author'=>$author,
            'collections'=>$collections,
        ]);
    }

    public function like(Request $request, $id)
    {
        if(!Auth::check()){
            return response()->json(['error'=>'Not authorized'],403);
        }

        $artwork = Artwork::findOrFail($id);
        $exists = $artwork->likes()->where('user_id',Auth::id())->exists();
        if($exists){
            $artwork->likes()->where('user_id',Auth::id())->delete();
            // Отправить уведомление автору, что dislike?
        } else {
            $artwork->likes()->create(['user_id'=>Auth::id()]);
            // Отправить уведомление автору о лайке
            $author=$artwork->user;
            if($author->id!==Auth::id()){
                $author->notify(new ArtworkLikedNotification(Auth::user(),$artwork));
            }
        }
        return response()->json([
            'likes_count'=>$artwork->likes()->count(),
            'liked'=>!$exists
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
        $perPage=10;
        $artworks = Artwork::where('user_id',$id)
            ->where('is_published',true)
            ->with('media','user')
            ->skip(($page-1)*$perPage)
            ->take($perPage)
            ->get();
        if(Auth::check()){
            foreach($artworks as $a){
                $a->liked_by_user=$a->likes()->where('user_id',Auth::id())->exists();
                $a->in_collections=$a->collections()->where('user_id',Auth::id())->pluck('id')->toArray();
            }
        }
        return response()->json(['artworks'=>$artworks]);
    }


}
