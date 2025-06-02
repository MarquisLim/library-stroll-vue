<?php

namespace App\Http\Controllers\Messenger;

use App\Events\MessagesSeen;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\Request;

class ReadMarkerController extends Controller
{
    public function update(Conversation $conversation, Request $request)
    {
        $this->authorize('view', $conversation);

        $msgId = (int) $request->input('message_id');

        $conversation->users()->updateExistingPivot(
            $request->user()->id,
            [
                'last_read_at' => now(),
                'last_read_id' => $msgId ?: $conversation->last_message_id,
            ]
        );

        return response()->noContent();
    }
}
