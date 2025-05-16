<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Models\Collection;
use App\Models\Conversation;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    public function index(Conversation $conversation, Request $request)
    {
        $this->authorize('view', $conversation);

        $messages = $conversation->messages()
            ->with([
                'user',
                'attachments',
                'reactions',
                'artwork' => function($q) {
                    $q->with(['media','user','likes','collections'])
                        ->withCount('likes');
                    },
                ])
            ->where('id','<',$request->input('before_id', PHP_INT_MAX))
            ->latest('id')->take(50)->get()->reverse()->values();

        return $messages;
    }

    public function store(Request $request, Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        $data = $request->validate([
            'body'        => 'nullable|string|max:10000',
            'reply_to_id' => 'nullable|exists:messages,id',
            'attachments' => 'array',
            'artwork_id'  => 'nullable|exists:artworks,id',
        ]);

        $msg = DB::transaction(function () use ($data, $conversation, $request) {
            $msg = $conversation->messages()->create([
                'user_id'         => $request->user()->id,
                'body'            => $data['body'] ?? null,
                'reply_to_id'     => $data['reply_to_id'] ?? null,
                'has_attachments' => !empty($data['attachments']),
                'artwork_id'      => $data['artwork_id'] ?? null,
            ]);

            if (!empty($data['attachments'])) {
                MessageAttachment::whereIn('id', $data['attachments'])
                    ->whereNull('message_id')
                    ->update(['message_id' => $msg->id]);
            }

            $conversation->forceFill(['last_message_id' => $msg->id])->save();

            return $msg;
        });

        $msg->load('user', 'attachments', 'reactions',     'artwork.media',
            'artwork.user:id,name,profile_photo_path');

        if (is_null($msg->attachments)) {
            $msg->setRelation('attachments', collect());
        }

        broadcast(new MessageSent($msg));

        return $msg;
    }

}

