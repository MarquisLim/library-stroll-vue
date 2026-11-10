<?php

namespace Tests\Feature;

use App\Contracts\AiServiceClient;
use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Models\Artwork;
use App\Models\Collection;
use App\Models\User;
use App\Services\AiService\ArtworkIndexingService;
use App\Services\DemoContent\ContentItem;
use App\Services\DemoContent\DemoImporter;
use App\Services\DemoContent\DemoProfiles;
use App\Services\DemoContent\Manifest;
use App\Services\DemoContent\MediaUrlPolicy;
use App\Services\DemoContent\SourceHttp;
use App\Services\DemoContent\SourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DemoContentTest extends TestCase
{
    public function test_public_profile_with_collections_exposes_biography(): void
    {
        $user = User::factory()->create(['description' => 'Curator biography']);
        $art = Artwork::factory()->create(['user_id' => $user->id, 'is_published' => true, 'is_private' => false]);
        $collection = Collection::create(['user_id' => $user->id, 'name' => 'Landscape archive', 'is_private' => false]);
        $collection->artworks()->attach($art);
        $this->get('/profile/'.$user->id)->assertOk()->assertSee('Curator biography');
    }

    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        config(['demo_content.root' => storage_path('framework/testing/demo-'.bin2hex(random_bytes(6))), 'media-library.image_optimizers' => [], 'ai.enabled' => true]);
        foreach (array_keys(config('demo_content.sources')) as $s) {
            config(["demo_content.sources.$s.rate_limit" => 1000000, "demo_content.sources.$s.retry_delay" => 0]);
        }
        $this->mock(MediaUrlPolicy::class, function ($mock) {
            $mock->shouldReceive('options')->andReturn(['allow_redirects' => false]);
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('demo_content.root'));
        parent::tearDown();
    }

    private function image(): string
    {
        $im = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    private function item(array $overrides = []): ContentItem
    {
        return new ContentItem(...array_replace(['source' => 'met', 'external_id' => '123', 'media_type' => 'image', 'source_url' => 'https://www.metmuseum.org/art/123', 'media_url' => 'https://images.metmuseum.org/a.jpg', 'original_title' => 'Mountain landscape', 'creator_name' => 'Original painter', 'license' => 'Public Domain', 'license_url' => 'https://creativecommons.org/publicdomain/zero/1.0/', 'original_tags' => ['landscape', 'flowers'], 'creation_date' => '1889'], $overrides));
    }

    private function owner(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_demo' => true])->save();

        return $u;
    }

    public function test_import_preserves_creator_tags_media_and_defers_all_ai_paths(): void
    {
        Queue::fake();
        Http::fake(['images.metmuseum.org/*' => fn () => Http::response($this->image(), 200)]);
        $owner = $this->owner();
        $r = app(DemoImporter::class)->import($this->item(), $owner, 'nature', 42);
        $this->assertSame('imported', $r['status']);
        $a = Artwork::findOrFail($r['artwork_id']);
        $this->assertSame($owner->id, $a->user_id);
        $this->assertSame('Original painter', $a->provenance->creator_name);
        $this->assertContains('цветы', $a->tags->pluck('name')->all());
        $this->assertFalse($a->indexingEnabled());
        $this->assertFalse((bool) $a->has_ai);
        $this->assertTrue(is_file($a->getFirstMedia('artworks')->getPath('thumb')));
        $this->assertFalse(app(ArtworkIndexingService::class)->dispatch($a, true));
        $this->artisan('ai:reconcile')->assertSuccessful();
        (new AnalyzeAndIndexArtworkJob($a->id))->handle(app(AiServiceClient::class));
        Queue::assertNotPushed(AnalyzeAndIndexArtworkJob::class);
        $this->artisan('ai:reindex-all')->assertSuccessful();
        $this->artisan('ai:index-artwork', ['id' => $a->id])->assertFailed();
        $this->assertDatabaseCount('artwork_ai_metadata', 0);
        $this->assertSame('existing', app(DemoImporter::class)->import($this->item(), $owner, 'nature', 42)['status']);
        $this->assertSame('duplicate', app(DemoImporter::class)->import($this->item(['external_id' => '124']), $owner, 'nature', 42)['status']);
        $this->assertDatabaseCount('artworks', 1);
    }

    public function test_license_and_missing_metadata_are_rejected_without_download(): void
    {
        $owner = $this->owner();
        foreach (['CC BY-SA', 'CC BY-NC', 'unverified', 'copyright'] as $license) {
            $this->assertSame('license', app(DemoImporter::class)->import($this->item(['license' => $license]), $owner, 'nature', 42)['status']);
        }
        $this->assertSame('metadata', app(DemoImporter::class)->import($this->item(['original_tags' => []]), $owner, 'nature', 42)['status']);
        Http::assertNothingSent();
        $this->assertFalse((bool) app(SourceRegistry::class)->allowed($this->item(['license' => 'CC BY'])));
        $this->assertTrue((bool) app(SourceRegistry::class)->allowed($this->item(['license' => 'CC BY', 'license_url' => 'https://creativecommons.org/licenses/by/4.0/', 'attribution_text' => 'Original painter, CC BY 4.0'])));
    }

    public function test_dry_run_and_production_are_side_effect_free(): void
    {
        $this->artisan('demo:populate', ['--images' => 50, '--videos' => 0, '--authors' => 8, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
        $this->assertDirectoryDoesNotExist(config('demo_content.root'));
        Http::assertNothingSent();
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('demo:populate', ['--dry-run' => true])->assertFailed();
        $this->artisan('demo:verify')->assertFailed();
    }

    public function test_profiles_use_local_png_and_repeated_runs_do_not_overwrite_user_edits(): void
    {
        $profiles = app(DemoProfiles::class)->prepare(2, 42, config('demo_content.presets.balanced'));
        $first = $profiles[0];
        $this->assertTrue((bool) $first->is_demo);
        $this->assertStringEndsWith('.test', $first->email);
        $this->assertSame('image/png', getimagesize(Storage::disk('public')->path($first->profile_photo_path))['mime']);
        $this->assertNotSame(Storage::disk('public')->get($first->profile_photo_path), Storage::disk('public')->get($profiles[1]->profile_photo_path));
        $first->update(['description' => 'User-edited bio']);
        app(DemoProfiles::class)->prepare(2, 42, config('demo_content.presets.balanced'));
        $this->assertSame('User-edited bio', $first->fresh()->description);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_manifest_resumes_stored_candidate_and_verifier_detects_missing_file(): void
    {
        Queue::fake();
        $man = app(Manifest::class);
        config(['demo_content.presets.tiny' => ['nature' => ['label' => 'Nature', 'quota' => 1, 'queries' => ['landscape'], 'sources' => ['met']]]]);
        $man->save($man->path(42, 'tiny'), ['seed' => 42, 'preset' => 'tiny', 'entries' => ['met:123' => ['item' => (array) $this->item(), 'category' => 'nature', 'status' => 'processing']], 'api_errors' => []]);
        Http::fake(['images.metmuseum.org/*' => Http::response($this->image(), 200)]);
        $this->artisan('demo:populate', ['--preset' => 'tiny', '--images' => 1, '--videos' => 0, '--authors' => 1, '--resume' => true])->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('artworks', 1);
        $this->artisan('demo:verify')->assertSuccessful();
        $a = Artwork::first();
        unlink($a->getFirstMedia('artworks')->getPath());
        $this->artisan('demo:verify')->assertFailed();
    }

    public function test_api_403_404_and_429_do_not_cause_unbounded_retries(): void
    {
        config(['demo_content.sources.met.retry_attempts' => 1]);
        Http::fake(['*' => Http::sequence()->push([], 403)->push([], 404)->push([], 429, ['Retry-After' => '0'])->push(['ok' => true])]);
        $sent = 0;
        foreach ([403, 404] as $status) {
            try {
                app(SourceHttp::class)->get('met', '/test');
                $this->fail('Expected failure');
            } catch (RequestException $e) {
                $this->assertSame($status, $e->response->status());
            }
            Http::assertSentCount(++$sent);
        }
        $this->assertTrue(app(SourceHttp::class)->get('met', '/test')['ok']);
        Http::assertSentCount(4);
    }

    public function test_private_addresses_and_redirects_are_rejected(): void
    {
        $policy = new class extends MediaUrlPolicy
        {
            public function addresses(string $host): array
            {
                return ['127.0.0.1'];
            }
        };
        foreach (['http://images.metmuseum.org/a', 'https://localhost/a', 'https://images.metmuseum.org/a', 'https://x:y@images.metmuseum.org/a'] as $url) {
            try {
                $policy->options($url, ['images.metmuseum.org']);
                $this->fail('Unsafe URL accepted');
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_short_video_has_real_preview_duration_and_thumbnail(): void
    {
        $finder = new ExecutableFinder;
        $ffmpeg = $finder->find('ffmpeg');
        $ffprobe = $finder->find('ffprobe');
        if (! $ffmpeg || ! $ffprobe) {
            $this->markTestSkipped('FFmpeg/FFprobe required');
        }
        config(['media-library.ffmpeg_path' => $ffmpeg, 'media-library.ffprobe_path' => $ffprobe]);
        $path = storage_path('framework/testing/demo-video-'.bin2hex(random_bytes(5)).'.mp4');
        (new Process([$ffmpeg, '-y', '-f', 'lavfi', '-i', 'color=c=black:s=64x64:d=1', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $path]))->mustRun();
        try {
            Http::fake(['images.metmuseum.org/*' => fn () => Http::response(file_get_contents($path), 200)]);
            $item = $this->item(['media_type' => 'video', 'media_url' => 'https://images.metmuseum.org/test.mp4']);
            $result = app(DemoImporter::class)->import($item, $this->owner(), 'science', 42);
            $this->assertSame('imported', $result['status']);
            $a = Artwork::find($result['artwork_id']);
            $this->assertGreaterThan(0, $a->getFirstMedia('artworks')->getCustomProperty('duration'));
            $this->assertFileExists($a->getFirstMedia('preview')->getPath());
            $this->artisan('artworks:check-imports')->assertSuccessful();
            $this->assertFileExists($a->getFirstMedia('artworks')->getPath('thumb'));
        } finally {
            @unlink($path);
        }
    }
}
