<?php

namespace App\Notifications;

use App\Models\Comment;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class CommentReceived extends Notification implements ShouldBroadcast
{
    use Queueable;

    public function __construct(protected Comment $comment) {}

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray($notifiable): array
    {
        $c = $this->comment;
        return [
            'comment_id'     => $c->id,
            'excerpt'        => Str::limit($c->text, 50),
            'artwork_id'     => $c->commentable->id,
            'artwork_title'  => $c->commentable->title ?: 'Без названия',
            'artwork_url'    => route('artworks.show', $c->commentable->id),
            'commenter_id'   => $c->user->id,
            'commenter_name' => $c->user->name,
            'avatar'         => $c->user->profile_photo_url,

            // для фронта удобно сразу знать, к кому ответ:
            'parent_id'      => $c->parent_id,
            'parent_name'    => $c->parent?->user->name,
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
        if ($this->comment->parent_id) {
            // это реплай — идём к parent->user
            $recipient = $this->comment->parent->user;
        } else {
            // это корневой — к автору арта
            $recipient = $this->comment->commentable->user;
        }

        return new PrivateChannel('user.' . $recipient->id);
    }
}
