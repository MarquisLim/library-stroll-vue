<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AddCollectionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_allows_user_to_add_artwork_to_collection()
    {
        $user = User::factory()->create();
        $artwork = Artwork::factory()->create(['user_id' => $user->id]);
        $collection = Collection::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->withoutMiddleware()
            ->post("/artworks/{$artwork->id}/add-to-collection", [
                'collections' => [$collection->id]
            ]);

        $response->assertStatus(200);

        // Используем правильный текст из API
        $response->assertJson(['message' => 'Added to collection']);

        // Проверяем связь artwork_collection
        $this->assertDatabaseHas('artwork_collection', [
            'artwork_id' => $artwork->id,
            'collection_id' => $collection->id
        ]);
    }
}
