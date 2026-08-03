<?php

namespace Tests\Feature;

use App\Models\Models\Complaint\Complaint;
use App\Models\Models\Complaint\ComplaintType;
use App\Models\User;
use App\Notifications\ContentBlocked;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentBlockedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_broadcasts_on_the_registered_user_channel(): void
    {
        $reporter = User::factory()->create();
        $owner = User::factory()->create();
        $moderator = User::factory()->create();
        $type = ComplaintType::create(['slug' => 'test', 'name' => 'Test']);
        $complaint = Complaint::create([
            'user_id' => $reporter->id,
            'complaint_type_id' => $type->id,
            'complaintable_type' => User::class,
            'complaintable_id' => $owner->id,
        ]);

        $channel = (new ContentBlocked($complaint, $moderator))->broadcastOn();

        $this->assertInstanceOf(PrivateChannel::class, $channel);
        $this->assertSame("private-user.{$owner->id}", $channel->name);
    }
}
