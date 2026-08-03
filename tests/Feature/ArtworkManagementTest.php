<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Comment;
use App\Models\Like;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ArtworkManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_an_artwork_through_the_canonical_route(): void
    {
        $owner = User::factory()->create();
        $artwork = Artwork::factory()->create(['user_id' => $owner->id]);
        $collection = Collection::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)
            ->patchJson("/studio/artworks/{$artwork->id}", [
                'title' => 'Updated title',
                'description' => 'Updated description',
                'allow_comments' => false,
                'tags' => ['First tag'],
                'collections' => [$collection->id],
            ])
            ->assertOk()
            ->assertJsonPath('artwork.title', 'Updated title');

        $this->assertDatabaseHas('artworks', [
            'id' => $artwork->id,
            'title' => 'Updated title',
            'allow_comments' => false,
        ]);
        $this->assertDatabaseHas('artwork_collection', [
            'artwork_id' => $artwork->id,
            'collection_id' => $collection->id,
        ]);
    }

    public function test_non_owner_cannot_update_or_delete_an_artwork(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $artwork = Artwork::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($other)
            ->patchJson("/studio/artworks/{$artwork->id}", ['title' => 'Forbidden'])
            ->assertForbidden();

        $this->actingAs($other)
            ->deleteJson("/studio/artworks/{$artwork->id}")
            ->assertForbidden();

        $this->assertModelExists($artwork);
    }

    public function test_legacy_update_route_uses_the_same_update_flow(): void
    {
        $owner = User::factory()->create();
        $artwork = Artwork::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)
            ->postJson("/studio/update-draft/{$artwork->id}", ['title' => 'Legacy update'])
            ->assertOk();

        $this->assertDatabaseHas('artworks', [
            'id' => $artwork->id,
            'title' => 'Legacy update',
        ]);
    }

    public function test_admin_can_manage_an_artwork_hidden_by_global_scopes(): void
    {
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $artwork = Artwork::factory()->create(['is_blocked' => true]);

        $this->actingAs($admin)
            ->patchJson("/studio/artworks/{$artwork->id}", ['title' => 'Moderated'])
            ->assertOk();

        $this->assertDatabaseHas('artworks', [
            'id' => $artwork->id,
            'title' => 'Moderated',
        ]);
    }

    public function test_canonical_delete_removes_dependent_domain_data(): void
    {
        $owner = User::factory()->create();
        $liker = User::factory()->create();
        $artwork = Artwork::factory()->create(['user_id' => $owner->id]);
        $collection = Collection::factory()->create(['user_id' => $owner->id]);
        $artwork->collections()->attach($collection);
        Like::create(['user_id' => $liker->id, 'artwork_id' => $artwork->id]);
        Comment::create([
            'user_id' => $liker->id,
            'commentable_type' => Artwork::class,
            'commentable_id' => $artwork->id,
            'text' => 'Will be deleted',
        ]);

        $this->actingAs($owner)
            ->deleteJson("/studio/artworks/{$artwork->id}")
            ->assertOk();

        $this->assertDatabaseMissing('artworks', ['id' => $artwork->id]);
        $this->assertDatabaseMissing('likes', ['artwork_id' => $artwork->id]);
        $this->assertDatabaseMissing('comments', [
            'commentable_type' => Artwork::class,
            'commentable_id' => $artwork->id,
        ]);
        $this->assertDatabaseMissing('artwork_collection', ['artwork_id' => $artwork->id]);
    }
}
