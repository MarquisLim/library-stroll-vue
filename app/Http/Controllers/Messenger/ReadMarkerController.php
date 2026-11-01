<?php

namespace App\Http\Controllers\Messenger;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReadMarkerController extends Controller
{
    public function update(Conversation $conversation, Request $request)
    {
        $this->authorize('view', $conversation);

        $data = $request->validate([
            'message_id' => ['nullable', 'integer', Rule::exists('messages', 'id')->where('conversation_id', $conversation->id)],
        ]);
        $msgId = $data['message_id'] ?? $conversation->last_message_id;

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
