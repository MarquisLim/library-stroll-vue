<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class BlockController extends Controller
{
    public function block(User $user)
    {
        $me = auth()->user();

        if ($me->id === $user->id) {
            return response()->json(['message' => 'Нельзя заблокировать себя'], 422);
        }

        $me->blockedUsers()->syncWithoutDetaching([$user->id]);

        return response()->json(['message' => 'Пользователь заблокирован']);
    }

    public function unblock(User $user)
    {
        $me = auth()->user();

        $me->blockedUsers()->detach($user->id);

        return response()->json(['message' => 'Пользователь разблокирован']);
    }

}
