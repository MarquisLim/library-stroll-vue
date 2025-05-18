<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;        // ← вот он
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastAs(): string
    {
        return 'MessageSent';
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('conversation.'.$this->message->conversation_id);
    }

    // Необязательно, но удобно — сразу отдавать «чистый» объект:
    public function broadcastWith(): array
    {
        return ['message' => $this->message->load('user','attachments','reactions')];
    }
}
