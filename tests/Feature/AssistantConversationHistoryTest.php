<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\AssistantConversation;
use App\Models\AssistantTurn;
use App\Models\User;
use App\Services\Search\AssistantConversationHistory;
use App\Services\Search\HybridSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AssistantConversationHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/assistant/conversations';

    private const QUERY = 'Найди изображения птиц у воды';

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.assistant_enabled' => true, 'ai.assistant_history_enabled' => true]);
        Http::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            Queue::assertNothingPushed();
        } finally {
            parent::tearDown();
        }
    }

    public function test_verified_owner_creates_an_empty_conversation_with_a_uuid(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $response = $this->actingAs($owner)->postJson(self::URL, ['user_id' => $other->id])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJsonStructure(['conversation' => ['id', 'created_at', 'updated_at']]);

        $id = $response->json('conversation.id');
        $this->assertTrue(Str::isUuid($id));
        $this->assertDatabaseHas('assistant_conversations', ['id' => $id, 'user_id' => $owner->id]);
        $this->assertDatabaseCount('assistant_turns', 0);
        $this->getJson(self::URL.'/'.$id)->assertOk()->assertJsonPath('turns', []);
    }

    public function test_guest_and_unverified_user_cannot_use_any_history_route(): void
    {
        $conversation = AssistantConversation::factory()->create();
        $this->assertHistoryStatus($conversation, 401);
        $this->actingAs(User::factory()->unverified()->create());
        $this->assertHistoryStatus($conversation, 403);
    }

    public function test_bearer_token_does_not_replace_the_web_session(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('history')->plainTextToken;

        $this->withToken($token)->getJson(self::URL)->assertUnauthorized();
    }

    #[DataProvider('foreignActorRoles')]
    public function test_foreign_uuid_is_treated_as_missing_even_for_admins(?string $role): void
    {
        $actor = User::factory()->create();
        if ($role !== null) {
            $actor->assignRole(Role::findOrCreate($role, 'web'));
        }
        $private = AssistantConversation::factory()->create();
        $missing = self::URL.'/'.Str::uuid();
        $this->actingAs($actor);
        $known = self::URL.'/'.$private->id;

        $absent = $this->getJson($missing)->assertNotFound();
        $foreign = $this->getJson($known.'?per_page=999')->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($absent->json('message'), $foreign->json('message'));
        $this->deleteJson($known)->assertNotFound();
        $this->assertDatabaseHas('assistant_conversations', ['id' => $private->id, 'user_id' => $private->user_id]);
    }

    public static function foreignActorRoles(): array
    {
        return [[null], ['Admin'], ['SuperAdmin']];
    }

    public function test_lists_only_owned_conversations_and_paginates_newest_activity_first(): void
    {
        config(['ai.assistant_history.page_size' => 2]);
        $owner = User::factory()->create();
        $earlier = AssistantConversation::factory()->for($owner)->create(['updated_at' => now()->subDay()]);
        $newest = AssistantConversation::factory()->for($owner)->create();
        AssistantConversation::factory()->create();
        $this->actingAs($owner);

        $this->getJson(self::URL.'?per_page=1')->assertOk()
            ->assertJsonPath('conversations.0.id', $newest->id)
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('pagination', ['page' => 1, 'per_page' => 1, 'has_more' => true]);
        $this->getJson(self::URL.'?page=2&per_page=1')->assertOk()
            ->assertJsonPath('conversations.0.id', $earlier->id)
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('pagination.has_more', false);
        $this->getJson(self::URL.'?page=3&per_page=1')->assertOk()->assertJsonPath('conversations', []);
        $this->getJson(self::URL.'?per_page=3')->assertUnprocessable();
        $this->getJson(self::URL.'?page=0')->assertUnprocessable();
        $this->getJson(self::URL.'/invalid-uuid')->assertNotFound();
    }

    public function test_conversation_quota_is_per_owner_and_deletion_frees_a_slot(): void
    {
        config(['ai.assistant_history.max_conversations' => 1]);
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $id = $this->postJson(self::URL)->assertCreated()->json('conversation.id');
        $this->postJson(self::URL)->assertConflict();
        $this->assertDatabaseCount('assistant_conversations', 1);

        $this->actingAs(User::factory()->create())->postJson(self::URL)->assertCreated();
        $this->actingAs($owner)->deleteJson(self::URL.'/'.$id)->assertNoContent();
        $this->postJson(self::URL)->assertCreated();
        $this->assertDatabaseCount('assistant_conversations', 2);
    }

    public function test_turn_query_is_encrypted_at_rest_and_only_minimal_owned_history_is_paginated(): void
    {
        $owner = User::factory()->create();
        $conversation = AssistantConversation::factory()->for($owner)->create();
        $store = app(AssistantConversationHistory::class);
        $refs = [self::reference()];
        $first = $store->appendTurn($owner, $conversation->id, self::QUERY, 'ok', 10, $refs);
        $next = $store->appendTurn($owner, $conversation->id, 'А какие из них относятся к XIX веку?', 'no_results', 5, []);

        $this->assertTrue(Str::isUuid($first->id));
        $raw = DB::table('assistant_turns')->where('id', $first->id)->first();
        $this->assertStringNotContainsString(self::QUERY, $raw->user_query);
        $this->assertSame(self::QUERY, Crypt::decryptString($raw->user_query));
        $this->assertSame($refs, json_decode($raw->source_references, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(self::QUERY, $first->fresh()->user_query);
        $this->assertArrayNotHasKey('user_query', $first->fresh()->toArray());
        $this->assertArrayNotHasKey('source_references', $first->fresh()->toArray());

        $url = self::URL.'/'.$conversation->id;
        $this->actingAs($owner)->getJson($url.'?per_page=1')->assertOk()
            ->assertJsonPath('turns.0.id', $next->id)->assertJsonPath('turns.0.sequence', 2)
            ->assertJsonPath('pagination.has_more', true);
        $this->getJson($url.'?page=2&per_page=1')->assertOk()
            ->assertJsonPath('turns.0.user_query', self::QUERY)
            ->assertJsonPath('turns.0.sequence', 1)
            ->assertJsonPath('turns.0.result_status', 'ok')
            ->assertJsonPath('turns.0.requested_limit', 10)
            ->assertJsonMissingPath('turns.0.source_references')
            ->assertJsonPath('pagination.has_more', false);
    }

    public function test_reads_history_without_disclosing_revoked_or_any_other_saved_citations(): void
    {
        $owner = User::factory()->create();
        $conversation = AssistantConversation::factory()->for($owner)->create();
        $private = Artwork::factory()->create(['title' => 'Revoked private title', 'description' => 'Revoked private description']);
        $blocked = Artwork::factory()->create(['title' => 'Blocked title', 'description' => 'Blocked description']);
        $public = Artwork::factory()->create(['title' => 'Other source title']);
        AssistantTurn::factory()->for($conversation, 'conversation')->create(['user_query' => self::QUERY, 'source_references' => [
            self::reference($private->id), self::reference($blocked->id), self::reference($public->id),
        ]]);
        DB::table('artworks')->where('id', $private->id)->update(['is_private' => true]);
        DB::table('artworks')->where('id', $blocked->id)->update(['is_blocked' => true]);

        DB::enableQueryLog();
        try {
            $response = $this->actingAs($owner)->getJson(self::URL.'/'.$conversation->id);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $response->assertOk()->assertJsonPath('turns.0.user_query', self::QUERY)->assertExactJsonStructure([
            'conversation' => ['id', 'created_at', 'updated_at'],
            'turns' => [['id', 'sequence', 'user_query', 'result_status', 'requested_limit', 'created_at', 'updated_at']],
            'pagination' => ['page', 'per_page', 'has_more'],
        ])->assertDontSee('Revoked private')->assertDontSee('Blocked title')->assertDontSee('Other source title');
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:artworks|source_references)\b/i', $query['query']);
        }
    }

    public function test_append_requires_ownership_and_enforces_a_turn_limit(): void
    {
        config(['ai.assistant_history.max_turns' => 1]);
        $owner = User::factory()->create();
        $conversation = AssistantConversation::factory()->for($owner)->create();
        $store = app(AssistantConversationHistory::class);

        try {
            $store->appendTurn(User::factory()->create(), $conversation->id, self::QUERY, 'no_results', 10, []);
            $this->fail('Foreign history accepted a turn.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertDatabaseCount('assistant_turns', 0);
        $store->appendTurn($owner, $conversation->id, str_repeat('я', 500), 'no_results', 10, []);

        try {
            $store->appendTurn($owner, $conversation->id, self::QUERY, 'no_results', 10, []);
            $this->fail('Turn limit did not apply.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('assistant_turns', 1);
    }

    #[DataProvider('invalidTurns')]
    public function test_turn_writer_rejects_unbounded_or_unexpected_data(
        string $query,
        array $references,
        array $config,
        string $errorKey,
    ): void {
        config($config);
        $owner = User::factory()->create();
        $conversation = AssistantConversation::factory()->for($owner)->create();

        try {
            app(AssistantConversationHistory::class)->appendTurn($owner, $conversation->id, $query, 'ok', 10, $references);
            $this->fail('Invalid turn was stored.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($errorKey, $error->errors());
        }
        $this->assertDatabaseCount('assistant_turns', 0);
    }

    public static function invalidTurns(): array
    {
        return [
            'max 500 characters' => [str_repeat('я', 501), [], [], 'user_query'],
            'configured text cap' => [self::QUERY, [], ['ai.assistant_history.max_query_chars' => 2], 'user_query'],
            'too many facts' => [self::QUERY, array_fill(0, 6, self::reference()), [], 'source_references'],
            'byte budget' => [self::QUERY, [self::reference()], ['ai.assistant_history.max_reference_bytes' => 128], 'source_references'],
            'direct file URL' => [self::QUERY, [[...self::reference(), 'url' => '/storage/private']], [], 'source_references.0'],
            'copied description' => [self::QUERY, [[...self::reference(), 'description' => 'Old text']], [], 'source_references.0'],
            'no verifiable revision' => [self::QUERY, [[...self::reference(), 'revision_hash' => 's1']], [], 'source_references.0.revision_hash'],
        ];
    }

    public function test_delete_cascades_to_turns_and_keeps_other_conversations(): void
    {
        $owner = User::factory()->create();
        $conversation = AssistantConversation::factory()->for($owner)->create();
        $turn = AssistantTurn::factory()->for($conversation, 'conversation')->create();
        $other = AssistantTurn::factory()->create();

        $this->actingAs($owner)->deleteJson(self::URL.'/'.$conversation->id)
            ->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson(self::URL.'/'.$conversation->id)->assertNotFound();
        $this->assertDatabaseMissing('assistant_turns', ['id' => $turn->id]);
        $this->assertDatabaseHas('assistant_turns', ['id' => $other->id]);
    }

    public function test_user_deletion_cascades_through_history_tables(): void
    {
        $owner = User::factory()->create();
        $conversation = AssistantConversation::factory()->for($owner)->create();
        $turn = AssistantTurn::factory()->for($conversation, 'conversation')->create();

        DB::table('users')->where('id', $owner->id)->delete();
        $this->assertDatabaseMissing('assistant_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('assistant_turns', ['id' => $turn->id]);
    }

    public function test_history_flag_gates_every_route_and_disabling_assistant_prevents_new_history(): void
    {
        $owner = User::factory()->create();
        $conversation = AssistantConversation::factory()->for($owner)->create();
        $this->actingAs($owner);
        config(['ai.assistant_history_enabled' => false]);
        $this->assertHistoryStatus($conversation, 404);
        $this->assertDatabaseCount('assistant_conversations', 1);

        config(['ai.assistant_history_enabled' => true, 'ai.assistant_enabled' => false]);
        $this->postJson(self::URL)->assertNotFound();
        $this->getJson(self::URL.'/'.$conversation->id)->assertOk();
        $this->deleteJson(self::URL.'/'.$conversation->id)->assertNoContent();
    }

    public function test_create_and_delete_require_csrf_in_the_web_group(): void
    {
        $user = User::factory()->create();
        $this->withSession([auth()->guard('web')->getName() => $user->id, '_token' => 'history-csrf']);
        $this->app->instance('env', 'local');

        try {
            $this->postJson(self::URL)->assertStatus(419);
            $id = $this->postJson(self::URL, [], ['X-CSRF-TOKEN' => 'history-csrf'])->assertCreated()->json('conversation.id');
            $this->deleteJson(self::URL.'/'.$id)->assertStatus(419);
            $this->deleteJson(self::URL.'/'.$id, [], ['X-CSRF-TOKEN' => 'history-csrf'])->assertNoContent();
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_existing_search_and_answer_remain_stateless_with_history_enabled(): void
    {
        config(['ai.assistant_rag_enabled' => true, 'ai.assistant_llm_enabled' => false]);
        $owner = User::factory()->create();
        AssistantConversation::factory()->for($owner)->create();
        $this->mock(HybridSearchService::class)->shouldReceive('search')->twice()
            ->with(self::QUERY, ['type' => 'image'], 1, 20)->andReturn(['artworks' => collect(), 'total' => 0]);

        $this->actingAs($owner)->postJson('/assistant/search', ['query' => self::QUERY])
            ->assertOk()->assertExactJson(['status' => 'no_results', 'results' => []]);
        $this->postJson('/assistant/answer', ['query' => self::QUERY])->assertOk()
            ->assertExactJson(['status' => 'no_results', 'answer' => [], 'sources' => []]);
        $this->assertDatabaseCount('assistant_conversations', 1);
        $this->assertDatabaseCount('assistant_turns', 0);
    }

    private function assertHistoryStatus(AssistantConversation $conversation, int $status): void
    {
        $path = self::URL.'/'.$conversation->id;
        $this->postJson(self::URL)->assertStatus($status);
        $this->getJson(self::URL)->assertStatus($status);
        $this->getJson($path)->assertStatus($status);
        $this->deleteJson($path)->assertStatus($status);
    }

    private static function reference(int $artworkId = 441055): array
    {
        return [
            'artwork_id' => $artworkId,
            'fact_ref' => 'f2',
            'revision_hash' => str_repeat('a', 64),
            'fact_sha256' => str_repeat('b', 64),
        ];
    }
}
