<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\AssistantConversation;
use App\Models\AssistantTurn;
use App\Models\User;
use App\Services\AiService\AssistantRagContext;
use App\Services\Search\AssistantConversationHistory;
use App\Services\Search\AssistantSavedSources;
use App\Services\Search\HybridSearchService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssistantTurnTest extends TestCase
{
    use RefreshDatabase;

    private const FIRST = 'Найди изображения птиц у воды';

    private const FOLLOWUP = 'А какие из них относятся к XIX веку?';

    private const COMPOSE = 'http://ai.test/api/v1/assistant/compose';

    private const RESOLVE = 'http://ai.test/api/v1/assistant/resolve-followup';

    private User $owner;

    private AssistantConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.assistant_enabled' => true, 'ai.assistant_rag_enabled' => true,
            'ai.assistant_history_enabled' => true, 'ai.assistant_context_enabled' => true,
            'ai.assistant_llm_enabled' => false, 'ai.rerank_enabled' => false,
            'ai.service_url' => 'http://ai.test', 'ai.api_key' => 'test-secret',
        ]);
        $this->owner = User::factory()->unverified()->create();
        $this->conversation = AssistantConversation::factory()->for($this->owner)->create();
        Storage::fake('public');
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        try {
            Queue::assertNothingPushed();
        } finally {
            parent::tearDown();
        }
    }

    public function test_first_turn_persists_only_checked_citations_and_reconstructs_them_on_read(): void
    {
        $work = $this->image('Chidori Birds', 'Artist: Example. Date: 1851.');
        $other = $this->image('An uncited work');
        $this->search(self::FIRST, [$work, $other]);
        $level = DB::transactionLevel();
        Http::fake([self::COMPOSE => function () use ($level) {
            $this->assertSame($level, DB::transactionLevel());

            return Http::response($this->plan('s1', 'f2'));
        }]);

        $response = $this->actingAs($this->owner)->postJson($this->url(), ['query' => self::FIRST, 'limit' => 5])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('status', 'ok')->assertJsonPath('sequence', 1)
            ->assertJsonCount(1, 'sources')->assertJsonPath('sources.0.url', '/artworks/'.$work->id);
        $turn = AssistantTurn::findOrFail($response->json('turn_id'));
        $this->assertSame([$this->reference($work, 'f2')], $turn->source_references);
        $this->assertSame(5, $turn->requested_limit);
        $ciphertext = DB::table('assistant_turns')->where('id', $turn->id)->value('user_query');
        $this->assertStringNotContainsString(self::FIRST, $ciphertext);
        $this->assertSame(self::FIRST, Crypt::decryptString($ciphertext));

        $this->getJson($this->url(false))->assertOk()
            ->assertJsonPath('turns.0.result_status', 'ok')->assertJsonPath('turns.0.source_status', 'available')
            ->assertJsonPath('turns.0.sources', $response->json('sources'))
            ->assertJsonPath('turns.0.answer', $response->json('answer'))
            ->assertJsonMissingPath('turns.0.source_references');
        Http::assertSentCount(1);
    }

    public function test_followup_only_uses_previously_cited_date_facts_and_does_not_search_the_catalog(): void
    {
        $current = $this->image('Birds', 'Date: 1851.');
        $old = $this->image('Older birds', 'Date: 1795.');
        $unknown = $this->image('Nineteenth century by title', 'Made of ink.');
        $this->seedTurn([$this->reference($current, 'f2'), $this->reference($old, 'f2'), $this->reference($unknown, 'f1')]);
        $this->neverSearch();
        Http::fake([
            self::RESOLVE => function ($request) {
                $this->assertTrue($request->hasHeader('X-AI-Service-Key', 'test-secret'));
                $this->assertSame(['query', 'previous_queries', 'facts'], array_keys($request->data()));
                $this->assertSame([self::FIRST], $request['previous_queries']);

                return Http::response(['action' => 'use_previous', 'source_refs' => ['s3', 's2', 's1']]);
            },
            self::COMPOSE => function ($request) {
                $this->assertSame(self::FOLLOWUP, $request['query']);
                $this->assertSame([['source_ref' => 's1', 'fact_ref' => 'f2', 'value' => 'Date: 1851.', 'origin' => 'user_metadata']], $request['facts']);

                return Http::response($this->plan('s1', 'f2'));
            },
        ]);

        $this->actingAs($this->owner)->postJson($this->url(), ['query' => self::FOLLOWUP])
            ->assertCreated()->assertJsonPath('status', 'ok')->assertJsonPath('sequence', 2)
            ->assertJsonCount(1, 'sources')->assertJsonPath('sources.0.artwork_id', $current->id);
        $this->assertSame([$this->reference($current, 'f2')], $this->conversation->turns()->where('sequence', 2)->firstOrFail()->source_references);
        Http::assertSentCount(2);
    }

    public function test_explicitly_independent_second_query_uses_the_unchanged_hybrid_search_once(): void
    {
        $previous = $this->image('A bird');
        $next = $this->image('A landscape');
        $this->seedTurn([$this->reference($previous, 'f1')]);
        $query = 'Покажи горные пейзажи';
        $this->search($query, [$next]);
        Http::fake([
            self::RESOLVE => Http::response(['action' => 'new_search', 'source_refs' => []]),
            self::COMPOSE => Http::response($this->plan('s1', 'f1')),
        ]);

        $this->actingAs($this->owner)->postJson($this->url(), ['query' => $query])
            ->assertCreated()->assertJsonPath('sources.0.artwork_id', $next->id);
        Http::assertSentCount(2);
    }

    public function test_dates_cannot_be_inferred_from_titles_or_an_ambiguous_period(): void
    {
        $work = $this->image('Birds of the XIX century', 'Date: 18th–19th century.');
        $this->seedTurn([$this->reference($work, 'f1'), $this->reference($work, 'f2')]);
        $this->neverSearch();
        Http::fake([self::RESOLVE => Http::response(['action' => 'use_previous', 'source_refs' => ['s1']])]);

        $this->actingAs($this->owner)->postJson($this->url(), ['query' => self::FOLLOWUP])->assertCreated()
            ->assertJsonPath('status', 'insufficient_evidence')->assertJsonPath('answer', []);
        $this->assertSame([], $this->conversation->turns()->where('sequence', 2)->firstOrFail()->source_references);
        Http::assertSentCount(1);
    }

    public function test_fallback_is_stored_without_any_citations_or_historical_cards(): void
    {
        $work = $this->image('A public bird');
        $this->search(self::FIRST, [$work]);
        Http::fake([self::COMPOSE => Http::failedConnection()]);

        $this->actingAs($this->owner)->postJson($this->url(), ['query' => self::FIRST])
            ->assertCreated()->assertJsonPath('status', 'search_fallback')->assertJsonPath('sources.0.artwork_id', $work->id)
            ->assertJsonPath('answer', []);
        $this->assertSame([], $this->conversation->turns()->firstOrFail()->source_references);
        $this->getJson($this->url(false))->assertOk()->assertJsonPath('turns.0.source_status', 'none')
            ->assertJsonPath('turns.0.result_status', 'search_fallback')->assertJsonPath('turns.0.sources', []);
    }

    public function test_dependent_request_without_citations_does_not_fall_through_to_new_search(): void
    {
        $this->seedTurn([]);
        $this->neverSearch();
        Http::fake([self::RESOLVE => function ($request) {
            $this->assertSame([self::FIRST], $request['previous_queries']);
            $this->assertSame([], $request['facts']);

            return Http::response(['action' => 'new_search', 'source_refs' => []]);
        }]);

        $this->actingAs($this->owner)->postJson($this->url(), ['query' => self::FOLLOWUP])
            ->assertCreated()->assertJsonPath('status', 'clarification_required')->assertJsonPath('sources', []);
        $this->assertSame([], $this->conversation->turns()->where('sequence', 2)->firstOrFail()->source_references);
        Http::assertSentCount(1);
    }

    #[DataProvider('unusableResolution')]
    public function test_dependent_request_fails_closed_on_unknown_refs_or_resolver_failure(string $scenario): void
    {
        $work = $this->image('A bird');
        $this->seedTurn([$this->reference($work, 'f1')]);
        $this->neverSearch();
        Log::spy();

        $reply = $scenario === 'timeout'
            ? Http::failedConnection()
            : Http::response(['action' => 'use_previous', 'source_refs' => ['s5'], ...($scenario === 'extra' ? ['url' => 'https://internal.test'] : [])]);
        Http::fake([self::RESOLVE => $reply]);
        $this->actingAs($this->owner)->postJson($this->url(), ['query' => self::FOLLOWUP])
            ->assertCreated()->assertJsonPath('status', 'clarification_required')->assertJsonPath('answer', []);
        if ($scenario === 'timeout') {
            Log::shouldHaveReceived('warning')->once()->with('Assistant follow-up resolution unavailable.', [
                'exception_type' => \Illuminate\Http\Client\ConnectionException::class,
            ]);
        }
        Http::assertSentCount(1);
    }

    public static function unusableResolution(): array
    {
        return [['unknown'], ['extra'], ['timeout']];
    }

    public function test_invalid_resolver_json_cannot_start_even_an_explicit_catalog_search(): void
    {
        $this->seedTurn([]);
        $this->neverSearch();
        Http::fake([self::RESOLVE => Http::response('not JSON')]);

        $this->actingAs($this->owner)->postJson($this->url(), ['query' => 'Покажи горные пейзажи'])
            ->assertCreated()->assertJsonPath('status', 'clarification_required');
        Http::assertSentCount(1);
    }

    public function test_timeout_allows_an_explicitly_new_request_without_using_previous_sources(): void
    {
        $this->seedTurn([]);
        $query = 'Find images of mountain ridges';
        $this->search($query, []);
        Http::fake([self::RESOLVE => Http::response([], 504)]);

        $this->actingAs($this->owner)->postJson($this->url(), ['query' => $query])
            ->assertCreated()->assertJsonPath('status', 'no_results');
        Http::assertSentCount(1);
    }

    public function test_context_disabled_or_missing_does_not_expand_a_dependent_request(): void
    {
        $this->neverSearch();
        Http::fake();
        $this->actingAs($this->owner)->postJson($this->url(), ['query' => self::FOLLOWUP])
            ->assertCreated()->assertJsonPath('status', 'clarification_required');
        config(['ai.assistant_context_enabled' => false]);
        $this->postJson($this->url(), ['query' => 'Покажи эту картину'])
            ->assertCreated()->assertJsonPath('status', 'clarification_required');
        $this->getJson($this->url(false))->assertOk()->assertJsonCount(2, 'turns');
        Http::assertNothingSent();
    }

    public function test_context_budget_prefers_latest_checked_facts_and_limits_previous_messages(): void
    {
        $works = collect(range(1, 7))->map(fn ($i) => $this->image('Work '.$i, str_repeat('я', 70).$i));
        foreach ($works as $index => $work) {
            $this->seedTurn([$this->reference($work, 'f2')], $index + 1, 'Historical query '.$index);
        }
        $this->neverSearch();
        Http::fake([self::RESOLVE => function ($request) {
            $data = $request->data();
            $this->assertLessThanOrEqual(1400, strlen(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG)));
            $this->assertLessThanOrEqual(4, count($data['previous_queries']));
            $this->assertLessThanOrEqual(5, count(array_unique(array_column($data['facts'], 'source_ref'))));
            $this->assertStringEndsWith('7', $data['facts'][0]['value']);
            $this->assertSame('Historical query 6', $data['previous_queries'][0]);

            return Http::response(['action' => 'clarify', 'source_refs' => []]);
        }]);

        $this->actingAs($this->owner)->postJson($this->url(), ['query' => self::FOLLOWUP])
            ->assertCreated()->assertJsonPath('status', 'clarification_required');
    }

    #[DataProvider('revocations')]
    public function test_revoked_or_changed_source_is_omitted_from_history_and_resolver(string $change): void
    {
        $work = $this->image('Old public title', 'Old public description');
        $other = $this->image('Current public title');
        $this->seedTurn([$this->reference($work, 'f2'), $this->reference($other, 'f1')]);
        match ($change) {
            'private' => DB::table('artworks')->where('id', $work->id)->update(['is_private' => true]),
            'blocked author' => DB::table('users')->where('id', $work->user_id)->update(['is_blocked' => true]),
            'revision' => DB::table('artworks')->where('id', $work->id)->update(['title' => 'New version']),
            'deleted' => DB::table('artworks')->where('id', $work->id)->delete(),
        };
        $this->neverSearch();
        Http::fake([self::RESOLVE => function ($request) {
            $this->assertSame(['Current public title'], array_column($request['facts'], 'value'));

            return Http::response(['action' => 'clarify', 'source_refs' => []]);
        }]);

        $this->actingAs($this->owner)->getJson($this->url(false))->assertOk()
            ->assertJsonPath('turns.0.result_status', 'ok')->assertJsonPath('turns.0.source_status', 'partial')
            ->assertJsonCount(1, 'turns.0.sources')->assertJsonPath('turns.0.sources.0.artwork_id', $other->id)
            ->assertDontSee('Old public description')->assertDontSee('Old public title');
        Http::assertNothingSent();
        $this->postJson($this->url(), ['query' => self::FOLLOWUP])->assertCreated();
        Http::assertSentCount(1);
    }

    public static function revocations(): array
    {
        return [['private'], ['blocked author'], ['revision'], ['deleted']];
    }

    public function test_changed_fact_without_dense_revision_change_is_not_reconstructed(): void
    {
        $work = $this->image('A bird');
        $work->provenance()->create([
            'source' => 'met', 'external_id' => 'bird', 'source_url' => 'https://example.test/bird',
            'image_url' => 'https://example.test/image', 'license' => 'CC0', 'image_sha256' => str_repeat('0', 64),
            'creator_name' => 'Old artist', 'metadata' => [],
        ]);
        $saved = $this->reference($work, 'f7');
        $this->seedTurn([$saved]);
        $work->provenance()->update(['creator_name' => 'New artist']);
        $new = $this->reference($work, 'f7');
        $this->assertSame($saved['revision_hash'], $new['revision_hash']);
        $this->assertNotSame($saved['fact_sha256'], $new['fact_sha256']);

        Http::fake();
        $this->actingAs($this->owner)->getJson($this->url(false))->assertOk()
            ->assertJsonPath('turns.0.source_status', 'unavailable')->assertJsonPath('turns.0.sources', [])
            ->assertDontSee('Old artist')->assertDontSee('New artist');
        Http::assertNothingSent();
    }

    public function test_access_revoked_during_model_generation_is_not_stored_as_a_confirmed_answer(): void
    {
        $work = $this->image('A bird');
        $this->search(self::FIRST, [$work]);
        Http::fake([self::COMPOSE => function () use ($work) {
            DB::table('artworks')->where('id', $work->id)->update(['is_private' => true]);

            return Http::response($this->plan('s1', 'f1'));
        }]);

        $this->actingAs($this->owner)->postJson($this->url(), ['query' => self::FIRST])->assertCreated()
            ->assertJsonPath('status', 'no_results')->assertJsonPath('sources', []);
        $this->assertSame([], $this->conversation->turns()->firstOrFail()->source_references);
    }

    public function test_final_transaction_rechecks_sources_after_rag_and_before_any_insert(): void
    {
        $work = $this->image('A bird');
        $this->search(self::FIRST, [$work]);
        $verifier = app(AssistantSavedSources::class);
        $this->partialMock(AssistantSavedSources::class)->shouldReceive('confirm')->once()
            ->andReturnUsing(function ($result, $owner) use ($work, $verifier) {
                DB::table('artworks')->where('id', $work->id)->update(['is_private' => true]);
                $verifier->confirm($result, $owner);
            });
        Http::fake([self::COMPOSE => Http::response($this->plan('s1', 'f1'))]);

        $this->actingAs($this->owner)->postJson($this->url(), ['query' => self::FIRST])->assertConflict()
            ->assertJsonMissingPath('sources');
        $this->assertDatabaseCount('assistant_turns', 0);
    }

    #[DataProvider('conversationRace')]
    public function test_deleted_reassigned_or_advanced_conversation_cannot_commit_old_context(string $change, int $status): void
    {
        $work = $this->image('A bird');
        $this->search(self::FIRST, [$work]);
        Http::fake([self::COMPOSE => function () use ($change) {
            match ($change) {
                'delete' => $this->conversation->delete(),
                'other owner' => DB::table('assistant_conversations')->where('id', $this->conversation->id)
                    ->update(['user_id' => User::factory()->create()->id]),
                'concurrent turn' => app(AssistantConversationHistory::class)
                    ->appendTurn($this->owner, $this->conversation->id, 'Winning turn', 'no_results', 10, [], expectedSequence: 0),
            };

            return Http::response($this->plan('s1', 'f1'));
        }]);

        $this->actingAs($this->owner)->postJson($this->url(), ['query' => self::FIRST])->assertStatus($status)
            ->assertJsonMissingPath('answer');
        $this->assertDatabaseCount('assistant_turns', $change === 'concurrent turn' ? 1 : 0);
        if ($change === 'concurrent turn') {
            $this->assertSame('Winning turn', $this->conversation->turns()->where('sequence', 1)->firstOrFail()->user_query);
        }
    }

    public static function conversationRace(): array
    {
        return [['delete', 404], ['other owner', 404], ['concurrent turn', 409]];
    }

    public function test_sequential_retries_are_distinct_completed_turns_without_idempotency_keys(): void
    {
        config(['ai.assistant_context_enabled' => false]);
        Http::fake();
        $this->mock(HybridSearchService::class)->shouldReceive('search')->twice()->with(self::FIRST, ['type' => 'image'], 1, 20)
            ->andReturn(['artworks' => collect(), 'total' => 0]);

        $this->actingAs($this->owner);
        $first = $this->postJson($this->url(), ['query' => self::FIRST])->assertCreated()->assertJsonPath('sequence', 1);
        $second = $this->postJson($this->url(), ['query' => self::FIRST])->assertCreated()->assertJsonPath('sequence', 2);
        $this->assertNotSame($first->json('turn_id'), $second->json('turn_id'));
        $this->assertDatabaseCount('assistant_turns', 2);
        Http::assertNothingSent();
    }

    public function test_source_lookup_error_is_sanitized_and_does_not_persist_a_turn(): void
    {
        $work = $this->image('A bird');
        $this->search(self::FIRST, [$work]);
        $this->partialMock(AssistantSavedSources::class)->shouldReceive('confirm')->once()
            ->andThrow(new \RuntimeException('private diagnostic and '.self::FIRST));
        Http::fake([self::COMPOSE => Http::response($this->plan('s1', 'f1'))]);
        Log::spy();
        $this->actingAs($this->owner)->postJson($this->url(), ['query' => self::FIRST])->assertServiceUnavailable()
            ->assertExactJson(['message' => 'Assistant turn could not be completed safely.']);
        Log::shouldHaveReceived('warning')->once()->with('Assistant turn could not be completed.', ['exception_type' => \RuntimeException::class]);
        $this->assertDatabaseCount('assistant_turns', 0);
    }

    public function test_owner_lookup_error_is_sanitized_before_processing(): void
    {
        Http::fake();
        $this->neverSearch();
        $this->partialMock(AssistantConversationHistory::class)->shouldReceive('findOwned')->once()
            ->andThrow(new QueryException('sqlite', 'select secret_payload', [], new \RuntimeException('private diagnostic')));

        $this->actingAs($this->owner)->postJson($this->url(), ['query' => self::FIRST])
            ->assertServiceUnavailable()->assertJsonPath('message', 'Assistant turn could not be completed safely.')
            ->assertDontSee('secret_payload')->assertDontSee('private diagnostic');
        $this->assertDatabaseCount('assistant_turns', 0);
        Http::assertNothingSent();
    }

    public function test_ownership_turn_limits_and_client_reference_injection_are_checked_before_processing(): void
    {
        $this->neverSearch();
        Http::fake();
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Admin', 'web'));
        $this->actingAs($admin)->postJson($this->url(), ['query' => self::FIRST])->assertNotFound();
        $this->getJson($this->url(false))->assertNotFound();
        $this->actingAs($this->owner)->postJson($this->url(), ['query' => self::FIRST, 'source_references' => [$this->reference($this->image('An injected work'), 'f1')]])
            ->assertUnprocessable();
        config(['ai.assistant_history.max_turns' => 1]);
        $this->seedTurn([]);
        $this->postJson($this->url(), ['query' => self::FOLLOWUP])->assertConflict();
        Http::assertNothingSent();
    }

    public function test_turn_quota_is_shared_with_search_but_does_not_block_reads_or_deletion(): void
    {
        Http::fake();
        $this->mock(HybridSearchService::class)->shouldReceive('search')->times(10)->with(self::FIRST, ['type' => 'image'], 1, 20)
            ->andReturn(['artworks' => collect(), 'total' => 0]);
        $this->actingAs($this->owner);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/assistant/search', ['query' => self::FIRST])->assertOk();
        }
        $this->postJson($this->url(), ['query' => self::FIRST])->assertStatus(429);
        $this->getJson($this->url(false))->assertOk();
        $this->deleteJson($this->url(false))->assertNoContent();
        Http::assertNothingSent();
    }

    public function test_turn_writes_require_both_flags_and_web_csrf(): void
    {
        Http::fake();
        $this->neverSearch();
        $this->postJson($this->url(), ['query' => self::FIRST])->assertUnauthorized();
        $this->withSession([auth()->guard('web')->getName() => $this->owner->id, '_token' => 'turn-csrf']);
        $this->app->instance('env', 'local');
        try {
            $this->postJson($this->url(), ['query' => self::FIRST])->assertStatus(419);
            foreach (['ai.assistant_rag_enabled', 'ai.assistant_history_enabled'] as $flag) {
                config([$flag => false]);
                $this->postJson($this->url(), ['query' => self::FIRST], ['X-CSRF-TOKEN' => 'turn-csrf'])->assertNotFound();
                config([$flag => true]);
            }
            $this->postJson($this->url(), ['query' => self::FOLLOWUP], ['X-CSRF-TOKEN' => 'turn-csrf'])->assertCreated();
        } finally {
            $this->app->instance('env', 'testing');
        }
        Http::assertNothingSent();
    }

    public function test_history_verifies_multiple_turns_in_one_batch_without_llm(): void
    {
        $refs = [];
        foreach (range(1, 5) as $id) {
            $refs[] = $this->reference($this->image('Work '.$id), 'f1');
            $this->seedTurn([$refs[$id - 1]], $id);
        }
        Http::fake();
        $this->neverSearch();
        DB::enableQueryLog();
        try {
            $response = $this->actingAs($this->owner)->getJson($this->url(false).'?per_page=3');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $response->assertOk()->assertJsonCount(3, 'turns')->assertJsonPath('turns.0.sequence', 5)
            ->assertJsonPath('turns.0.source_status', 'available')->assertJsonPath('pagination.has_more', true);
        $this->assertCount(1, array_filter($queries, fn ($row) => str_contains($row['query'], 'from "artworks"')));
        $this->assertCount(1, array_filter($queries, fn ($row) => str_contains($row['query'], 'from "tags"')));
        Http::assertNothingSent();
    }

    private function seedTurn(array $references, int $sequence = 1, string $query = self::FIRST): void
    {
        AssistantTurn::factory()->for($this->conversation, 'conversation')->create([
            'sequence' => $sequence, 'user_query' => $query, 'result_status' => $references === [] ? 'no_results' : 'ok',
            'source_references' => $references,
        ]);
    }

    private function reference(Artwork $work, string $fact): array
    {
        $work = $work->fresh();
        return AssistantSavedSources::reference($work, app(AssistantRagContext::class)->snapshot($work), $fact);
    }

    private function image(string $title, ?string $description = null): Artwork
    {
        $work = Artwork::factory()->create(['title' => $title, 'description' => $description]);
        $work->addMedia(base_path('public/images/favicons/apple.png'))->preservingOriginal()->toMediaCollection('artworks');

        return $work;
    }

    private function search(string $query, array $matches): void
    {
        $this->mock(HybridSearchService::class)->shouldReceive('search')->once()->with($query, ['type' => 'image'], 1, 20)
            ->andReturn(['artworks' => collect($matches), 'total' => count($matches)]);
    }

    private function neverSearch(): void
    {
        $this->mock(HybridSearchService::class)->shouldReceive('search')->never();
    }

    private function plan(string $source, string $fact): array
    {
        return ['action' => 'compose', 'selections' => [['source_ref' => $source, 'fact_ref' => $fact]]];
    }

    private function url(bool $write = true): string
    {
        return '/assistant/conversations/'.$this->conversation->id.($write ? '/turns' : '');
    }
}
