<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomeCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercise a store that serializes payloads, unlike the default test store.
        config(['cache.default' => 'database']);
    }

    public function test_home_renders_on_cache_miss_and_hit_without_serialized_models(): void
    {
        $artwork = Artwork::factory()->create();
        Artwork::factory()->private()->create();
        Artwork::factory()->draft()->create();

        // The old object payload must not be reused after the upgrade.
        Cache::put('home:recentArtworks:', collect([$artwork]), 300);

        for ($request = 0; $request < 2; $request++) {
            $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Home')
                ->has('recentArtworks', 1)
                ->where('recentArtworks.0.id', $artwork->id)
                ->where('recentArtworks.0.liked_by_user', false));
        }

        $this->assertSame([$artwork->id], Cache::get('home:recentArtworkIds:v1'));
    }

    public function test_cached_likes_and_collections_are_isolated_per_user(): void
    {
        $viewer = User::factory()->create();
        $otherViewer = User::factory()->create();
        $artwork = Artwork::factory()->create();
        $collection = Collection::factory()->create(['user_id' => $viewer->id]);
        $collection->artworks()->attach($artwork);
        $artwork->likes()->create(['user_id' => $viewer->id]);

        for ($request = 0; $request < 2; $request++) {
            $this->actingAs($viewer)->get('/')->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('recentArtworks.0.liked_by_user', true)
                    ->where('recentArtworks.0.in_collections', [$collection->id]));

            $this->actingAs($otherViewer)->get('/')->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('recentArtworks.0.liked_by_user', false)
                    ->where('recentArtworks.0.in_collections', []));
        }

        $artwork->likes()->delete();
        $collection->artworks()->detach($artwork);

        $this->actingAs($viewer)->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('recentArtworks.0.liked_by_user', false)
                ->where('recentArtworks.0.in_collections', []));
    }

    public function test_cached_artworks_disappear_immediately_when_made_private(): void
    {
        $artwork = Artwork::factory()->create();
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->has('recentArtworks', 1));

        $artwork->update(['is_private' => true]);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->has('recentArtworks', 0));
    }
}
