<?php

namespace App\Notifications;

use App\Models\Comment;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class CommentReceived extends Notification implements ShouldBroadcast
{
    use Queueable;

    public function __construct(protected Comment $comment) {}

    public function via($notifiable): array
    {
        return ['database','broadcast'];
    }

    public function toArray($notifiable): array
    {
        $art = $this->comment->commentable;
        return [
            'comment_id'      => $this->comment->id,
            'excerpt'         => Str::limit($this->comment->text,50),

            'artwork_id'      => $art->id,
            'artwork_title'   => $art->title ?: 'Без названия',
            'artwork_url'     => route('artworks.show', $art->id),

            'commenter_id'    => $this->comment->user->id,
            'commenter_name'  => $this->comment->user->name,
            'avatar'          => $this->comment->user->profile_photo_url,
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'id'         => $this->id,
            'type'       => class_basename(static::class),
            'data'       => $this->toArray($notifiable),
            'read_at'    => null,
            'created_at' => now()->toDateTimeString(),
        ]);
    }

    public function broadcastOn(): PrivateChannel
    {
        $owner = $this->comment->commentable->user;
        return new PrivateChannel('user.'.$owner->id);
    }

}
