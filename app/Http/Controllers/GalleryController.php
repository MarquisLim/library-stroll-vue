<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class GalleryController extends Controller
{
    public function index()
    {
        $artworks = Artwork::where('is_published',true)->with('media','user')->take(30)->get();
        $collections = Collection::where('user_id', Auth::id())->get(); // Если пользователь залогинен
        return Inertia::render('Gallery/GalleryIndex',[
            'artworks'=>$artworks,
            'collections'=>$collections
        ]);
    }


    public function loadMore(Request $request)
    {
        $page = $request->page ?? 2;
        $perPage=30;
        $artworks = Artwork::where('is_published',true)->with('media','user')
            ->skip(($page-1)*$perPage)
            ->take($perPage)
            ->get();
        return response()->json(['artworks'=>$artworks]);
    }
}
