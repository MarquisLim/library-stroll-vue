<?php

namespace App\Notifications;

use App\Models\Models\Complaint\Complaint;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContentBlocked extends Notification implements ShouldBroadcast
{
    use Queueable;

    public function __construct(
        protected Complaint $complaint,
        protected User      $moderator
    ) {}

    public function via($notifiable) { return ['database','broadcast']; }

    public function toDatabase($notifiable) : array
    {
        $subjectType = class_basename($this->complaint->complaintable_type);
        return [
            'message'  => "Ваш {$subjectType} заблокирован модератором",
            'note'     => $this->complaint->moderator_note,
            'type'     => 'content_blocked',
            'avatar'   => $this->moderator->profile_photo_url,
        ];
    }

    public function toBroadcast($n) : BroadcastMessage
    {
        return new BroadcastMessage([
            'id'         => $this->id,
            'data'       => $this->toDatabase($n),
            'created_at' => now()->toDateTimeString(),
            'read_at'    => null,
        ]);
    }

    public function broadcastOn() : array
    {
        $ownerId = $this->complaint->complaintable->user->id;
        return [new PrivateChannel('user.'.$ownerId)];
    }
}
