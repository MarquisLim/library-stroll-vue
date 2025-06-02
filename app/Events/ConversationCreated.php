<?php

namespace App\Events;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;        // ← вот он
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Conversation $conversation;

    public function __construct(Conversation $conversation)
    {
        $this->conversation = $conversation;
    }

    public function broadcastAs(): string
    {
        return 'ConversationCreated';
    }

    public function broadcastOn(): array
    {
        return $this->conversation
            ->users
            ->pluck('id')
            ->map(fn($id) => new PrivateChannel("user.{$id}"))
            ->toArray();
    }

    public function broadcastWith(): array
    {
        $conv = $this->conversation->load([
            'lastMessage.user:id,name,profile_photo_path',
            'users:id,name,profile_photo_path',
        ]);

        return [
            'conversation' => $conv->toArray(),
        ];
    }
}
