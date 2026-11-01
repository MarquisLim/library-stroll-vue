<?php

namespace App\Http\Controllers\Messenger;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Artwork;
use App\Models\Conversation;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    public function index(Conversation $conversation, Request $request)
    {
        $this->authorize('view', $conversation);

        $request->validate(['before_id' => 'nullable|integer|min:1']);

        $messages = $conversation->messages()
            ->with([
                'user',
                'attachments',
                'reactions',
                'replyTo' => fn ($query) => $query->where('conversation_id', $conversation->id),
                'replyTo.user:id,name,profile_photo_path',
                'replyTo.attachments',
                'replyTo.artwork:id,title',
                'replies' => fn ($query) => $query->where('conversation_id', $conversation->id),
                'artwork' => function($q) {
                    $q->with(['media','user','likes','collections'])
                        ->withCount('likes');
                    },
                ])
            ->where('id','<',$request->input('before_id', PHP_INT_MAX))
            ->take(50)
            ->latest('id')->get()->reverse()->values();

        return $messages;
    }

    public function store(Request $request, Conversation $conversation)
    {
        $this->authorize('sendMessage', $conversation);

        $data = $request->validate([
            'body' => 'nullable|required_without_all:attachments,artwork_id|string|max:10000',
            'reply_to_id' => ['nullable', 'integer', Rule::exists('messages', 'id')->where('conversation_id', $conversation->id)],
            'attachments' => 'sometimes|array|max:20',
            'attachments.*' => ['required', 'integer', 'distinct', Rule::exists('message_attachments', 'id')
                ->where('user_id', $request->user()->id)->whereNull('message_id')],
            'artwork_id' => ['nullable', 'integer', Rule::exists('artworks', 'id')],
        ]);

        if (! empty($data['artwork_id']) && ! Artwork::publiclyVisible()->whereKey($data['artwork_id'])->exists()) {
            throw ValidationException::withMessages(['artwork_id' => 'Эта работа недоступна для отправки.']);
        }

        $msg = DB::transaction(function () use ($data, $conversation, $request) {
            $msg = $conversation->messages()->create([
                'user_id'         => $request->user()->id,
                'body'            => $data['body'] ?? null,
                'reply_to_id'     => $data['reply_to_id'] ?? null,
                'has_attachments' => !empty($data['attachments']),
                'artwork_id'      => $data['artwork_id'] ?? null,
            ]);

            $conversation->users()
                ->updateExistingPivot($request->user()->id, [
                    'last_read_id' => $msg->id,
                    'last_read_at' => now(),
                ]);

            if (!empty($data['attachments'])) {
                $attached = MessageAttachment::whereIn('id', $data['attachments'])
                    ->where('user_id', $request->user()->id)
                    ->whereNull('message_id')
                    ->update(['message_id' => $msg->id]);

                if ($attached !== count($data['attachments'])) {
                    throw ValidationException::withMessages(['attachments' => 'Одно из вложений уже использовано или недоступно.']);
                }
            }

            $conversation->forceFill(['last_message_id' => $msg->id])->save();

            return $msg;
        });

        $msg->load('user', 'attachments', 'reactions',
            'artwork.media',
            'replyTo.user:id,name,profile_photo_path',
            'replyTo.attachments',
            'replyTo.artwork:id,title',
            'replies',
            'artwork.user:id,name,profile_photo_path');

        if (is_null($msg->attachments)) {
            $msg->setRelation('attachments', collect());
        }

        broadcast(new MessageSent($msg));

        return $msg;
    }

}

