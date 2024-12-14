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
        $artwork = Artwork::with('media','tags','user')->findOrFail($id);
        $author = $artwork->user;
        $collections = Collection::where('user_id',Auth::id())->get();

        return Inertia::render('Artworks/ArtworksShow',[
            'artwork'=>$artwork,
            'author'=>$author,
            'collections'=>$collections
        ]);
    }

    public function addToCollection(Request $request, $id)
    {
        $artwork = Artwork::findOrFail($id);
        $collections = $request->collections ?? [];
        // Привязать artwork к коллекциям (artwork_collection pivot)
        foreach($collections as $colId){
            $artwork->collections()->syncWithoutDetaching($colId);
        }
        return response()->json(['message'=>'Added to collection']);
    }

    public function like(Request $request, $id)
    {
        $artwork = Artwork::findOrFail($id);
        // Допустим у нас есть модель Like
        $exists = $artwork->likes()->where('user_id',Auth::id())->exists();
        if($exists){
            $artwork->likes()->where('user_id',Auth::id())->delete();
        } else {
            $artwork->likes()->create(['user_id'=>Auth::id()]);
        }
        return response()->json(['likes_count'=>$artwork->likes()->count()]);
    }
}
