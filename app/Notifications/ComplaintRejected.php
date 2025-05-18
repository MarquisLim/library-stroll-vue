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

class ComplaintRejected extends Notification implements ShouldBroadcast
{
    use Queueable;

    public function __construct(
        protected Complaint $complaint,
        protected User      $moderator
    ){}

    public function via($notifiable){ return ['database','broadcast']; }

    public function toDatabase($n) : array
    {
        return [
            'message' => "Ваша жалоба отклонена",
            'note'    => $this->complaint->moderator_note,
            'type'    => 'complaint_rejected',
            'avatar'  => $this->moderator->profile_photo_url,
        ];
    }

    public function toBroadcast($n): BroadcastMessage
    {
        return new BroadcastMessage([
            'id'=>$this->id,
            'data'=>$this->toDatabase($n),
            'created_at'=>now()->toDateTimeString(),
            'read_at'=>null,
        ]);
    }

    public function broadcastOn(): array
    {
        return [ new PrivateChannel('user.'.$this->complaint->user_id) ];
    }
}
