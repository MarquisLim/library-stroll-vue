<?php

namespace Tests\Unit;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ArtworkTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_create_an_artwork()
    {
        // Создаём пользователя
        $user = User::factory()->create();

        // Создаём запись Artwork через фабрику
        $artwork = Artwork::factory()->create([
            'user_id' => $user->id,
            'title' => 'Test Art',
            'description' => 'Some description',
        ]);

        // Проверяем, что запись существует в БД
        $this->assertModelExists($artwork);

        // Проверяем, что в БД есть нужные данные
        $this->assertDatabaseHas('artworks', [
            'title' => 'Test Art'
        ]);

        // Дополнительная проверка на соответствие
        $this->assertEquals('Test Art', $artwork->title);
        $this->assertEquals('Some description', $artwork->description);
    }
}
