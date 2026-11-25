<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class AssistantArtworkPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.assistant_enabled' => true]);
        $this->withoutVite();
        Storage::fake('public');
        Queue::fake();
        Http::fake();
    }

    public function test_preview_returns_the_existing_thumb_without_spending_search_quota(): void
    {
        [$url, , $media] = $this->work();
        $this->actingAs(User::factory()->create());

        for ($i = 0; $i < 11; $i++) {
            $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertTrue($response->headers->hasCacheControlDirective('private'));
        }
        self::assertSame(Storage::disk('public')->get($media->getPathRelativeToRoot('thumb')), $response->getContent());
        $this->postJson(route('assistant.search'), ['query' => ''])->assertUnprocessable();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_guest_unverified_user_and_disabled_feature_cannot_access_preview(): void
    {
        [$url] = $this->work();
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->getJson($url)->assertForbidden();
        config(['ai.assistant_enabled' => false]);
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
    }

    #[DataProvider('ineligibleWorks')]
    public function test_work_and_author_restrictions_apply_to_thumbnail(array $state, bool $blockedAuthor): void
    {
        [$url, $work] = $this->work($state, $blockedAuthor);

        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->actingAs($work->user()->withoutGlobalScopes()->first())->get($url)->assertNotFound();
    }

    public static function ineligibleWorks(): array
    {
        return [
            'private' => [['is_private' => true], false],
            'unpublished' => [['is_published' => false], false],
            'blocked work' => [['is_blocked' => true], false],
            'blocked author' => [[], true],
            'adult' => [['is_adult' => true], false],
            'AI indexing disabled' => [['ai_indexing_enabled' => false], false],
        ];
    }

    public function test_access_is_checked_again_and_missing_or_unsafe_files_do_not_fall_back_to_original(): void
    {
        [$url, $work, $media] = $this->work();
        $this->actingAs(User::factory()->create())->get($url.'?v=first')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $work->updateQuietly(['is_private' => true]);
        $this->get($url.'?v=second')->assertNotFound();
        $work->updateQuietly(['is_private' => false]);

        $disk = Storage::disk('public');
        $path = $media->getPathRelativeToRoot('thumb');
        $disk->put($path, '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->get($url)->assertNotFound();
        $disk->delete($path);
        $this->get($url)->assertNotFound();
        $disk->assertExists($media->getPathRelativeToRoot());
    }

    private function work(array $state = [], bool $blockedAuthor = false): array
    {
        $author = User::factory()->create(['is_blocked' => $blockedAuthor]);
        $work = Artwork::factory()->create(['user_id' => $author->id, 'ai_indexing_enabled' => true, ...$state]);
        $media = $work->addMedia(public_path('images/favicons/apple.png'))->preservingOriginal()->toMediaCollection('artworks');
        self::assertInstanceOf(Media::class, $media);

        return [route('assistant.artworks.preview', $work), $work, $media];
    }
}
