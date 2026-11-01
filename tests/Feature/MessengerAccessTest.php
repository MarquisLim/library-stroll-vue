<?php

namespace Tests\Feature;

use App\Events\ConversationCreated;
use App\Events\MessageSent;
use App\Events\ReactionUpdated;
use App\Models\Artwork;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MessengerAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([ConversationCreated::class, MessageSent::class, ReactionUpdated::class]);
    }

    private function conversation(User $admin, ?User $member = null, string $type = 'group'): Conversation
    {
        $conversation = Conversation::create(['type' => $type, 'title' => 'Test chat']);
        $conversation->users()->attach($admin, ['role' => 'admin']);
        if ($member) {
            $conversation->users()->attach($member, ['role' => 'member']);
        }

        return $conversation;
    }

    private function message(Conversation $conversation, User $author): Message
    {
        return $conversation->messages()->create(['user_id' => $author->id, 'body' => 'Private message']);
    }

    private function attachment(User $owner): MessageAttachment
    {
        return MessageAttachment::create([
            'user_id' => $owner->id, 'disk' => 'local', 'path' => 'attachments/test.txt',
            'mime' => 'text/plain', 'size' => 4,
        ]);
    }

    public function test_group_member_cannot_delete_the_group_but_admin_can(): void
    {
        [$admin, $member] = User::factory()->count(2)->create();
        $conversation = $this->conversation($admin, $member);

        $this->actingAs($member)->deleteJson(route('messenger.conversations.destroy', $conversation))->assertForbidden();
        $this->assertModelExists($conversation);
        $this->actingAs($admin)->deleteJson(route('messenger.conversations.destroy', $conversation))->assertOk();
        $this->assertModelMissing($conversation);
    }

    public function test_outsider_cannot_react_to_a_message(): void
    {
        [$member, $outsider] = User::factory()->count(2)->create();
        $message = $this->message($this->conversation($member), $member);

        $this->actingAs($outsider)->postJson(route('messenger.messages.reaction', $message), ['emoji' => '👍'])->assertForbidden();
        $this->assertDatabaseCount('message_reactions', 0);
        Event::assertNotDispatched(ReactionUpdated::class);
    }

    public function test_last_reaction_can_be_removed_and_broadcast_to_the_correct_chat(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversation($user);
        $message = $this->message($conversation, $user);
        $url = route('messenger.messages.reaction', $message);

        $this->actingAs($user)->postJson($url, ['emoji' => '👍'])->assertOk()->assertJsonCount(1);
        $this->postJson($url, ['emoji' => '👍'])->assertOk()->assertExactJson([]);

        Event::assertDispatched(ReactionUpdated::class, fn ($event) => $event->reactions->isEmpty()
            && $event->broadcastOn()->name === 'private-conversation.'.$conversation->id);
    }

    public function test_cannot_reply_to_a_message_from_another_conversation(): void
    {
        [$user, $outsider] = User::factory()->count(2)->create();
        $conversation = $this->conversation($user);
        $secret = $this->message($this->conversation($outsider), $outsider);

        $this->actingAs($user)->postJson(route('messenger.messages.store', $conversation), [
            'body' => 'Reply', 'reply_to_id' => $secret->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('reply_to_id');

        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_legacy_cross_chat_replies_are_not_exposed_in_message_history(): void
    {
        [$user, $other] = User::factory()->count(2)->create();
        $conversation = $this->conversation($user);
        $secret = $this->message($this->conversation($other), $other);
        $message = $this->message($conversation, $user);
        $message->update(['reply_to_id' => $secret->id]);
        $secret->update(['reply_to_id' => $message->id]);

        $this->actingAs($user)->getJson(route('messenger.messages.index', $conversation))
            ->assertOk()->assertJsonPath('0.reply_to', null)->assertJsonPath('0.replies', []);
        $this->get(route('messenger.index', $conversation))->assertOk()->assertInertia(fn ($page) => $page
            ->where('messages.0.reply_to', null)->where('messages.0.replies', []));
    }

    public function test_user_cannot_attach_another_users_pending_upload(): void
    {
        [$user, $other] = User::factory()->count(2)->create();
        $conversation = $this->conversation($user);
        $attachment = $this->attachment($other);

        $this->actingAs($user)->postJson(route('messenger.messages.store', $conversation), [
            'attachments' => [$attachment->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');

        $this->assertNull($attachment->fresh()->message_id);
        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_owner_can_send_attachment_once_and_reply_in_the_same_chat(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversation($user);
        $original = $this->message($conversation, $user);
        $attachment = $this->attachment($user);

        $response = $this->actingAs($user)->postJson(route('messenger.messages.store', $conversation), [
            'reply_to_id' => $original->id, 'attachments' => [$attachment->id],
        ])->assertSuccessful()->assertJsonPath('has_attachments', true);

        $this->assertSame($response->json('id'), $attachment->fresh()->message_id);
        $this->postJson(route('messenger.messages.store', $conversation), [
            'attachments' => [$attachment->id],
        ])->assertUnprocessable();
        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_empty_messages_and_malformed_attachments_are_rejected(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversation($user);
        $url = route('messenger.messages.store', $conversation);

        $this->actingAs($user)->postJson($url, ['body' => '   '])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson($url, ['body' => 'hello', 'attachments' => [['id' => 1]]])->assertUnprocessable();
        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_private_artwork_cannot_be_shared_and_existing_previews_disappear(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversation($user);
        $artwork = Artwork::factory()->private()->create(['user_id' => $user->id]);

        $this->actingAs($user)->postJson(route('messenger.messages.store', $conversation), [
            'artwork_id' => $artwork->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('artwork_id');

        $message = $this->message($conversation, $user);
        $message->update(['artwork_id' => $artwork->id]);
        $this->getJson(route('messenger.messages.index', $conversation))
            ->assertOk()->assertJsonPath('0.artwork', null);
    }

    public function test_upload_is_private_and_only_owner_can_preview_pending_file(): void
    {
        Storage::fake('local');
        [$user, $other] = User::factory()->count(2)->create();
        $response = $this->actingAs($user)->postJson(route('messenger.attachments.store'), [
            'file' => UploadedFile::fake()->create('document.txt', 1, 'text/plain'),
        ])->assertSuccessful();

        $attachment = MessageAttachment::findOrFail($response->json('id'));
        $this->assertSame('local', $attachment->disk);
        $this->assertSame($user->id, $attachment->user_id);
        Storage::disk('local')->assertExists($attachment->path);
        $this->get($attachment->url())->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($other)->getJson($attachment->url())->assertForbidden();
    }

    public function test_sent_attachment_is_only_available_to_current_conversation_members(): void
    {
        Storage::fake('local');
        [$admin, $member, $outsider] = User::factory()->count(3)->create();
        $conversation = $this->conversation($admin, $member);
        $attachment = $this->attachment($admin);
        $attachment->update(['message_id' => $this->message($conversation, $admin)->id]);
        Storage::disk('local')->put($attachment->path, 'test');

        $this->actingAs($member)->get($attachment->url())->assertOk();
        $this->actingAs($outsider)->getJson($attachment->url())->assertForbidden();
        $conversation->users()->detach($member);
        $this->actingAs($member)->getJson($attachment->url())->assertForbidden();
    }

    public function test_active_content_upload_is_downloaded_instead_of_rendered_inline(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $attachment = $this->attachment($user);
        $attachment->update(['mime' => 'text/html']);
        Storage::disk('local')->put($attachment->path, '<script>alert(1)</script>');

        $response = $this->actingAs($user)->get($attachment->url())->assertOk();
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
    }

    public function test_private_video_delivery_supports_byte_range_requests(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $attachment = $this->attachment($user);
        $attachment->update(['mime' => 'video/mp4']);
        Storage::disk('local')->put($attachment->path, 'test');

        $this->actingAs($user)->withHeader('Range', 'bytes=1-2')->get($attachment->url())
            ->assertStatus(206)->assertHeader('Content-Range', 'bytes 1-2/4');
    }

    public function test_invalid_chat_participants_are_rejected_without_creating_conversations(): void
    {
        [$user, $other] = User::factory()->count(2)->create();
        $blocked = User::factory()->create(['is_blocked' => true]);
        $this->actingAs($user);

        foreach ([[], [$user->id], [$other->id, $other->id], [$blocked->id], [999999]] as $ids) {
            $this->postJson(route('messenger.conversations.store'), ['type' => 'dialog', 'user_ids' => $ids])->assertUnprocessable();
        }
        $this->postJson(route('messenger.conversations.store'), ['type' => 'group'])->assertUnprocessable();
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_recreating_a_dialog_returns_the_existing_conversation(): void
    {
        [$user, $other] = User::factory()->count(2)->create();
        $this->actingAs($user);
        $data = ['type' => 'dialog', 'user_ids' => [$other->id]];
        $first = $this->postJson(route('messenger.conversations.store'), $data)->assertOk();
        $this->postJson(route('messenger.conversations.store'), $data)->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_conversation_details_are_only_available_to_members(): void
    {
        [$user, $outsider] = User::factory()->count(2)->create();
        $conversation = $this->conversation($user);

        $this->actingAs($user)->getJson(route('messenger.conversations.show', $conversation))->assertOk()->assertJsonPath('id', $conversation->id);
        $this->actingAs($outsider)->getJson(route('messenger.conversations.show', $conversation))->assertForbidden();
    }

    public function test_admin_cannot_remove_self_using_a_string_user_id(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversation($user);
        $this->actingAs($user)->postJson(route('messenger.conversations.removeUser', $conversation), ['user_id' => (string) $user->id])->assertUnprocessable();
        $this->assertTrue($conversation->users()->whereKey($user->id)->exists());
    }

    public function test_read_marker_rejects_messages_from_another_chat(): void
    {
        [$user, $other] = User::factory()->count(2)->create();
        $conversation = $this->conversation($user);
        $message = $this->message($this->conversation($other), $other);

        $this->actingAs($user)->patchJson(route('messenger.conversations.read', $conversation), ['message_id' => $message->id])
            ->assertUnprocessable()->assertJsonValidationErrors('message_id');
    }

    public function test_legacy_public_attachment_urls_still_require_membership(): void
    {
        Storage::fake('public');
        [$user, $outsider] = User::factory()->count(2)->create();
        $conversation = $this->conversation($user);
        $attachment = $this->attachment($user);
        $attachment->update(['disk' => 'public', 'user_id' => null, 'message_id' => $this->message($conversation, $user)->id]);
        Storage::disk('public')->put($attachment->path, 'test');

        $url = '/storage/'.$attachment->path;
        $this->actingAs($user)->get($url)->assertOk();
        $this->actingAs($outsider)->getJson($url)->assertForbidden()->assertHeader('Content-Type', 'application/json');
    }

    public function test_a_dialog_participant_can_still_delete_the_dialog(): void
    {
        [$admin, $member] = User::factory()->count(2)->create();
        $conversation = $this->conversation($admin, $member, 'dialog');
        $this->actingAs($member)->deleteJson(route('messenger.conversations.destroy', $conversation))->assertOk();
    }

    public function test_blocking_in_either_direction_prevents_direct_messages(): void
    {
        [$user, $other] = User::factory()->count(2)->create();
        $conversation = $this->conversation($user, $other, 'dialog');
        $other->blockedUsers()->attach($user);

        $this->actingAs($user)->postJson(route('messenger.messages.store', $conversation), ['body' => 'blocked'])
            ->assertForbidden()->assertHeader('Content-Type', 'application/json');
        $this->actingAs($other)->postJson(route('messenger.messages.store', $conversation), ['body' => 'also blocked'])->assertForbidden();
        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_missing_api_route_returns_json_instead_of_an_inertia_page(): void
    {
        $this->getJson('/missing-audit-route')->assertNotFound()->assertHeader('Content-Type', 'application/json');
        $this->get('/missing-audit-route')->assertNotFound()->assertInertia(fn ($page) => $page->component('Errors/NotFound'));
    }
}
