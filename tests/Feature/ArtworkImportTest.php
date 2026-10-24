<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Models\Artwork;
use App\Models\ArtworkImport;
use App\Models\Collection;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArtworkImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        config(['media-library.image_optimizers' => []]);
    }

    private function record(array $overrides = []): array
    {
        return array_replace([
            'id' => 28560, 'title' => 'The Bedroom', 'artist_title' => 'Vincent van Gogh',
            'date_display' => '1889', 'medium_display' => 'Oil on canvas',
            'is_public_domain' => true, 'image_id' => 'museum-image',
            'subject_titles' => ['interiors', 'window', 'Window'],
            'style_titles' => ['Post-Impressionism'],
            'classification_title' => 'oil on canvas', 'artwork_type_title' => 'Painting',
        ], $overrides);
    }

    private function fakeArtic(array $records = [], ?string $image = null): void
    {
        Http::fake([
            'api.artic.edu/*' => Http::response(['data' => $records ?: [$this->record()]]),
            'www.artic.edu/iiif/*' => Http::response($image ?? $this->image(), 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    private function image(): string
    {
        $image = imagecreatetruecolor(32, 24);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public function test_import_uses_real_source_metadata_and_creates_media_and_bilingual_tags(): void
    {
        $this->withoutMockingConsoleOutput();
        $this->fakeArtic();
        $status = \Illuminate\Support\Facades\Artisan::call('artworks:import', ['--source' => 'artic', '--query' => 'cats', '--limit' => 1, '--publish' => true]);
        $this->assertSame(0, $status, \Illuminate\Support\Facades\Artisan::output());

        $artwork = Artwork::firstOrFail();
        $this->assertSame('The Bedroom', $artwork->title);
        $this->assertTrue((bool) $artwork->is_published);
        $this->assertStringContainsString('Vincent van Gogh', $artwork->description);
        $this->assertStringContainsString('https://www.artic.edu/artworks/28560', $artwork->description);
        $tags = $artwork->tags->pluck('name')->all();
        $this->assertContains('интерьер', $tags);
        $this->assertContains('post-impressionism', $tags);
        $this->assertContains('постимпрессионизм', $tags);
        $this->assertNotContains('cats', $tags); // A query is not evidence of image contents.
        $this->assertSame(1, array_count_values($tags)['window']);
        $this->assertFileExists($artwork->getFirstMedia('artworks')->getPath());
        $this->assertFileExists($artwork->getFirstMedia('artworks')->getPath('thumb'));
        $this->assertDatabaseHas('artwork_imports', ['source' => 'artic', 'external_id' => '28560']);
        $this->assertSame('museum_metadata', ArtworkImport::first()->metadata['tags_origin']);
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.artic.edu/')) {
                return false;
            }
            $params = json_decode($request['params'], true);

            return $params['query']['bool']['filter'][0]['term']['is_public_domain'] === true;
        });
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('artworks:check-imports'), \Illuminate\Support\Facades\Artisan::output());
    }

    public function test_import_is_idempotent_and_does_not_rewrite_an_existing_artwork(): void
    {
        $this->fakeArtic();
        $this->artisan('artworks:import', ['--source' => 'artic', '--limit' => 1])->assertSuccessful();
        $artwork = Artwork::firstOrFail();
        $artwork->update(['title' => 'Edited by owner']);
        $this->artisan('artworks:import', ['--source' => 'artic', '--limit' => 1, '--publish' => true])->assertSuccessful();
        $this->assertSame(1, Artwork::count());
        $this->assertSame(1, ArtworkImport::count());
        $this->assertSame('Edited by owner', $artwork->fresh()->title);
        $this->assertFalse((bool) $artwork->fresh()->is_published);
        Http::assertSentCount(3); // Two metadata requests, one image.
    }

    public function test_dry_run_never_creates_an_owner_or_downloads_an_image(): void
    {
        $this->fakeArtic();
        $before = User::count();
        $this->artisan('artworks:import', ['--source' => 'artic', '--dry-run' => true, '--limit' => 1])
            ->expectsOutputToContain('The Bedroom')->assertSuccessful();
        $this->assertSame($before, User::count());
        $this->assertSame(0, Artwork::count());
        $this->assertSame(0, ArtworkImport::count());
        Http::assertSentCount(1);
    }

    public function test_import_index_option_dispatches_ai_queue_and_does_not_fabricate_vectors(): void
    {
        $this->fakeArtic();
        config(['ai.enabled' => true]);
        Queue::fake();
        $this->artisan('artworks:import', ['--source' => 'artic', '--limit' => 1, '--publish' => true, '--index' => true])->assertSuccessful();
        Queue::assertPushedOn('ai', AnalyzeAndIndexArtworkJob::class);
        $this->assertDatabaseHas('artwork_ai_metadata', ['status' => 'pending']);
        $this->assertDatabaseMissing('artwork_ai_metadata', ['status' => 'indexed']);
    }

    public function test_index_option_requires_ai_and_published_artworks_before_import(): void
    {
        config(['ai.enabled' => false]);
        $this->artisan('artworks:import', ['--index' => true])->assertExitCode(2);
        $this->artisan('artworks:import', ['--index' => true, '--publish' => true])->assertFailed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('artworks', 0);
    }

    public function test_private_domain_and_missing_images_are_not_imported(): void
    {
        $this->fakeArtic([
            $this->record(['is_public_domain' => false]),
            $this->record(['id' => 2, 'image_id' => null]),
        ]);
        $this->artisan('artworks:import', ['--source' => 'artic', '--limit' => 2])->assertSuccessful();
        $this->assertSame(0, Artwork::count());
        Http::assertSentCount(1);
    }

    public function test_invalid_download_is_reported_without_a_partial_artwork(): void
    {
        $this->fakeArtic(image: '<html>Access denied</html>');
        $this->artisan('artworks:import', ['--source' => 'artic', '--limit' => 1])->assertFailed();
        $this->assertSame(0, Artwork::count());
        $this->assertSame(0, ArtworkImport::count());
        $this->assertDatabaseMissing('users', ['email' => 'museum-imports@libstroll.test']);
    }

    public function test_conversion_failure_rolls_back_the_artwork_tags_owner_and_media_files(): void
    {
        $this->fakeArtic();
        config(['media-library.image_driver' => 'unavailable-driver']);
        $this->artisan('artworks:import', ['--source' => 'artic', '--limit' => 1])->assertFailed();
        $this->assertSame(0, Artwork::count());
        $this->assertSame(0, ArtworkImport::count());
        $this->assertDatabaseCount('media', 0);
        $this->assertDatabaseMissing('users', ['email' => 'museum-imports@libstroll.test']);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_collection_requires_its_owner_before_any_requests(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $collection = Collection::factory()->create(['user_id' => $other->id]);
        $this->artisan('artworks:import', ['--source' => 'artic', '--user' => $owner->id, '--collection' => $collection->id])->assertExitCode(2);
        Http::assertNothingSent();
    }

    public function test_import_into_collection_uses_selected_owner_and_draft_default(): void
    {
        $this->fakeArtic();
        $owner = User::factory()->create();
        $collection = Collection::factory()->create(['user_id' => $owner->id]);
        $this->artisan('artworks:import', ['--source' => 'artic', '--user' => $owner->id, '--collection' => $collection->id, '--limit' => 1])->assertSuccessful();
        $artwork = Artwork::firstOrFail();
        $this->assertSame($owner->id, $artwork->user_id);
        $this->assertFalse((bool) $artwork->is_published);
        $this->assertNull($artwork->published_at);
        $this->assertTrue($collection->artworks()->whereKey($artwork->id)->exists());
    }

    public function test_met_uses_paginated_api_and_object_subjects_and_ignores_restricted_objects(): void
    {
        Http::fake([
            'collectionapi.metmuseum.org/public/collection/v1.1/search*' => Http::response(['objectIDs' => [1, 2]]),
            'collectionapi.metmuseum.org/public/collection/v1/objects/1' => Http::response([
                'objectID' => 1, 'isPublicDomain' => false, 'primaryImageSmall' => 'https://images.metmuseum.org/one.jpg',
            ]),
            'collectionapi.metmuseum.org/public/collection/v1/objects/2' => Http::response([
                'objectID' => 2, 'isPublicDomain' => true, 'primaryImageSmall' => '',
                'primaryImage' => 'https://images.metmuseum.org/two.jpg', 'title' => 'Cats in the Garden',
                'artistDisplayName' => 'Museum artist', 'objectDate' => '1900', 'medium' => 'Ink',
                'tags' => [['term' => 'Cats']], 'classification' => 'Prints', 'objectName' => 'Print',
            ]),
            'images.metmuseum.org/two.jpg' => Http::response($this->image()),
        ]);
        $this->artisan('artworks:import', ['--source' => 'met', '--limit' => 1])->assertSuccessful();
        $this->assertDatabaseHas('artwork_imports', ['source' => 'met', 'external_id' => '2']);
        $this->assertDatabaseHas('tags', ['name' => 'кот']);
        $this->assertDatabaseHas('tags', ['name' => 'гравюра']);
        $this->assertSame(1, Artwork::count());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1.1/search') && $request['offset'] === 0);
    }

    public function test_checker_detects_changed_image_and_removed_source_tags(): void
    {
        $this->fakeArtic();
        $this->artisan('artworks:import', ['--source' => 'artic', '--limit' => 1])->assertSuccessful();
        $artwork = Artwork::firstOrFail();
        $artwork->tags()->detach();
        file_put_contents($artwork->getFirstMedia('artworks')->getPath(), 'corrupted');
        $this->artisan('artworks:check-imports')->expectsOutputToContain('теги источника')->assertFailed();
    }

    public function test_unknown_source_and_negative_limits_are_rejected_without_http(): void
    {
        $this->artisan('artworks:import', ['--source' => 'unknown'])->assertExitCode(2);
        $this->artisan('artworks:import', ['--source' => 'artic', '--limit' => -1])->assertExitCode(2);
        Http::assertNothingSent();
    }

    public function test_repeated_cdn_errors_stop_after_three_attempts(): void
    {
        Http::fake([
            'api.artic.edu/*' => Http::response(['data' => [
                $this->record(['id' => 1]), $this->record(['id' => 2]),
                $this->record(['id' => 3]), $this->record(['id' => 4]),
            ]]),
            'www.artic.edu/iiif/*' => Http::response('Forbidden', 403),
        ]);
        $this->artisan('artworks:import', ['--source' => 'artic'])
            ->expectsOutputToContain('Три ошибки подряд')->assertFailed();
        $this->assertSame(0, Artwork::count());
        Http::assertSentCount(4); // One metadata request and three downloads.
    }

    public function test_met_continues_after_a_missing_object(): void
    {
        Http::fake([
            'collectionapi.metmuseum.org/public/collection/v1.1/search*' => Http::response(['objectIDs' => [1, 2]]),
            'collectionapi.metmuseum.org/public/collection/v1/objects/1' => Http::response([], 404),
            'collectionapi.metmuseum.org/public/collection/v1/objects/2' => Http::response([
                'objectID' => 2, 'isPublicDomain' => true, 'primaryImageSmall' => 'https://images.metmuseum.org/cat.jpg',
                'title' => 'Cat', 'tags' => [['term' => 'Cats']],
            ]),
            'images.metmuseum.org/cat.jpg' => Http::response($this->image()),
        ]);
        $this->artisan('artworks:import', ['--limit' => 1])->assertSuccessful();
        $this->assertSame('2', ArtworkImport::firstOrFail()->external_id);
    }

    public function test_default_seeder_only_creates_reference_data_without_network_or_demo_accounts(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(0, Artwork::count());
        $this->assertSame(0, User::count());
        $this->assertDatabaseHas('roles', ['name' => 'User']);
        $this->assertDatabaseHas('complaint_types', ['slug' => 'copyright']);
        Http::assertNothingSent();
    }

    public function test_demo_user_seeder_preserves_existing_password_name_and_roles(): void
    {
        $user = User::factory()->create(['email' => 'admin@libstroll.test', 'name' => 'Existing account', 'password' => 'personal-password']);
        $this->seed(DatabaseSeeder::class);
        $this->seed(UserSeeder::class);
        $user->refresh();
        $this->assertSame('Existing account', $user->name);
        $this->assertTrue(Hash::check('personal-password', $user->password));
        $this->assertFalse($user->hasRole('Admin'));
    }
}
