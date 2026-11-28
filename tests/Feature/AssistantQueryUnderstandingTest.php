<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\AssistantConversation;
use App\Models\User;
use App\Services\AiService\AssistantQueryStructure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssistantQueryUnderstandingTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'http://ai.test/api/v1/';

    private const QUERY = 'Две красные птицы на белом фоне.';

    private const PLAN = [
        'objects' => [['name' => 'птицы', 'count' => ['op' => 'exact', 'n' => 2, 'text' => 'Две']]],
        'conditions' => [['kind' => 'color', 'text' => 'красные', 'target' => 1], ['kind' => 'background_color', 'text' => 'белом']],
        'ambiguities' => [],
    ];

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->instance('env', 'local');
        config(['ai.enabled' => true, 'ai.assistant_enabled' => true, 'ai.assistant_rag_enabled' => true,
            'ai.assistant_history_enabled' => true, 'ai.assistant_intent_routing_enabled' => true,
            'ai.assistant_query_understanding_enabled' => true, 'ai.assistant_debug_enabled' => true,
            'ai.assistant_llm_enabled' => false, 'ai.rerank_enabled' => false,
            'ai.service_url' => 'http://ai.test', 'ai.api_key' => 'test-qu-key']);
        Storage::fake('public');
        Queue::fake();
        Http::preventStrayRequests();
        $owner = User::factory()->unverified()->create();
        $this->actingAs($owner);
        $conversation = AssistantConversation::factory()->for($owner)->create();
        $this->url = '/assistant/conversations/'.$conversation->id;
    }

    public function test_extracts_after_intent_then_passes_the_original_query_to_search_and_composer(): void
    {
        $this->fakePipeline(Http::response(self::PLAN, headers: ['X-Assistant-Model' => 'qwen3:8b',
            'X-Assistant-Model-Calls' => '1', 'X-Assistant-Prompt-Tokens' => '310']));
        $turn = $this->postJson($this->url.'/turns', ['query' => self::QUERY], ['X-Assistant-Debug' => '1'])
            ->assertCreated()->assertJsonPath('status', 'ok')->assertJsonPath('diagnostics.query.used', self::QUERY)
            ->assertJsonPath('diagnostics.query_understanding.validation', 'valid')
            ->assertJsonPath('diagnostics.query_understanding.tokens.prompt_tokens', 310)
            ->assertJsonPath('diagnostics.query_understanding.structure', self::PLAN)->json();
        $this->assertSame(['assistant/classify-intent', 'assistant/understand-query', 'search', 'assistant/compose'],
            Http::recorded()->map(fn ($record) => substr($record[0]->url(), strlen(self::API)))->all());
        foreach (Http::recorded() as [$request]) {
            $this->assertSame(self::QUERY, $request['query']);
            $this->assertArrayNotHasKey('structure', $request->data());
        }
        $export = json_decode($this->get($this->url.'/export?debug=1')->assertOk()->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(self::PLAN, $export['diagnostics'][$turn['turn_id']]['query_understanding']['structure']);
        Http::assertSentCount(4);
    }

    public function test_information_turns_and_direct_apis_skip_extraction(): void
    {
        $this->fakePipeline(Http::response(self::PLAN));
        $this->postJson($this->url.'/turns', ['query' => 'Hello.'], ['X-Assistant-Debug' => '1'])->assertCreated()
            ->assertJsonPath('status', 'informational')->assertJsonPath('diagnostics.query_understanding', null);
        $this->postJson('/assistant/search', ['query' => self::QUERY])->assertOk();
        $this->postJson('/assistant/answer', ['query' => self::QUERY])->assertOk();
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request) => $request->url() === self::API.'assistant/understand-query');
    }

    #[DataProvider('failures')]
    public function test_extraction_failure_never_cancels_correctly_routed_search(string $body, int $status, string $reason): void
    {
        $this->fakePipeline($status === 0 ? Http::failedConnection() : Http::response($body, $status));
        $this->postJson($this->url.'/turns', ['query' => self::QUERY], ['X-Assistant-Debug' => '1'])->assertCreated()
            ->assertJsonPath('status', 'ok')->assertJsonPath('diagnostics.query_understanding.structure', null)
            ->assertJsonPath('diagnostics.query_understanding.fallback_reason', $reason)->assertJsonPath('diagnostics.query.used', self::QUERY);
    }

    public static function failures(): array
    {
        return [['broken json', 200, 'invalid_result'], ['{"objects":[],"conditions":[],"ambiguities":[],"sql":"select"}', 200, 'invalid_result'],
            ['{"detail":"assistant_query_understanding_timeout"}', 504, 'timeout'], ['', 0, 'transport_failure']];
    }

    public function test_structure_cannot_copy_unredacted_pieces_of_a_sensitive_query_into_the_trace(): void
    {
        $this->fakePipeline(Http::response(['objects' => [['name' => 'abcd9876']], 'conditions' => [], 'ambiguities' => []]));
        $response = $this->postJson($this->url.'/turns', ['query' => 'Find a bird password=abcd9876.'], ['X-Assistant-Debug' => '1'])
            ->assertCreated()->assertJsonPath('diagnostics.query_understanding.redacted', true)
            ->assertJsonPath('diagnostics.query_understanding.structure', null);
        $this->assertStringNotContainsString('abcd9876', $response->getContent());
    }

    public function test_independent_validation_rejects_fabricated_exact_numbers_scopes_and_exclusions(): void
    {
        $this->assertSame(self::PLAN, AssistantQueryStructure::decode(json_encode(self::PLAN), self::QUERY));
        $count = ['objects' => [['name' => 'birds', 'count' => ['op' => 'exact', 'text' => 'two', 'n' => 2]]], 'conditions' => [], 'ambiguities' => []];
        $this->assertNull(AssistantQueryStructure::decode(json_encode($count), 'At least two birds'));
        $this->assertNull(AssistantQueryStructure::decode(json_encode($count), 'Birds.'));
        $invalid = self::PLAN;
        $invalid['conditions'][0]['target'] = 2;
        $this->assertNull(AssistantQueryStructure::decode(json_encode($invalid), self::QUERY));
        $invalid = ['objects' => [], 'conditions' => [['kind' => 'presence', 'text' => 'birds', 'mode' => 'excluded']], 'ambiguities' => []];
        $this->assertNull(AssistantQueryStructure::decode(json_encode($invalid), 'Birds.'));
        $this->assertNull(AssistantQueryStructure::decode('{"objects":{},"conditions":[],"ambiguities":[]}', self::QUERY));
    }

    private function fakePipeline(mixed $outcome): void
    {
        $work = Artwork::factory()->create(['title' => 'Birds in a park']);
        $work->addMedia(base_path('public/images/favicons/apple.png'))->preservingOriginal()->toMediaCollection('artworks');
        Http::fake([
            self::API.'assistant/classify-intent' => Http::response(['intent' => 'search_media']),
            self::API.'assistant/understand-query' => $outcome,
            self::API.'search' => Http::response(['results' => [['artwork_id' => $work->id, 'score' => 0.8, 'rank' => 1]]]),
            self::API.'assistant/compose' => Http::response(['action' => 'compose', 'selections' => [['source_ref' => 's1', 'fact_ref' => 'f1']]]),
        ]);
    }
}
