<?php

namespace Tests\Feature;

use App\Actions\Artwork\UpdateArtwork;
use App\Contracts\AiServiceClient;
use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Jobs\DeleteArtworkIndexJob;
use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Models\Tag;
use App\Services\AiService\ArtworkIndexingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArtworkIndexingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => true, 'ai.service_url' => 'http://ai.test', 'ai.retry_times' => 1]);
        Http::preventStrayRequests();
    }

    private function artwork(): Artwork
    {
        return Artwork::factory()->create(['title' => 'Sunset', 'is_published' => true, 'is_private' => false]);
    }

    public function test_job_records_dimension_nonnegative_latency_and_user_tags(): void
    {
        $artwork = $this->artwork();
        $artwork->tags()->attach(Tag::create(['name' => 'author tag']));
        Http::fake([
            '*/api/v1/artworks/analyze' => function () use ($artwork) {
                usleep(5000);

                return Http::response(['artwork_id' => $artwork->id, 'summary' => 'Sunset landscape', 'styles' => ['watercolor']]);
            },
            '*/api/v1/artworks/index' => Http::response(['qdrant_point_id' => 'test-point', 'embedding_dimension' => 1024]),
        ]);
        $hash = app(ArtworkIndexingService::class)->contentHash($artwork);
        (new AnalyzeAndIndexArtworkJob($artwork->id, $hash))->handle(app(AiServiceClient::class));
        $metadata = $artwork->aiMetadata()->firstOrFail();
        $this->assertTrue($metadata->isIndexed());
        $this->assertSame(1024, $metadata->embedding_dimension);
        $this->assertSame($hash, $metadata->content_hash);
        $this->assertGreaterThanOrEqual(0, AiProcessingRun::firstOrFail()->latency_ms);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/index')
            && str_contains($r['searchable_text'], 'author tag')
            && str_contains($r['searchable_text'], 'watercolor')
            && $r['payload']['is_published'] === true);
    }

    public function test_tag_only_edit_changes_hash_and_dispatches_reindex(): void
    {
        Queue::fake();
        $artwork = $this->artwork();
        $hash = app(ArtworkIndexingService::class)->contentHash($artwork);
        ArtworkAiMetadata::create(['artwork_id' => $artwork->id, 'status' => 'indexed', 'content_hash' => $hash]);
        app(UpdateArtwork::class)->handle($artwork, $artwork->user, ['tags' => ['new tag']]);
        $this->assertNotSame($hash, app(ArtworkIndexingService::class)->contentHash($artwork->fresh()));
        Queue::assertPushedOn('ai', AnalyzeAndIndexArtworkJob::class);
        $this->assertSame('pending', $artwork->aiMetadata()->first()->status);
    }

    public function test_invalid_index_response_does_not_mark_artwork_indexed(): void
    {
        $artwork = $this->artwork();
        Http::fake([
            '*/artworks/analyze' => Http::response(['artwork_id' => $artwork->id, 'summary' => 'Test']),
            '*/artworks/index' => Http::response(['status' => 'indexed']),
        ]);
        try {
            (new AnalyzeAndIndexArtworkJob($artwork->id))->handle(app(AiServiceClient::class));
            $this->fail('Expected an invalid index response error.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('point ID', $exception->getMessage());
        }
        $this->assertSame('failed', $artwork->aiMetadata()->first()->status);
        $this->assertSame('failed', AiProcessingRun::first()->status);
    }

    public function test_unchanged_index_does_not_dispatch_but_force_does(): void
    {
        Queue::fake();
        $artwork = $this->artwork();
        $service = app(ArtworkIndexingService::class);
        ArtworkAiMetadata::create(['artwork_id' => $artwork->id, 'status' => 'indexed', 'content_hash' => $service->contentHash($artwork)]);
        $this->assertFalse($service->dispatch($artwork));
        Queue::assertNothingPushed();
        $this->assertTrue($service->dispatch($artwork, force: true));
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, 1);
    }

    public function test_obsolete_job_does_not_call_ollama_and_reschedules_current_revision(): void
    {
        Queue::fake();
        $artwork = $this->artwork();
        (new AnalyzeAndIndexArtworkJob($artwork->id, 'old-hash'))->handle(app(AiServiceClient::class));
        Http::assertNothingSent();
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, 1);
    }

    public function test_analysis_result_for_changed_artwork_is_not_indexed(): void
    {
        Queue::fake();
        $artwork = $this->artwork();
        Http::fake(['*/artworks/analyze' => function () use ($artwork) {
            $artwork->update(['title' => 'New version']);

            return Http::response(['artwork_id' => $artwork->id, 'summary' => 'Old version']);
        }]);
        (new AnalyzeAndIndexArtworkJob($artwork->id))->handle(app(AiServiceClient::class));
        Http::assertSentCount(1);
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class);
        $this->assertSame('pending', $artwork->aiMetadata()->first()->status);
    }

    public function test_delete_job_logs_after_artwork_deletion_without_foreign_key_error(): void
    {
        $artwork = $this->artwork();
        $id = $artwork->id;
        $artwork->delete();
        Http::fake(['*/index' => Http::response(['status' => 'deleted'])]);
        (new DeleteArtworkIndexJob($id))->handle(app(AiServiceClient::class));
        $run = AiProcessingRun::firstOrFail();
        $this->assertNull($run->artwork_id);
        $this->assertSame($id, $run->parameters['deleted_artwork_id']);
        $this->assertSame('success', $run->status);
        $this->assertGreaterThanOrEqual(0, $run->latency_ms);
    }

    public function test_failed_delete_logs_original_error_instead_of_foreign_key_exception(): void
    {
        Http::fake(['*/index' => Http::response(['detail' => 'offline'], 503)]);
        try {
            (new DeleteArtworkIndexJob(12345))->handle(app(AiServiceClient::class));
            $this->fail('Expected HTTP failure.');
        } catch (\Illuminate\Http\Client\RequestException $exception) {
            $this->assertSame(503, $exception->response->status());
        }
        $this->assertSame('failed', AiProcessingRun::firstOrFail()->status);
        $this->assertNull(AiProcessingRun::first()->artwork_id);
    }

    public function test_manual_index_rejects_draft_without_pending_metadata(): void
    {
        Queue::fake();
        $draft = Artwork::factory()->create(['is_published' => false]);
        $this->artisan('ai:index-artwork', ['id' => $draft->id])->assertFailed();
        $this->assertDatabaseCount('artwork_ai_metadata', 0);
        Queue::assertNothingPushed();
    }

    public function test_reconcile_recovers_stuck_processing_jobs(): void
    {
        Queue::fake();
        $artwork = $this->artwork();
        ArtworkAiMetadata::create(['artwork_id' => $artwork->id, 'status' => 'processing'])
            ->forceFill(['updated_at' => now()->subHours(4)])->save();
        $this->artisan('ai:reconcile')->assertSuccessful();
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class);
    }
}
