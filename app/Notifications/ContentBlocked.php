<?php

namespace App\Notifications;

use App\Models\User;
use App\Models\Models\Complaint\Complaint;
use App\Services\ComplaintSubjectInfo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class ContentBlocked extends Notification implements ShouldBroadcast, ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Complaint $complaint,
        protected User      $moderator
    ) {}

    public function via($notifiable) { return ['database', 'broadcast']; }

    /* ---------- общее тело ---------- */
    protected function payload(): array
    {
        $info = ComplaintSubjectInfo::for($this->complaint->complaintable);

        return [
            'message' => "Ваш {$info['type']} был заблокирован модератором",
            'note'    => $this->complaint->moderator_note,
            'type'    => 'content_blocked',
            'avatar'  => $this->moderator->profile_photo_url,

            // новое
            'title'   => $info['title'],
            'url'     => $info['url'],
        ];
    }

    public function toDatabase($n): array          { return $this->payload(); }

    public function toBroadcast($n): BroadcastMessage
    {
        return new BroadcastMessage([
            'id'         => $this->id,
            'data'       => $this->payload(),
            'created_at' => now()->toDateTimeString(),
            'read_at'    => null,
        ]);
    }

    public function broadcastOn(): array
    {
        $ownerId = $this->complaint->complaintable->user->id;
        return ['private-user.'.$ownerId];
    }
}
