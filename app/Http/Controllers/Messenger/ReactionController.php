<?php

namespace App\Http\Controllers\Messenger;

use App\Events\ReactionUpdated;
use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\Request;

class ReactionController extends Controller
{
    public function toggle(Message $message, Request $request)
    {
        $emoji = $request->validate(['emoji'=>'required|string|max:16'])['emoji'];
        $user  = $request->user();

        $exists = $message->reactions()
            ->where('user_id', $user->id)
            ->where('emoji', $emoji)
            ->exists();

        if ($exists) {
            $message->reactions()
                ->where('user_id', $user->id)
                ->where('emoji', $emoji)
                ->delete();
        } else {
            $message->reactions()->create([
                'user_id' => $user->id,
                'emoji'   => $emoji,
            ]);
        }

        // обновлённый список реакций
        $payload = $message->reactions()->get();
        broadcast(new ReactionUpdated($message->id, $payload));

        return $payload;
    }
}

