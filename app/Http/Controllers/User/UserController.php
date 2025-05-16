<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function search(Request $request)
    {
        $q = $request->input('q','');
        $users = User::where('name', 'like', "%{$q}%")
            ->take(10)
            ->get(['id','name','profile_photo_path']);
        // приводим к profile_photo_url
        return $users->map(fn($u) => [
            'id'   => $u->id,
            'name' => $u->name,
            'avatar'=> $u->profile_photo_url,
        ]);
    }
    
}
