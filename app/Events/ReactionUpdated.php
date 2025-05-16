<?php

namespace App\Events;

use App\Models\Collection;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReactionUpdated implements ShouldBroadcast
{
    public function __construct(public int $messageId, public Collection $reactions) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('conversation.'.$this->reactions->first()->message->conversation_id);
    }
}
