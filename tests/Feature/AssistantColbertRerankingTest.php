<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\AssistantConversation;
use App\Models\User;
use App\Services\AiService\ArtworkIndexingService;
use App\Services\Search\AssistantArtworkSearch;
use App\Services\Search\AssistantCandidateReranker;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssistantColbertRerankingTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://frozen-catalog.test/api/v1/rerank';

    private const DEBUG = ['X-Assistant-Debug' => '1'];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->instance('env', 'local');
        config(['ai.enabled' => true, 'ai.assistant_enabled' => true, 'ai.assistant_rag_enabled' => true,
            'ai.assistant_history_enabled' => true, 'ai.assistant_debug_enabled' => true,
            'ai.assistant_intent_routing_enabled' => false, 'ai.assistant_llm_enabled' => false,
            'ai.assistant_lexical_metadata_enabled' => true, 'ai.assistant_colbert_post_rrf_enabled' => true,
            'ai.assistant_colbert_url' => 'http://frozen-catalog.test', 'ai.assistant_colbert_depth' => 30,
            'ai.rerank_enabled' => false, 'ai.auto_analyze_on_publish' => false,
            'ai.auto_reindex_on_update' => false, 'ai.service_url' => 'http://ai.test', 'ai.api_key' => 'test-key']);
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('public');
        $this->user = User::factory()->unverified()->create();
        $this->actingAs($this->user);
    }

    public function test_post_rrf_sends_lexical_only_candidates_and_exports_rag_membership(): void
    {
        $dense = collect(range(1, 21))->map(fn ($offset) => $this->image('Dense picture '.$offset))->pluck('id')->all();
        $lexical = $this->image('Rare lexical subject');
        Http::fake([
            'ai.test/api/v1/search' => Http::response(['results' => $this->results($dense)]),
            'ai.test/api/v1/assistant/compose' => Http::response(['action' => 'compose',
                'selections' => [['source_ref' => 's1', 'fact_ref' => 'f1']]]),
            self::URL => function ($request) use ($lexical) {
                $sent = $request['artwork_ids'];
                $this->assertSame('Rare lexical subject', $request['query']);
                $this->assertSame(22, count($sent));
                $this->assertSame(22, count(array_unique($request['document_keys'])));
                return Http::response(['reranked' => true,
                    'results' => $this->results([$lexical->id, ...array_diff($sent, [$lexical->id])])]);
            },
        ]);
        $path = '/assistant/conversations/'.AssistantConversation::factory()->for($this->user)->create()->id;
        $turn = $this->postJson($path.'/turns', ['query' => 'Rare lexical subject'], self::DEBUG)->assertCreated()
            ->assertJsonPath('sources.0.artwork_id', $lexical->id)->json();
        $export = json_decode($this->get($path.'/export?debug=1')->assertOk()->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $trace = $export['diagnostics'][$turn['turn_id']];
        $this->assertSame('post_rrf', $trace['stages']['colbert']['strategy']);
        $this->assertSame($lexical->id, $export['rag'][$turn['turn_id']]['available'][0]['artwork_id']);
        $this->assertTrue(collect($trace['candidates'])->keyBy('artwork_id')->get($lexical->id)['lexical_only']);
        Http::assertSentCount(3);
    }

    public function test_candidate_beyond_assistant_window_can_enter_the_first_position(): void
    {
        $ids = collect(range(1, 22))->map(fn ($offset) => $this->image('Gallery example '.$offset))->pluck('id')->all();
        $last = $ids[21];
        Http::fake([
            'ai.test/api/v1/search' => Http::response(['results' => $this->results($ids)]),
            self::URL => Http::response(['reranked' => true, 'results' => $this->results([$last, ...array_slice($ids, 0, -1)])]),
        ]);
        $trace = $this->postJson('/assistant/search', ['query' => 'Abstract'], self::DEBUG)->assertOk()
            ->assertJsonPath('results.0.artwork_id', $last)->json('diagnostics');
        $selected = collect($trace['candidates'])->keyBy('artwork_id')->get($last);
        $this->assertSame(22, $selected['rrf_rank']);
        $this->assertSame(['before' => 22, 'after' => 1, 'score' => 1, 'document' => 'current'], $selected['colbert']);
    }

    public function test_checks_policy_privacy_indexing_and_files_before_contacting_colbert(): void
    {
        $first = $this->image('First');
        $private = $this->image('Private', ['is_private' => true]);
        $denied = $this->image('Denied');
        $disabled = $this->image('Disabled', ['ai_indexing_enabled' => false]);
        $missing = $this->image('Missing');
        $media = $missing->getFirstMedia('artworks');
        Storage::disk($media->disk)->delete($media->getPathRelativeToRoot());
        $second = $this->image('Second');
        Gate::before(fn ($user, $ability, $args) => $ability === 'view' && ($args[0]->id ?? null) === $denied->id ? false : null);
        Http::fake([
            self::URL => function ($request) use ($first, $second) {
                $this->assertSame([$first->id, $second->id], $request['artwork_ids']);
                return Http::response(['reranked' => true, 'results' => $this->results([$second->id, $first->id])]);
            },
        ]);
        $result = $this->rank([$first->id, $private->id, $denied->id, $disabled->id, $missing->id, $second->id]);
        $this->assertSame([$second->id, $private->id, $denied->id, $disabled->id, $missing->id, $first->id], $result['ids']);
        $this->assertSame([$first->id, $second->id], array_keys($result['ranks']));
    }

    public function test_stale_local_revision_falls_back_without_contacting_colbert(): void
    {
        $first = $this->image('First');
        $second = $this->image('Second');
        $second->aiMetadata()->update(['content_hash' => str_repeat('0', 64)]);
        Http::fake();
        $ids = [$first->id, $second->id];
        $result = $this->rank($ids);
        $this->assertSame($ids, $result['ids']);
        $this->assertSame('local_document_missing_or_stale', $result['fallback_reason']);
        $this->assertSame('missing_or_stale_local', $result['ranks'][$second->id]['document']);
        Http::assertNothingSent();
    }

    #[DataProvider('failures')]
    public function test_transport_and_invalid_permutations_keep_original_order(string $fault, string $fallback): void
    {
        $ids = [$this->image('First')->id, $this->image('Second')->id, $this->image('Third')->id];
        Http::fake([self::URL => fn () => match ($fault) {
            'timeout' => throw new ConnectionException('cURL error 28: timed out'),
            'unavailable' => throw new ConnectionException('Connection refused'),
            'stale' => Http::response([], 409),
            'missing' => Http::response([], 404),
            'json' => Http::response('broken JSON'),
            'duplicate' => Http::response(['reranked' => true, 'results' => $this->results([$ids[1], $ids[1], $ids[0]])]),
            'foreign' => Http::response(['reranked' => true, 'results' => $this->results([$ids[2], 999999, $ids[0]])]),
        }]);
        $result = $this->rank($ids);
        $this->assertSame($ids, $result['ids']);
        $this->assertSame($fallback, $result['fallback_reason']);
    }

    public static function failures(): array
    {
        return [
            ['timeout', 'timeout'], ['unavailable', 'service_unavailable'], ['stale', 'remote_document_stale'],
            ['missing', 'remote_document_missing'], ['json', 'invalid_response'],
            ['duplicate', 'invalid_response'], ['foreign', 'invalid_response'],
        ];
    }

    public function test_disabled_flag_and_ordinary_catalogue_do_not_call_experimental_colbert(): void
    {
        $ids = [$this->image('First')->id, $this->image('Second')->id];
        Http::fake(['ai.test/api/v1/search' => Http::response(['results' => $this->results($ids)])]);
        $this->get('/search?q=Abstract')->assertOk();
        config(['ai.assistant_colbert_post_rrf_enabled' => false]);
        $this->postJson('/assistant/search', ['query' => 'Abstract'], self::DEBUG)->assertOk()
            ->assertJsonPath('diagnostics.stages.colbert', null)->assertJsonPath('results.0.artwork_id', $ids[0]);
        Http::assertNotSent(fn ($request) => $request->url() === self::URL);
    }

    private function rank(array $ids): array
    {
        $search = app(AssistantArtworkSearch::class);
        return app(AssistantCandidateReranker::class)->rerank('test', $ids,
            fn (Collection $ids) => $search->visible($ids, $this->user, withMetadata: true), 20, 'post_rrf');
    }

    private function results(array $ids): array
    {
        return array_map(fn ($id, $offset) => ['artwork_id' => $id, 'score' => 1, 'rank' => $offset + 1], $ids, array_keys($ids));
    }

    private function image(string $title, array $attributes = []): Artwork
    {
        $work = Artwork::factory()->create(['title' => $title, 'description' => '', ...$attributes]);
        $media = $work->addMedia(base_path('public/images/favicons/apple.png'))->preservingOriginal()->toMediaCollection('artworks');
        $work->aiMetadata()->create(['status' => 'indexed', 'vision_model' => 'test-vision', 'source_media_uuid' => $media->uuid,
            'content_hash' => app(ArtworkIndexingService::class)->contentHash($work)]);
        return $work;
    }
}
