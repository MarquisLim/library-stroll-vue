<?php

namespace Tests\Unit;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ArtworkControllerLikeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function authenticated_user_can_like_an_artwork()
    {
        $user = User::factory()->create();
        $artwork = Artwork::factory()->create();

        // Имитируем авторизованного пользователя
        $response = $this->actingAs($user)->withoutMiddleware()
            ->post("/artworks/{$artwork->id}/like");

        $response->assertStatus(200);

        // Проверяем, что в таблицу likes добавлена запись
        $this->assertDatabaseHas('likes', [
            'user_id' => $user->id,
            'artwork_id' => $artwork->id
        ]);
    }

    #[Test]
    public function it_removes_like_if_already_liked()
    {
        $user = User::factory()->create();
        $artwork = Artwork::factory()->create();

        // Сначала ставим лайк
        $this->actingAs($user)->withoutMiddleware()->post("/artworks/{$artwork->id}/like");
        $this->assertDatabaseHas('likes', [
            'user_id' => $user->id,
            'artwork_id' => $artwork->id
        ]);

        // Повторно отправляем запрос лайка -> должен удалить
        $this->actingAs($user)->withoutMiddleware()->post("/artworks/{$artwork->id}/like");
        $this->assertDatabaseMissing('likes', [
            'user_id' => $user->id,
            'artwork_id' => $artwork->id
        ]);
    }
}
