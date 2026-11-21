<?php

namespace Tests\Feature;

use App\Actions\Artwork\DeleteArtwork;
use App\Actions\Artwork\UpdateArtwork;
use App\Actions\Jetstream\DeleteUser;
use App\Contracts\AiServiceClient;
use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Jobs\DeleteArtworkIndexJob;
use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Models\Models\Complaint\Complaint;
use App\Models\Models\Complaint\ComplaintType;
use App\Models\User;
use App\Services\AiService\ArtworkIndexingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ArtworkIndexVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => true, 'ai.service_url' => 'http://ai.test', 'ai.retry_times' => 1]);
        Queue::fake();
    }

    public function test_privacy_change_commits_before_cleanup_is_queued(): void
    {
        $artwork = $this->indexedArtwork();
        DB::transaction(function () use ($artwork) {
            app(UpdateArtwork::class)->handle($artwork, $artwork->user, ['is_private' => true]);

            Queue::assertNothingPushed();
            $this->assertSame('pending', AiProcessingRun::sole()->status);
        });

        Queue::assertPushedOn('ai', DeleteArtworkIndexJob::class);
        Queue::assertNotPushed(AnalyzeAndIndexArtworkJob::class);
        $this->assertTrue((bool) $artwork->fresh()->is_private);
        $this->assertTrue($artwork->aiMetadata()->sole()->isStale());
        Http::assertNothingSent();
    }

    public function test_studio_publish_waits_for_commit_and_discards_a_rolled_back_job(): void
    {
        $draft = Artwork::factory()->create(['title' => 'Draft', 'is_published' => false, 'is_private' => false]);
        $draft->media()->create([
            'collection_name' => 'artworks', 'name' => 'Fixture', 'file_name' => 'fixture.png',
            'disk' => 'public', 'conversions_disk' => 'public', 'size' => 100,
            'manipulations' => [], 'custom_properties' => [], 'generated_conversions' => [], 'responsive_images' => [],
        ]);
        $this->actingAs($draft->user);
        DB::beginTransaction();
        try {
            $this->postJson("/studio/publish/{$draft->id}")->assertOk();
            Queue::assertNothingPushed();
        } finally {
            DB::rollBack();
        }
        $this->assertFalse((bool) $draft->fresh()->is_published);
        Queue::assertNothingPushed();

        $this->postJson("/studio/publish/{$draft->id}")->assertOk();
        $this->assertTrue((bool) $draft->fresh()->is_published);
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, 1);
        Http::assertNothingSent();
    }

    #[DataProvider('visibilityMutations')]
    public function test_visibility_mutations_roll_back_with_their_cleanup_intent(string $mutation): void
    {
        $artwork = $this->indexedArtwork();
        $metadata = $artwork->aiMetadata()->sole()->getRawOriginal();
        DB::beginTransaction();
        try {
            match ($mutation) {
                'privacy' => app(UpdateArtwork::class)->handle($artwork, $artwork->user, ['is_private' => true]),
                'delete' => app(DeleteArtwork::class)->handle($artwork),
                'author' => $artwork->user->block(),
            };
            $this->assertDatabaseCount('ai_processing_runs', 1);
            Queue::assertNothingPushed();
        } finally {
            DB::rollBack();
        }

        $this->assertTrue(app(ArtworkIndexingService::class)->canIndex($artwork->fresh()));
        $this->assertSame($metadata, $artwork->aiMetadata()->sole()->getRawOriginal());
        $this->assertDatabaseCount('ai_processing_runs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public static function visibilityMutations(): array
    {
        return [['privacy'], ['delete'], ['author']];
    }

    #[DataProvider('restrictions')]
    public function test_stale_index_jobs_do_not_start_for_hidden_or_disabled_work(string $restriction): void
    {
        $artwork = $this->indexedArtwork();
        $job = new AnalyzeAndIndexArtworkJob($artwork->id, $artwork->aiMetadata()->sole()->content_hash);

        $this->restrict($artwork, $restriction);

        $job->middleware()[0]->handle($job, fn () => $job->handle(app(AiServiceClient::class)));

        Queue::assertPushed(DeleteArtworkIndexJob::class, 1);
        Queue::assertNotPushed(AnalyzeAndIndexArtworkJob::class);
        Http::assertNothingSent();
        $this->assertTrue($artwork->aiMetadata()->sole()->isStale());
    }

    public static function restrictions(): array
    {
        return [['private'], ['unpublished'], ['blocked'], ['author'], ['disabled']];
    }

    public function test_cleanup_cannot_delete_the_index_of_a_republished_revision(): void
    {
        $artwork = $this->indexedArtwork();
        $staleCopy = $artwork->fresh();
        $artwork->update(['is_published' => false]);
        $cleanup = Queue::pushed(DeleteArtworkIndexJob::class)->sole();
        $artwork->update(['is_published' => true]);
        $updated = app(UpdateArtwork::class)->handle($staleCopy, $staleCopy->user, ['title' => 'Latest committed title']);

        $this->assertTrue((bool) $updated->is_published);
        $hash = app(ArtworkIndexingService::class)->contentHash($updated);
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, fn ($job) => str_ends_with($job->uniqueId(), $hash));
        $cleanup->middleware()[0]->handle($cleanup, fn () => $cleanup->handle(app(AiServiceClient::class)));

        Http::assertNothingSent();
        $this->assertSame('skipped', AiProcessingRun::sole()->status);
    }

    public function test_author_deletion_preserves_index_removal_ids_despite_the_sql_cascade(): void
    {
        $author = User::factory()->create();
        $work = $this->indexedArtwork(['user_id' => $author->id]);
        app(DeleteUser::class)->delete($author);

        Queue::assertPushed(DeleteArtworkIndexJob::class, 1);
        $this->assertDatabaseMissing('artworks', ['id' => $work->id]);
        $intent = AiProcessingRun::sole();
        $this->assertNull($intent->artwork_id);
        $this->assertSame($work->id, $intent->parameters['deleted_artwork_id']);
    }

    public function test_moderation_route_revokes_and_then_restores_an_authors_index_eligibility(): void
    {
        Notification::fake();
        $moderator = User::factory()->create();
        $moderator->assignRole(Role::firstOrCreate(['name' => 'Moderator', 'guard_name' => 'web']));
        $work = $this->indexedArtwork();
        $type = ComplaintType::create(['slug' => 'test', 'name' => 'Test']);
        $complaint = Complaint::create([
            'user_id' => User::factory()->create()->id,
            'complaint_type_id' => $type->id,
            'complaintable_type' => User::class,
            'complaintable_id' => $work->user_id,
        ]);

        $this->actingAs($moderator)->post("/moderation/complaints/{$complaint->id}/review", ['status' => 'approved'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['id' => $work->user_id, 'is_blocked' => true]);
        Queue::assertPushed(DeleteArtworkIndexJob::class, 1);

        $this->post("/moderation/complaints/{$complaint->id}/review", ['status' => 'rejected'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['id' => $work->user_id, 'is_blocked' => false]);
        Queue::assertNotPushed(AnalyzeAndIndexArtworkJob::class);
        $this->artisan('ai:reconcile', ['--limit' => 1])->assertSuccessful();
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_cleanup_waits_for_active_index_and_a_duplicate_delivery_is_harmless(): void
    {
        $artwork = $this->indexedArtwork();
        $id = $artwork->id;
        $index = new AnalyzeAndIndexArtworkJob($id);
        app(DeleteArtwork::class)->handle($artwork);
        app(DeleteArtwork::class)->handle($artwork);
        Queue::assertPushed(DeleteArtworkIndexJob::class, 1);
        $cleanup = Queue::pushed(DeleteArtworkIndexJob::class)->sole()->withFakeQueueInteractions();
        Http::fake(["ai.test/api/v1/artworks/{$id}/index" => Http::response(['status' => 'deleted'])]);

        $index->middleware()[0]->handle($index, function () use ($cleanup) {
            $cleanup->middleware()[0]->handle($cleanup, fn () => $this->fail('Cleanup ran while indexing held the lock.'));
        });
        $cleanup->assertReleased(30);

        for ($delivery = 0; $delivery < 2; $delivery++) {
            $cleanup->middleware()[0]->handle($cleanup, fn () => $cleanup->handle(app(AiServiceClient::class)));
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
        $this->assertSame('success', AiProcessingRun::sole()->status);
    }

    #[DataProvider('changesDuringUpsert')]
    public function test_restriction_during_upsert_cannot_finalize_the_old_public_revision(string $restriction): void
    {
        $artwork = $this->indexedArtwork();
        $id = $artwork->id;
        Http::fake([
            'ai.test/api/v1/artworks/analyze' => Http::response(['artwork_id' => $id, 'summary' => 'Example']),
            'ai.test/api/v1/artworks/index' => function () use ($artwork, $restriction) {
                $this->assertSame('processing', $artwork->aiMetadata()->sole()->status);
                if ($restriction === 'delete') {
                    app(DeleteArtwork::class)->handle($artwork);
                } else {
                    $this->restrict($artwork, $restriction);
                }

                return Http::response(['qdrant_point_id' => 'fake-point', 'embedding_dimension' => 1024]);
            },
            "ai.test/api/v1/artworks/{$id}/index" => Http::response(['status' => 'deleted']),
        ]);
        $job = new AnalyzeAndIndexArtworkJob($id);
        $job->middleware()[0]->handle($job, fn () => $job->handle(app(AiServiceClient::class)));

        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE');
        Queue::assertPushed(DeleteArtworkIndexJob::class, 1);
        Queue::assertNotPushed(AnalyzeAndIndexArtworkJob::class);
        $this->assertSame('skipped', AiProcessingRun::where('operation', 'analyze_and_index')->sole()->status);
        $this->assertDatabaseMissing('artwork_ai_metadata', ['artwork_id' => $id, 'status' => 'indexed']);
    }

    public static function changesDuringUpsert(): array
    {
        return [['private'], ['unpublished'], ['blocked'], ['author'], ['disabled'], ['delete']];
    }

    public function test_redis_failure_leaves_replayable_cleanup_for_the_deleted_artwork(): void
    {
        $artwork = $this->indexedArtwork();
        Queue::beforePushing(fn () => throw new RuntimeException('Redis unavailable'));

        app(DeleteArtwork::class)->handle($artwork);

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('artworks', ['id' => $artwork->id]);
        $intent = AiProcessingRun::sole();
        $this->assertSame('pending', $intent->status);
        $this->assertNull($intent->artwork_id);
        $this->assertSame($artwork->id, $intent->parameters['deleted_artwork_id']);

        Queue::fake();
        $this->artisan('ai:delete-index', ['id' => $artwork->id + 1, '--intent' => (string) $intent->id])->assertFailed();
        Queue::assertNothingPushed();
        $this->artisan('ai:delete-index', ['id' => $artwork->id, '--intent' => (string) $intent->id])->assertSuccessful();
        Queue::assertPushed(DeleteArtworkIndexJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_worker_failure_does_not_overwrite_the_visibility_invalidation(): void
    {
        $artwork = $this->indexedArtwork();
        Http::fake([
            'ai.test/api/v1/artworks/analyze' => function () use ($artwork) {
                $artwork->update(['is_private' => true]);

                return Http::response(['detail' => 'Unavailable'], 503);
            },
        ]);
        $job = new AnalyzeAndIndexArtworkJob($artwork->id);
        try {
            $job->middleware()[0]->handle($job, fn () => $job->handle(app(AiServiceClient::class)));
            $this->fail('Expected analysis to fail.');
        } catch (\Illuminate\Http\Client\RequestException $error) {
            $this->assertSame(503, $error->response->status());
        }

        $this->assertSame('stale', $artwork->aiMetadata()->sole()->status);
        $this->assertSame('failed', AiProcessingRun::where('operation', 'analyze_and_index')->sole()->status);
        Queue::assertPushed(DeleteArtworkIndexJob::class, 1);
    }

    public function test_an_old_worker_cannot_replace_or_retry_over_a_newer_completed_revision(): void
    {
        $artwork = $this->indexedArtwork();
        $id = $artwork->id;
        $hash = $artwork->aiMetadata()->sole()->content_hash;
        Http::fake([
            'ai.test/api/v1/artworks/analyze' => Http::response(['artwork_id' => $id, 'summary' => 'Older analysis']),
            'ai.test/api/v1/artworks/index' => function () use ($artwork) {
                $artwork->aiMetadata()->sole()->update([
                    'status' => 'indexed', 'summary' => 'Newer analysis', 'qdrant_point_id' => 'newer-point',
                ]);

                return Http::response(['qdrant_point_id' => 'older-point', 'embedding_dimension' => 1024]);
            },
        ]);
        $job = (new AnalyzeAndIndexArtworkJob($id, $hash))->withFakeQueueInteractions();
        $job->middleware()[0]->handle($job, fn () => $job->handle(app(AiServiceClient::class)));

        $job->assertNotReleased();
        Queue::assertNothingPushed();
        $metadata = $artwork->aiMetadata()->sole();
        $this->assertSame('indexed', $metadata->status);
        $this->assertSame('Newer analysis', $metadata->summary);
        $this->assertSame('newer-point', $metadata->qdrant_point_id);
        $this->assertSame('skipped', AiProcessingRun::sole()->status);
    }

    private function indexedArtwork(array $attributes = []): Artwork
    {
        $artwork = Artwork::factory()->create($attributes + ['is_published' => true, 'is_private' => false]);
        $artwork->aiMetadata()->create([
            'content_hash' => app(ArtworkIndexingService::class)->contentHash($artwork),
            'status' => 'indexed',
            'attempts' => 1,
        ]);

        return $artwork;
    }

    private function restrict(Artwork $artwork, string $restriction): void
    {
        match ($restriction) {
            'private' => $artwork->update(['is_private' => true]),
            'unpublished' => $artwork->update(['is_published' => false]),
            'blocked' => $artwork->block(),
            'author' => $artwork->user->block(),
            'disabled' => $artwork->forceFill(['ai_indexing_enabled' => false])->save(),
        };
    }
}
