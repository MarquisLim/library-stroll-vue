<?php

namespace App\Notifications;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class ArtworkLiked extends Notification implements ShouldBroadcast
{
    use Queueable;

    protected $artwork;
    protected $liker;

    public function __construct(Artwork $artwork, User $liker)
    {
        $this->artwork = $artwork;
        $this->liker    = $liker;
    }

    public function via($notifiable)
    {
        return ['database','broadcast'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'artwork_id'    => $this->artwork->id,
            'artwork_title' => $this->artwork->title ?: 'Без названия',
            'artwork_url'   => route('artworks.show', $this->artwork->id),

            'liker_id'      => $this->liker->id,
            'liker_name'    => $this->liker->name,
            'avatar'        => $this->liker->profile_photo_url,
        ];
    }

    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage([
            'id'        => $this->id,
            'data'      => $this->toDatabase($notifiable),
            'read_at'   => null,
            'created_at'=> now()->toDateTimeString(),
        ]);
    }

    public function broadcastOn()
    {
        return new PrivateChannel('user.'.$this->artwork->user_id);
    }
}
