<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\User;
use App\Models\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CommentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_logged_in_user_can_add_comment()
    {
        Notification::fake();

        $user = User::factory()->create();
        $artwork = Artwork::factory()->create([
            'is_published' => true,
            'is_private' => false,
            'allow_comments' => true,
        ]);

        $response = $this->actingAs($user)
            ->withoutMiddleware()
            ->post("/artworks/{$artwork->id}/comments", [
                'text' => 'Test Comment'
            ]);

        $response->assertStatus(200);

        // Проверяем структуру JSON-ответа (но не `message`)
        $response->assertJsonStructure([
            'comment' => [
                'user_id',
                'commentable_id',
                'commentable_type',
                'text',
                'created_at',
                'updated_at',
                'id',
                'user' => [
                    'id',
                    'name',
                    'email'
                ]
            ]
        ]);

        // Проверяем, что комментарий появился в БД
        $this->assertDatabaseHas('comments', [
            'user_id' => $user->id,
            'commentable_id' => $artwork->id,
            'text' => 'Test Comment'
        ]);
    }

    #[Test]
    public function comments_are_rejected_when_the_artwork_disallows_them()
    {
        $user = User::factory()->create();
        $artwork = Artwork::factory()->create([
            'is_published' => true,
            'is_private' => false,
            'allow_comments' => false,
        ]);

        $this->actingAs($user)
            ->post("/artworks/{$artwork->id}/comments", ['text' => 'Blocked'])
            ->assertForbidden();

        $this->assertDatabaseMissing('comments', [
            'commentable_id' => $artwork->id,
            'text' => 'Blocked',
        ]);
    }

    #[Test]
    public function replies_are_rejected_when_comments_are_disabled_after_parent_creation()
    {
        $author = User::factory()->create();
        $replier = User::factory()->create();
        $artwork = Artwork::factory()->create([
            'is_published' => true,
            'is_private' => false,
            'allow_comments' => true,
        ]);

        $parent = $artwork->comments()->create([
            'user_id' => $author->id,
            'text' => 'Parent',
        ]);
        $artwork->update(['allow_comments' => false]);

        $this->actingAs($replier)
            ->post("/artworks/comments/{$parent->id}/reply", ['text' => 'Reply'])
            ->assertForbidden();
    }
}
