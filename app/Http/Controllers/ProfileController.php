<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProfileController extends Controller
{
    public function show($userId){
        $user = User::findOrFail($userId);
        $collections = Collection::where('user_id',$userId)->get();
        $artworks = Artwork::where('user_id',$userId)->where('is_published',true)->with('media','user')->take(20)->get();

        return Inertia::render('Profile/ProfileShow',[
            'profileUser'=>$user,
            'collections'=>$collections,
            'artworks'=>$artworks
        ]);
    }
}
