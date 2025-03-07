<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\User;
use App\Models\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CommentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_logged_in_user_can_add_comment()
    {
        $user = User::factory()->create();
        $artwork = Artwork::factory()->create();

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
}
