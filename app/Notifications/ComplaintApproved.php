<?php

namespace App\Notifications;

use App\Models\Models\Complaint\Complaint;
use App\Models\User;
use App\Services\ComplaintSubjectInfo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Broadcasting\PrivateChannel;

class ComplaintApproved extends Notification implements ShouldBroadcast
{
    use Queueable;

    protected int $recipientId;

    public function __construct(
        protected Complaint $complaint,
        protected User      $moderator,
        int $recipientId = null
    ) {
        $this->recipientId = $recipientId ?? $this->complaint->user_id;
        $this->complaint->load('complaintable', 'user');
    }

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    protected function payload(): array
    {
        $info = ComplaintSubjectInfo::for($this->complaint->complaintable);

        return [
            'message'      => "Ваша жалоба на {$info['type']} принята",
            'note'         => $this->complaint->moderator_note,
            'type'         => 'complaint_approved',
            'avatar'       => $this->moderator->profile_photo_url,
            'title'        => $info['title'],
            'url'          => $info['url'],
            'complaint_id' => $this->complaint->id,
        ];
    }

    public function toDatabase($notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'id'         => $this->id,
            'data'       => $this->payload(),
            'read_at'    => null,
            'created_at' => now()->toDateTimeString(),
            'type'       => class_basename(static::class),
        ]);
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->recipientId),
        ];
    }
}
