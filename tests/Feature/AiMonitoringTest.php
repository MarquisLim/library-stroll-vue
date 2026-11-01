<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Models\User;
use App\Services\AiService\ArtworkIndexingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AiMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => false, 'ai.service_url' => 'http://ai.test', 'ai.api_key' => 'secret-ai-key', 'ai.retry_times' => 1]);
        Http::preventStrayRequests();
    }

    private function admin(string $role = 'Admin'): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    private function artwork(array $attributes = []): Artwork
    {
        return Artwork::factory()->create([...['is_published' => true, 'is_private' => false, 'is_blocked' => false], ...$attributes]);
    }

    public function test_all_monitor_endpoints_are_restricted_to_administrators(): void
    {
        $this->getJson('/admin/ai/data')->assertUnauthorized();
        $this->admin('Moderator');
        $this->get('/admin/ai')->assertForbidden();
        $this->getJson('/admin/ai/data')->assertForbidden();
        $this->postJson('/admin/ai/diagnostics')->assertForbidden();
        $this->postJson('/admin/ai/search', ['query' => 'test'])->assertForbidden();
        $this->admin('SuperAdmin');
        $this->getJson('/admin/ai/data')->assertOk();
    }

    public function test_snapshot_counts_published_hidden_works_filters_and_redacts_secrets(): void
    {
        $this->admin();
        $indexed = $this->artwork(['is_private' => true, 'title' => 'Private indexed']);
        ArtworkAiMetadata::create(['artwork_id' => $indexed->id, 'status' => 'indexed']);
        $failed = $this->artwork(['is_blocked' => true]);
        ArtworkAiMetadata::create(['artwork_id' => $failed->id, 'status' => 'failed', 'last_error' => 'secret-ai-key http://user:password@ai.test']);
        $this->artwork();
        $this->artwork(['is_published' => false]);
        $this->getJson('/admin/ai/data')->assertOk()->assertJsonPath('stats.total', 3)->assertJsonPath('stats.indexed', 1)->assertJsonPath('stats.failed', 1)->assertJsonPath('stats.missing', 1)->assertJsonPath('stats.coverage', 33.3);
        $response = $this->getJson('/admin/ai/data?status=failed')->assertOk()->assertJsonCount(1, 'artworks.data')->assertJsonPath('artworks.data.0.id', $failed->id);
        $this->assertStringNotContainsString('secret-ai-key', $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());
        $this->getJson('/admin/ai/data?search=Private')->assertJsonCount(1, 'artworks.data');
        $this->getJson('/admin/ai/data?status=missing')->assertJsonCount(1, 'artworks.data');
        $this->getJson('/admin/ai/data?status=invalid')->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_running_attempt_is_visible_and_overdue_is_not_reported_as_success(): void
    {
        $this->admin();
        $artwork = $this->artwork();
        AiProcessingRun::create(['artwork_id' => $artwork->id, 'operation' => 'analyze_and_index', 'status' => 'processing', 'request_id' => 'run-1', 'started_at' => now()->subMinutes(12), 'parameters' => ['stage' => 'embedding_and_indexing', 'api_key' => 'secret-ai-key']]);
        $response = $this->getJson('/admin/ai/data')->assertOk()->assertJsonPath('active_runs.0.stage', 'embedding_and_indexing')->assertJsonPath('active_runs.0.overdue', true)->assertJsonPath('history.0.status', 'processing');
        $this->assertStringNotContainsString('secret-ai-key', $response->getContent());
    }

    public function test_disabled_diagnostics_and_search_do_not_call_ai(): void
    {
        $this->admin();
        $this->postJson('/admin/ai/diagnostics')->assertOk()->assertJsonPath('status', 'disabled');
        $this->postJson('/admin/ai/search', ['query' => 'sunset'])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_diagnostics_selects_safe_fields_and_authenticates_to_ai_service(): void
    {
        $this->admin();
        config(['ai.enabled' => true, 'queue.default' => 'sync']);
        Http::fake(['*/api/v1/diagnostics' => Http::response(['status' => 'ok', 'api_key' => 'secret-ai-key', 'ollama' => ['status' => 'ok', 'models' => [['role' => 'chat', 'name' => 'test-model', 'installed' => true, 'secret' => 'hidden']]], 'qdrant' => ['status' => 'ok', 'dimension' => 1024, 'url' => 'sensitive']])]);
        $response = $this->postJson('/admin/ai/diagnostics')->assertOk()->assertJsonPath('ollama.models.0.name', 'test-model')->assertJsonPath('qdrant.dimension', 1024)->assertJsonPath('queue.status', 'not_async');
        $this->assertStringNotContainsString('secret-ai-key', $response->getContent());
        $this->assertStringNotContainsString('sensitive', $response->getContent());
        Http::assertSent(fn ($request) => $request->hasHeader('X-AI-Service-Key', 'secret-ai-key'));
    }

    public function test_unavailable_service_returns_actionable_diagnostics_without_raw_error(): void
    {
        $this->admin();
        config(['ai.enabled' => true, 'queue.default' => 'sync']);
        Http::fake(['*' => Http::response(['error' => 'secret-ai-key'], 500)]);
        $this->postJson('/admin/ai/diagnostics')->assertOk()->assertJsonPath('status', 'unavailable')->assertJsonMissing(['error' => 'secret-ai-key']);
    }

    public function test_search_executes_vector_query_but_filters_private_blocked_and_draft_results(): void
    {
        $this->admin();
        config(['ai.enabled' => true]);
        $public = $this->artwork();
        $private = $this->artwork(['is_private' => true]);
        $blocked = $this->artwork(['is_blocked' => true]);
        $draft = $this->artwork(['is_published' => false]);
        Http::fake(['*/api/v1/search' => Http::response(['results' => collect([$public, $private, $blocked, $draft])->map(fn ($work, $index) => ['artwork_id' => $work->id, 'score' => 0.8, 'rank' => $index + 1])->all(), 'model' => 'embedding-test', 'latency_ms' => 15])]);
        $this->postJson('/admin/ai/search', ['query' => 'sunset'])->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.id', $public->id)->assertJsonPath('model', 'embedding-test');
        $this->postJson('/admin/ai/search', ['query' => str_repeat('a', 301)])->assertUnprocessable();
    }

    public function test_retry_uses_canonical_hash_and_rejects_duplicate_and_unsafe_queue_modes(): void
    {
        $this->admin();
        Queue::fake();
        config(['ai.enabled' => true, 'queue.default' => 'database']);
        $artwork = $this->artwork();
        $this->postJson("/admin/artworks/{$artwork->id}/reindex")->assertAccepted();
        $hash = app(ArtworkIndexingService::class)->contentHash($artwork);
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, fn ($job) => str_ends_with($job->uniqueId(), $hash));
        $this->postJson("/admin/artworks/{$artwork->id}/reindex")->assertConflict();
        $draft = $this->artwork(['is_published' => false]);
        $this->postJson("/admin/artworks/{$draft->id}/reindex")->assertUnprocessable();
        config(['queue.default' => 'sync']);
        $this->postJson("/admin/artworks/{$artwork->id}/reindex")->assertUnprocessable();
        Queue::assertPushed(AnalyzeAndIndexArtworkJob::class, 1);
    }
}
