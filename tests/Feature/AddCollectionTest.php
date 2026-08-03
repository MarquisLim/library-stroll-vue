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
        $artwork = Artwork::factory()->create([
            'user_id' => $user->id,
            'is_published' => true,
            'is_private' => false,
        ]);
        $collection = Collection::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
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

    #[Test]
    public function it_rejects_a_collection_owned_by_another_user()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $artwork = Artwork::factory()->create([
            'is_published' => true,
            'is_private' => false,
        ]);
        $foreignCollection = Collection::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user)
            ->post("/artworks/{$artwork->id}/add-to-collection", [
                'collections' => [$foreignCollection->id],
            ])
            ->assertSessionHasErrors('collections.0');

        $this->assertDatabaseMissing('artwork_collection', [
            'artwork_id' => $artwork->id,
            'collection_id' => $foreignCollection->id,
        ]);
    }

    #[Test]
    public function updating_one_users_collections_does_not_detach_another_users_collection()
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $artwork = Artwork::factory()->create([
            'is_published' => true,
            'is_private' => false,
        ]);
        $firstCollection = Collection::factory()->create(['user_id' => $firstUser->id]);
        $secondCollection = Collection::factory()->create(['user_id' => $secondUser->id]);
        $artwork->collections()->attach([$firstCollection->id, $secondCollection->id]);

        $this->actingAs($firstUser)
            ->post("/artworks/{$artwork->id}/add-to-collection", ['collections' => []])
            ->assertOk();

        $this->assertDatabaseMissing('artwork_collection', [
            'artwork_id' => $artwork->id,
            'collection_id' => $firstCollection->id,
        ]);
        $this->assertDatabaseHas('artwork_collection', [
            'artwork_id' => $artwork->id,
            'collection_id' => $secondCollection->id,
        ]);
    }

    #[Test]
    public function collections_media_endpoint_only_returns_the_current_users_collections()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $own = Collection::factory()->create(['user_id' => $user->id]);
        $foreign = Collection::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user)
            ->getJson('/collections/media')
            ->assertOk()
            ->assertJsonFragment(['id' => $own->id])
            ->assertJsonMissing(['id' => $foreign->id]);
    }
}
