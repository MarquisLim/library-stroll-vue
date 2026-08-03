<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtworkVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_only_returns_publicly_visible_artworks(): void
    {
        $owner = User::factory()->create();
        $public = Artwork::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Visible needle',
            'is_published' => true,
            'is_private' => false,
        ]);
        $private = Artwork::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Private needle',
            'is_published' => true,
            'is_private' => true,
        ]);
        $draft = Artwork::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Draft needle',
            'is_published' => false,
            'is_private' => false,
        ]);

        $response = $this->get('/search?q=needle');

        $response->assertOk()
            ->assertSee($public->title)
            ->assertDontSee($private->title)
            ->assertDontSee($draft->title);
    }

    public function test_gallery_load_more_never_returns_the_owners_draft(): void
    {
        $owner = User::factory()->create();
        $public = Artwork::factory()->create([
            'user_id' => $owner->id,
            'is_published' => true,
            'is_private' => false,
        ]);
        $draft = Artwork::factory()->create([
            'user_id' => $owner->id,
            'is_published' => false,
            'is_private' => false,
        ]);

        $response = $this->actingAs($owner)->getJson('/gallery/load-more');

        $response->assertOk()
            ->assertJsonFragment(['id' => $public->id])
            ->assertJsonMissing(['id' => $draft->id]);
    }

    public function test_blocked_artworks_and_blocked_authors_are_excluded(): void
    {
        $blockedAuthor = User::factory()->create(['is_blocked' => true]);
        $blockedArtwork = Artwork::factory()->create([
            'is_published' => true,
            'is_private' => false,
            'is_blocked' => true,
        ]);
        $authoredArtwork = Artwork::withoutGlobalScopes()->create([
            'user_id' => $blockedAuthor->id,
            'title' => 'Blocked author work',
            'is_published' => true,
            'is_private' => false,
        ]);

        $response = $this->getJson('/gallery/load-more');

        $response->assertOk()
            ->assertJsonMissing(['id' => $blockedArtwork->id])
            ->assertJsonMissing(['id' => $authoredArtwork->id]);
    }
}
