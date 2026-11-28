<?php

namespace Tests\Feature;

use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Models\AssistantConversation;
use App\Models\AssistantTurn;
use App\Models\User;
use App\Services\AiService\ArtworkIndexingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssistantSearchDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    public function test_export_redacts_secrets_recursively_including_conversation_label(): void
    {
        config(['ai.api_key' => 'unique-service-secret', 'app.key' => 'unique-app-secret']);
        $export = app(\App\Services\Search\AssistantConversationExport::class);
        $method = new \ReflectionMethod($export, 'json');
        $encoded = $method->invoke($export, [
            'conversation' => ['label' => 'password=hunter2 unique-service-secret'],
            'diagnostics' => ['query' => ['original' => 'Bearer abcdefghijkl unique-app-secret']],
        ]);
        foreach (['hunter2', 'unique-service-secret', 'abcdefghijkl', 'unique-app-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        Storage::fake('public');
        Queue::fake();
        Http::preventStrayRequests();
        $this->owner = User::factory()->unverified()->create();
        $this->app->instance('env', 'local');
        config([
            'ai.enabled' => true, 'ai.assistant_enabled' => true, 'ai.assistant_rag_enabled' => true,
            'ai.assistant_history_enabled' => true, 'ai.assistant_context_enabled' => false,
            'ai.auto_analyze_on_publish' => false, 'ai.auto_reindex_on_update' => false,
            'ai.assistant_debug_enabled' => true,
            'ai.service_url' => 'http://ai.test', 'ai.api_key' => 'test-never-export',
        ]);
    }

    public function test_trace_captures_the_existing_search_and_preserves_order_and_contract_when_off(): void
    {
        $bird = $this->image('Bird with a view');
        $other = $this->image('A seaside painting');
        $traceConversation = $this->conversation();
        $ordinaryConversation = $this->conversation();
        Http::fake([
            'http://ai.test/api/v1/search' => Http::response([
                'results' => [['artwork_id' => $other->id, 'rank' => 1, 'score' => 0.71354],
                    ['artwork_id' => $bird->id, 'rank' => 2, 'score' => 0.6257]],
                'model' => 'test-embedding', 'latency_ms' => 5,
            ], headers: ['X-Assistant-Dense-Stages' => '{"qdrant_count":2,"embedding_ms":2.12}']),
            'http://ai.test/api/v1/assistant/compose' => Http::response([
                'action' => 'compose',
                'selections' => [['source_ref' => 's1', 'fact_ref' => 'f1']],
            ], headers: ['X-Assistant-Model' => 'test-chat', 'X-Assistant-Prompt-Tokens' => '123']),
        ]);

        $this->actingAs($this->owner);
        $captured = $this->postJson($traceConversation.'/turns', ['query' => 'Bird'], ['X-Assistant-Debug' => '1'])
            ->assertCreated()->assertJsonPath('status', 'ok');
        $debug = $captured->json('diagnostics');
        $byId = collect($debug['candidates'])->keyBy('artwork_id');
        $this->assertSame(2, $byId[$bird->id]['dense_rank']);
        $this->assertSame(0.6257, $byId[$bird->id]['dense_score']);
        $this->assertSame(1, $byId[$bird->id]['lexical_rank']);
        $this->assertSame(1, $byId[$bird->id]['hybrid_rank']);
        $this->assertSame(0.032522, $byId[$bird->id]['rrf_score']);
        $this->assertNull($byId[$other->id]['lexical_rank']);
        $this->assertContains('not_selected_by_composer', $byId[$other->id]['exclusions']);
        $this->assertSame('vision', $byId[$bird->id]['metadata']['analysis_source']);
        $this->assertSame('test-chat', $debug['configuration']['models']['composer']);
        $this->assertSame(2, $debug['dense_service']['qdrant_count']);
        $this->assertSame('legacy', $debug['rag']['strategy']);
        $this->assertSame(['prompt_tokens' => 123], $debug['rag']['composer_usage']);
        $this->assertSame($bird->id, $debug['rag']['selected'][0]['artwork_id']);
        $this->assertSame('Bird', $debug['query']['used']);
        $this->assertStringNotContainsString('seaside', AssistantTurn::findOrFail($captured->json('turn_id'))->getRawOriginal('diagnostics'));
        Http::assertSentCount(2);

        $this->getJson($traceConversation, ['X-Assistant-Debug' => '1'])
            ->assertOk()->assertJsonPath('turns.0.diagnostics', $debug);
        $this->getJson($traceConversation)->assertOk()->assertJsonMissingPath('turns.0.diagnostics');
        Http::assertSentCount(2);
        config(['ai.assistant_debug_enabled' => false]);
        $ordinary = $this->postJson($ordinaryConversation.'/turns', ['query' => 'Bird'], ['X-Assistant-Debug' => '1'])
            ->assertCreated()->assertJsonMissingPath('diagnostics');
        $this->assertSame($captured->json('sources'), $ordinary->json('sources'));
        $this->assertNull(AssistantTurn::findOrFail($ordinary->json('turn_id'))->diagnostics);
        Http::assertSentCount(4);
    }

    public function test_export_walks_all_pages_and_authorizes_every_historical_candidate_without_inference(): void
    {
        $work = $this->image('A work later made private');
        $available = $this->image('A publicly visible fallback card');
        $url = $this->conversation();
        Http::fake([
            'http://ai.test/api/v1/search' => Http::response(['results' => [
                ['artwork_id' => $work->id, 'rank' => 1, 'score' => 0.7],
                ['artwork_id' => $available->id, 'rank' => 2, 'score' => 0.6],
            ]]),
            'http://ai.test/api/v1/assistant/compose' => Http::response([], 504),
        ]);
        $this->actingAs($this->owner);
        $conversation = AssistantConversation::findOrFail(basename($url));
        for ($sequence = 1; $sequence <= 21; $sequence++) {
            $conversation->turns()->create(['sequence' => $sequence, 'user_query' => 'Запрос '.$sequence,
                'result_status' => 'no_results', 'requested_limit' => 10, 'source_references' => []]);
        }
        // A resolver is immaterial here. Inject an independent query recognized by the existing context rules.
        $post = $this->postJson($url.'/turns', ['query' => 'Покажи изображения птиц'], ['X-Assistant-Debug' => '1'])
            ->assertCreated()->assertJsonPath('status', 'search_fallback');
        $last = $post->json('turn_id');
        $work->updateQuietly(['is_private' => true]);

        $response = $this->get($url.'/export?debug=1')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString('library-stroll-chat-', $response->headers->get('Content-Disposition'));
        $export = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(44, $export['messages']);
        $this->assertSame('Запрос 1', $export['messages'][0]['query']);
        $this->assertSame($last, $export['messages'][43]['turn_id']);
        $this->assertNull($export['diagnostics'][$export['messages'][0]['turn_id']]);
        $this->assertSame([$available->id], array_column($export['sources'][$last], 'artwork_id'));
        $this->assertSame([$available->id], array_column($export['diagnostics'][$last]['candidates'], 'artwork_id'));
        $this->assertNotContains($work->id, array_column($export['rag'][$last]['facts'], 'artwork_id'));
        $this->assertSame('legacy', $export['rag'][$last]['strategy']);
        $this->assertSame([$available->id], array_column($export['rag'][$last]['available'], 'artwork_id'));
        $this->assertSame('service_timeout', $export['errors'][$last]['events'][0]['code']);
        $this->assertStringNotContainsString('test-never-export', $response->streamedContent());
        $this->getJson($url, ['X-Assistant-Debug' => '1'])->assertOk()
            ->assertJsonPath('turns.0.diagnostics.candidates.0.artwork_id', $available->id);

        $this->app->instance('env', 'production');
        $this->get($url.'/export?debug=1')->assertForbidden();
        $basic = json_decode($this->get($url.'/export')->assertOk()->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(44, $basic['messages']);
        $this->assertArrayNotHasKey('diagnostics', $basic);
        $this->assertSame([$available->id], array_column($basic['sources'][$last], 'artwork_id'));
        $this->app->instance('env', 'local');
        config(['ai.assistant_debug_enabled' => false]);
        $this->get($url.'/export?debug=1')->assertForbidden();
        $this->actingAs(User::factory()->create())->get($url.'/export')->assertNotFound();
        $this->get($url.'/export?debug=1')->assertNotFound();
        Http::assertSentCount(2);
        Queue::assertNothingPushed();
    }

    public function test_research_export_preserves_card_order_when_composer_cites_sources_in_reverse(): void
    {
        $first = $this->image('First card');
        $second = $this->image('Second card');
        $url = $this->conversation();
        Http::fake([
            'http://ai.test/api/v1/search' => Http::response(['results' => [
                ['artwork_id' => $first->id, 'rank' => 1, 'score' => 0.7],
                ['artwork_id' => $second->id, 'rank' => 2, 'score' => 0.6],
            ]]),
            'http://ai.test/api/v1/assistant/compose' => Http::response(['action' => 'compose', 'selections' => [
                ['source_ref' => 's2', 'fact_ref' => 'f1'],
                ['source_ref' => 's1', 'fact_ref' => 'f1'],
            ]]),
        ]);

        $this->actingAs($this->owner);
        $turn = $this->postJson($url.'/turns', ['query' => 'Find something'], ['X-Assistant-Debug' => '1'])
            ->assertCreated()->assertJsonPath('status', 'ok')->json();
        $export = json_decode($this->get($url.'/export?debug=1')->assertOk()->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $sources = $export['sources'][$turn['turn_id']];
        $this->assertSame([$first->id, $second->id], array_column($sources, 'artwork_id'));
        $idsByReference = array_column($sources, 'artwork_id', 'source_ref');
        $this->assertSame([$second->id, $first->id], array_map(
            fn ($fact) => $idsByReference[$fact['source_ref']], $export['messages'][1]['answer'],
        ));
        Http::assertSentCount(2);
    }

    private function conversation(): string
    {
        return '/assistant/conversations/'.AssistantConversation::factory()->for($this->owner)->create()->id;
    }

    private function image(string $title): Artwork
    {
        $work = Artwork::factory()->create(['title' => $title]);
        $media = $work->addMedia(base_path('public/images/favicons/apple.png'))->preservingOriginal()->toMediaCollection('artworks');
        $hash = app(ArtworkIndexingService::class)->contentHash($work);
        $work->aiMetadata()->create(['status' => 'indexed', 'indexed_at' => now(), 'summary' => 'An image with blue details',
            'content_hash' => $hash, 'source_media_uuid' => $media->uuid, 'objects' => ['bird'], 'vision_model' => 'test-vision']);
        AiProcessingRun::create(['artwork_id' => $work->id, 'operation' => 'analyze_and_index', 'status' => 'success',
            'started_at' => now(), 'finished_at' => now(), 'parameters' => ['content_hash' => $hash, 'analysis_source' => 'vision']]);

        return $work;
    }
}
