<?php

namespace App\Notifications;

use App\Models\Models\Complaint\Complaint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Support\Arrayable;

class ComplaintCreated extends Notification implements ShouldBroadcast
{
    use Queueable;

    public function __construct(
        protected Complaint $complaint,
        protected int       $recipientId
    ){
        $this->complaint->load('type','user','complaintable');
    }

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $author = $this->complaint->user;
        $type   = $this->complaint->type;

        $subjectType = strtolower(class_basename($this->complaint->complaintable_type));
        $subjectId   = $this->complaint->complaintable_id;
        $parentId    = $subjectType === 'comment'
            ? $this->complaint->complaintable->commentable_id
            : null;

        return [
            'message'       => "Новая жалоба «{$type->name}» от {$author->name}",

            'user_id'       => $author->id,
            'user_name'     => $author->name,
            'avatar'        => $author->profile_photo_url,

            'subject_type'  => $subjectType,
            'subject_id'    => $subjectId,
            'parent_id'     => $parentId,

            'complaint_id'  => $this->complaint->id,
            'type'          => $type->slug,
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'id'         => $this->id,
            'data'       => $this->toDatabase($notifiable),
            'read_at'    => null,
            'created_at' => now()->toDateTimeString(),
            'type'       => class_basename(static::class),
        ]);
    }

    public function broadcastOn(): array
    {
        return [ new PrivateChannel('user.'.$this->recipientId) ];
    }
}
