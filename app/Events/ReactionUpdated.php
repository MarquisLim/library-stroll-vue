<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Collection;

class ReactionUpdated implements ShouldBroadcast
{
    public function __construct(public int $messageId, public Collection $reactions, public int $conversationId) {}

    public function broadcastAs(): string
    {
        return 'ReactionUpdated';
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('conversation.'.$this->conversationId);
    }
}
