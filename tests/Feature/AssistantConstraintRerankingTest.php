<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\AssistantConversation;
use App\Models\User;
use App\Services\AiService\ArtworkIndexingService;
use App\Services\Search\AssistantArtworkSearch;
use App\Services\Search\AssistantConstraintReranker;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class AssistantConstraintRerankingTest extends TestCase
{
    use RefreshDatabase;

    private const UNKNOWN = 'insufficient_evidence';

    private const QUERY = 'Find a red bird on a blue background';

    private const PLAN = [
        'objects' => [['name' => 'bird']],
        'conditions' => [['kind' => 'color', 'text' => 'red', 'target' => 1], ['kind' => 'background_color', 'text' => 'blue']],
        'ambiguities' => [],
    ];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->instance('env', 'local');
        config(['ai.enabled' => true, 'ai.assistant_enabled' => true, 'ai.assistant_rag_enabled' => true,
            'ai.assistant_history_enabled' => true, 'ai.assistant_intent_routing_enabled' => true,
            'ai.assistant_query_understanding_enabled' => true, 'ai.assistant_debug_enabled' => true,
            'ai.assistant_llm_enabled' => false, 'ai.rerank_enabled' => false,
            'ai.assistant_constraint_reranking_enabled' => true, 'ai.assistant_lexical_metadata_enabled' => false,
            'ai.auto_analyze_on_publish' => false, 'ai.auto_reindex_on_update' => false,
            'ai.service_url' => 'http://ai.test', 'ai.api_key' => 'constraint-test']);
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('public');
        $this->owner = User::factory()->unverified()->create();
        $this->actingAs($this->owner);
    }

    public function test_distinguishes_object_colour_background_and_palette_and_abstains_on_conflicting_evidence(): void
    {
        $inverted = $this->image('A blue bird against a red background.');
        $confirmed = $this->image('A red bird on a blue background.');
        $palette = $this->image('A bird looks ahead.', ['colors' => ['red', 'blue']]);
        $conflicting = $this->image('A red bird against a blue background. The background is white.');
        $ids = [$inverted->id, $confirmed->id, $palette->id, $conflicting->id];
        $run = $this->runRanking(self::QUERY, self::PLAN, $ids);
        $states = fn ($work) => array_column($run['ranks'][$work->id]['evidence'], 'state', null);
        $this->assertSame(['confirmed', 'contradicted', 'contradicted'], $states($inverted));
        $this->assertSame(['confirmed', 'confirmed', 'confirmed'], $states($confirmed));
        $this->assertSame(['confirmed', self::UNKNOWN, self::UNKNOWN], $states($palette));
        $this->assertSame('palette_without_binding', $run['ranks'][$palette->id]['evidence']['condition:2']['reason']);
        $this->assertSame('conflicting_metadata', $run['ranks'][$conflicting->id]['evidence']['condition:2']['reason']);
        $this->assertSame(['field' => 'summary', 'fragment' => 'A red bird on a blue background'],
            $run['ranks'][$confirmed->id]['evidence']['condition:2']['evidence'][0]);
        $this->assertEqualsCanonicalizing($ids, $run['ids']);
        $this->assertSame($confirmed->id, $run['ids'][0]);
    }

    public function test_quantity_uses_explicit_witnesses_and_preserves_uncertain_counts(): void
    {
        $exact = $this->image('Two birds are in the photograph.');
        $three = $this->image('Three birds sit together.');
        $approx = $this->image('Approximately two birds appear.');
        $vague = $this->image('Several birds are perched together.');
        $plural = $this->image('Birds are perched together.', ['objects' => ['birds']]);
        $ids = [$exact->id, $three->id, $approx->id, $vague->id, $plural->id];
        foreach ([
            ['two', 'exact', 2, ['confirmed', 'contradicted', self::UNKNOWN, self::UNKNOWN, self::UNKNOWN]],
            ['at least two', 'at_least', 2, ['confirmed', 'confirmed', self::UNKNOWN, self::UNKNOWN, self::UNKNOWN]],
            ['several', 'vague', null, [self::UNKNOWN, self::UNKNOWN, self::UNKNOWN, 'confirmed', self::UNKNOWN]],
        ] as [$span, $op, $n, $expected]) {
            $count = ['op' => $op, 'text' => $span, ...($n === null ? [] : ['n' => $n])];
            $run = $this->runRanking('Show '.$span.' birds', ['objects' => [['name' => 'birds', 'count' => $count]], 'conditions' => [], 'ambiguities' => []], $ids);
            $this->assertSame($expected, array_map(fn ($id) => $run['ranks'][$id]['evidence']['count:1']['state'], $ids));
        }
    }

    public function test_explicit_negation_and_spatial_binding_are_distinguished_from_silence_and_lists(): void
    {
        $right = $this->image('A bridge above the stream. No people are visible.');
        $wrong = $this->image('A bridge below the stream. A person stands nearby.');
        $list = $this->image('A bridge and a stream appear.', ['objects' => ['bridge', 'stream']]);
        $contradictory = $this->image('No people are visible.', ['objects' => ['person']]);
        $limited = $this->image('No people near the bridge.');
        $query = 'A bridge above a stream without people';
        $plan = ['objects' => [['name' => 'bridge'], ['name' => 'stream', 'role' => 'additional']], 'conditions' => [
            ['kind' => 'relation', 'text' => 'above', 'target' => 1, 'other' => 2],
            ['kind' => 'presence', 'text' => 'people', 'mode' => 'excluded', 'cue' => 'without'],
        ], 'ambiguities' => []];
        $ids = [$right->id, $wrong->id, $list->id, $contradictory->id, $limited->id];
        $run = $this->runRanking($query, $plan, $ids);
        foreach (['condition:1', 'condition:2'] as $key) {
            $this->assertSame(['confirmed', 'contradicted', self::UNKNOWN, self::UNKNOWN, self::UNKNOWN],
                array_map(fn ($id) => $run['ranks'][$id]['evidence'][$key]['state'], $ids));
        }
        $this->assertSame('conflicting_metadata', $run['ranks'][$contradictory->id]['evidence']['condition:2']['reason']);
        $bird = $this->image('No red birds are visible.');
        $scopedColour = $this->runRanking(self::QUERY, self::PLAN, [$bird->id]);
        $this->assertSame(self::UNKNOWN, $scopedColour['ranks'][$bird->id]['evidence']['object:1']['state']);
    }

    public function test_missing_invalid_and_empty_structures_and_failures_leave_input_unchanged(): void
    {
        $ids = [$this->image('The grass grows.')->id, $this->image('A rock stands.')->id];
        $wrongBinding = self::PLAN;
        $wrongBinding['conditions'][0]['target'] = 2;
        $outcomes = [
            [null, 'no_structure'], [$wrongBinding, 'invalid_structure'],
            [['objects' => [], 'conditions' => [], 'ambiguities' => []], 'no_applicable_conditions'],
        ];
        foreach ($outcomes as [$plan, $reason]) {
            $run = $this->runRanking(self::QUERY, $plan, $ids);
            $this->assertSame($reason, $run['fallback_reason']);
            $this->assertSame($ids, $run['ids']);
        }
        $unknown = $this->runRanking(self::QUERY, self::PLAN, $ids);
        $this->assertSame($ids, $unknown['ids']);
        $this->assertSame([0.0, 0.0], array_column($unknown['ranks'], 'delta'));
        $error = app(AssistantConstraintReranker::class)->rerank(self::QUERY, self::PLAN, $ids,
            fn () => throw new RuntimeException('local test'), 20, 0.5, 'title_like');
        $this->assertSame('evaluation_unavailable', $error['fallback_reason']);
        $this->assertSame($ids, $error['ids']);
    }

    public function test_loads_only_allowed_media_and_marks_outdated_vision_as_unknown(): void
    {
        $summary = 'A red bird on a blue background.';
        $visible = $this->image($summary);
        $stale = $this->image($summary, ['content_hash' => str_repeat('0', 64)]);
        $private = $this->image($summary, attributes: ['is_private' => true]);
        $disabled = $this->image($summary, attributes: ['ai_indexing_enabled' => false]);
        $missing = $this->image($summary);
        $denied = $this->image($summary);
        $deleted = $this->image($summary);
        $deleted->delete();
        $media = $missing->getFirstMedia('artworks');
        Storage::disk($media->disk)->delete($media->getPathRelativeToRoot());
        Gate::before(fn ($user, $ability, $args) => $ability === 'view' && ($args[0]->id ?? null) === $denied->id ? false : null);
        $ids = [$visible->id, $stale->id, $private->id, $disabled->id, $missing->id, $denied->id, $deleted->id];
        $run = $this->runRanking(self::QUERY, self::PLAN, $ids);
        $this->assertSame([$visible->id, $stale->id], array_keys($run['ranks']));
        $this->assertSame('stale_or_missing_vision', $run['ranks'][$stale->id]['evidence']['condition:1']['reason']);
        $this->assertSame($ids, $run['ids']);
    }

    public function test_turn_uses_the_plan_once_before_rag_with_or_without_debug_and_flag_off_preserves_order(): void
    {
        $wrong = $this->image('A blue bird on a red background.');
        $right = $this->image('A red bird on a blue background.');
        Http::fake([
            'ai.test/api/v1/assistant/classify-intent' => Http::response(['intent' => 'search_media']),
            'ai.test/api/v1/assistant/understand-query' => Http::response(self::PLAN),
            'ai.test/api/v1/search' => Http::response(['results' => [
                ['artwork_id' => $wrong->id, 'score' => 0.9, 'rank' => 1], ['artwork_id' => $right->id, 'score' => 0.8, 'rank' => 2],
            ]]),
            'ai.test/api/v1/assistant/compose' => Http::response(['action' => 'compose', 'selections' => [['source_ref' => 's1', 'fact_ref' => 'f1']]]),
        ]);
        $path = '/assistant/conversations/'.AssistantConversation::factory()->for($this->owner)->create()->id;
        $this->postJson($path.'/turns', ['query' => self::QUERY])->assertCreated()
            ->assertJsonPath('sources.0.artwork_id', $right->id)->assertJsonMissingPath('diagnostics');
        $turn = $this->postJson($path.'/turns', ['query' => self::QUERY], ['X-Assistant-Debug' => '1'])->assertCreated()
            ->assertJsonPath('sources.0.artwork_id', $right->id)->assertJsonPath('diagnostics.stages.constraints.structure', self::PLAN)->json();
        $export = json_decode($this->get($path.'/export?debug=1')->assertOk()->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($right->id, $export['rag'][$turn['turn_id']]['available'][0]['artwork_id']);
        config(['ai.assistant_constraint_reranking_enabled' => false]);
        $this->postJson($path.'/turns', ['query' => self::QUERY], ['X-Assistant-Debug' => '1'])->assertCreated()
            ->assertJsonPath('sources.0.artwork_id', $wrong->id)->assertJsonPath('diagnostics.stages.constraints', null);
        $requests = Http::recorded(fn ($request) => str_ends_with($request->url(), '/understand-query'));
        $this->assertCount(3, $requests);
        foreach ($requests as [$request]) {
            $this->assertSame(self::QUERY, $request['query']);
        }
    }

    private function runRanking(string $query, ?array $plan, array $ids): array
    {
        $search = app(AssistantArtworkSearch::class);

        return app(AssistantConstraintReranker::class)->rerank($query, $plan, $ids,
            fn (Collection $ids) => $search->visible($ids, $this->owner, withMetadata: true), 20, 0.5, 'title_like');
    }

    private function image(string $summary, array $meta = [], array $attributes = []): Artwork
    {
        $image = Artwork::factory()->create(['title' => 'Study item', 'description' => '', ...$attributes]);
        $media = $image->addMedia(base_path('public/images/favicons/apple.png'))->preservingOriginal()->toMediaCollection('artworks');
        $image->aiMetadata()->create($meta + ['summary' => $summary, 'status' => 'indexed', 'vision_model' => 'fixture-vision',
            'source_media_uuid' => $media->uuid, 'content_hash' => app(ArtworkIndexingService::class)->contentHash($image)]);

        return $image;
    }
}
