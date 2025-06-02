<?php

namespace App\Http\Controllers\Messenger;

use App\Models\Conversation;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;

class GroupAvatarController extends Controller
{

    public function update(Conversation $conversation, Request $request)
    {
        $user = Auth::user();
        $this->authorize('view', $conversation);

        $pivot = $conversation->users()->where('user_id', $user->id)->first()->pivot;
        if (! $pivot || $pivot->role !== 'admin') {
            abort(403);
        }

        $request->validate([
            'avatar' => 'required|file|image|max:5120',
        ]);

        if ($conversation->avatar) {
            Storage::disk('public')->delete($conversation->avatar);
        }

        $path = $request->file('avatar')->store('conversation_avatars', 'public');
        $conversation->avatar = $path;
        $conversation->save();

        return response()->json([
            'avatar_url' => $conversation->avatar_url,
        ]);
    }
}
