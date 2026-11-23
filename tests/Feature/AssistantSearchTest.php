<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\User;
use App\Services\Search\HybridSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AssistantSearchTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/assistant/search';

    private const QUERY = 'Найди изображения птиц у воды';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.assistant_enabled' => true,
            'ai.assistant_llm_enabled' => false,
            'ai.enabled' => true,
            'ai.rerank_enabled' => false,
            'ai.service_url' => 'http://ai.test',
        ]);
        Storage::fake('public');
        Queue::fake();
    }

    public function test_hybrid_results_keep_order_and_return_only_canonical_fields_without_writes(): void
    {
        $user = User::factory()->create();
        $first = $this->image(['title' => 'Bird with Pink Plumage', 'description' => 'private metadata fixture']);
        $second = $this->image(['title' => 'Water birds']);
        $lexical = $this->image(['title' => self::QUERY.' · каталог']);
        $ids = [$second->id, $first->id];
        Http::fake(['ai.test/api/v1/search' => Http::response([
            'results' => [
                ['artwork_id' => $ids[0], 'score' => 0.9, 'rank' => 1],
                ['artwork_id' => $ids[1], 'score' => 0.8, 'rank' => 2],
                ['artwork_id' => $lexical->id, 'score' => 0.7, 'rank' => 3],
            ],
        ])]);

        DB::enableQueryLog();
        try {
            $response = $this->actingAs($user)->postJson(self::URL, ['query' => '  '.self::QUERY." \t"]);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $response->assertOk()->assertExactJson([
            'status' => 'ok',
            'results' => [$this->card($lexical), $this->card($second), $this->card($first)],
        ]);
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('/artworks/'.$first->id, $response->json('results.2.url'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'http://ai.test/api/v1/search'
            && $request['query'] === self::QUERY
            && (array) $request['filters'] === ['media_type' => 'image']);
        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\s/i', $query['query']);
        }
        $this->assertDatabaseCount('artwork_ai_metadata', 0);
        $this->assertDatabaseCount('ai_processing_runs', 0);
        $this->assertDatabaseCount('artwork_colbert_expectations', 0);
        Queue::assertNothingPushed();
    }

    public function test_limit_is_applied_after_filtering_and_defaults_to_ten(): void
    {
        $user = User::factory()->create();
        $eligible = collect(range(1, 20))->map(fn () => $this->image())->reverse()->values();
        $missingOriginal = $this->image();
        Storage::disk('public')->delete($missingOriginal->getFirstMedia('artworks')->getPathRelativeToRoot());

        $search = $this->mock(HybridSearchService::class);
        $search->shouldReceive('search')->once()->with(self::QUERY, ['type' => 'image'], 1, 20)
            ->andReturn(['artworks' => collect([$missingOriginal])->concat($eligible->take(19)), 'total' => 21]);
        $search->shouldReceive('search')->twice()->with(self::QUERY, ['type' => 'image'], 1, 20)
            ->andReturn(['artworks' => $eligible, 'total' => 20]);
        $this->actingAs($user)->postJson(self::URL, ['query' => self::QUERY, 'limit' => 1])
            ->assertOk()->assertExactJson(['status' => 'ok', 'results' => [$this->card($eligible->first())]]);
        $this->postJson(self::URL, ['query' => self::QUERY, 'limit' => 20])
            ->assertOk()->assertExactJson(['status' => 'ok', 'results' => $eligible->map($this->card(...))->all()]);
        $this->postJson(self::URL, ['query' => self::QUERY])
            ->assertOk()->assertJsonCount(10, 'results');
    }

    public function test_rechecks_stale_candidates_and_permissions_from_mysql(): void
    {
        $user = User::factory()->create();
        $visible = $this->image();
        $private = $this->image();
        $draft = $this->image();
        $blocked = $this->image();
        $blockedAuthor = $this->image();
        $deleted = $this->image();
        $disabled = $this->image();
        $video = $this->image(['type' => 'video']);
        $missingMedia = Artwork::factory()->create();
        $wrongMediaType = $this->image();
        DB::table('artworks')->where('id', $private->id)->update(['is_private' => true]);
        DB::table('artworks')->where('id', $draft->id)->update(['is_published' => false]);
        DB::table('artworks')->where('id', $blocked->id)->update(['is_blocked' => true]);
        DB::table('users')->where('id', $blockedAuthor->user_id)->update(['is_blocked' => true]);
        DB::table('artworks')->where('id', $deleted->id)->delete();
        DB::table('artworks')->where('id', $disabled->id)->update(['ai_indexing_enabled' => false]);
        DB::table('media')->where('id', $wrongMediaType->getFirstMedia('artworks')->id)->update(['mime_type' => 'video/mp4']);
        DB::table('artworks')->where('id', $visible->id)->update(['title' => 'Fresh title']);

        $this->mockCandidates([
            $private, $draft, $blocked, $blockedAuthor, $video, $disabled, $missingMedia, $wrongMediaType, $deleted, $visible,
        ]);
        $this->actingAs($user)->postJson(self::URL, ['query' => self::QUERY])
            ->assertOk()->assertExactJson(['status' => 'ok', 'results' => [$this->card($visible->fresh())]]);
    }

    public function test_owner_and_admin_are_both_subject_to_public_visibility(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('Admin', 'web'));
        $private = $this->image(['user_id' => $owner->id, 'is_private' => true]);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $private));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $private));
        $this->mockCandidates([$private], times: 2);
        foreach ([$owner, $admin] as $user) {
            $this->actingAs($user)->postJson(self::URL, ['query' => self::QUERY])
                ->assertOk()->assertExactJson(['status' => 'no_results', 'results' => []]);
        }
    }

    public function test_policy_denial_excludes_a_public_candidate(): void
    {
        $user = User::factory()->create();
        $denied = $this->image();
        $this->mockCandidates([$denied]);
        Gate::before(fn ($actor, $ability) => $ability === 'view' ? false : null);

        $this->actingAs($user)->postJson(self::URL, ['query' => self::QUERY])
            ->assertOk()->assertExactJson(['status' => 'no_results', 'results' => []]);
    }

    public function test_indeterminate_access_withholds_even_earlier_candidates_and_redacts_error(): void
    {
        $user = User::factory()->create();
        $visible = $this->image();
        $indeterminate = $this->image();
        $this->mockCandidates([$visible, $indeterminate]);
        Gate::before(function ($actor, $ability, $arguments) use ($indeterminate) {
            if ($ability === 'view' && $arguments[0]->id === $indeterminate->id) {
                throw new RuntimeException('Sensitive permission lookup detail');
            }
        });
        Log::shouldReceive('warning')->once()->with(
            'Assistant search could not verify results.',
            ['exception_type' => RuntimeException::class],
        );

        $this->actingAs($user)->postJson(self::URL, ['query' => self::QUERY, 'limit' => 1])
            ->assertStatus(503)->assertExactJson(['message' => 'Artwork access could not be verified.']);
    }

    public function test_dense_failure_keeps_lexical_fallback_and_does_not_log_sensitive_data(): void
    {
        $user = User::factory()->create();
        $phrase = 'Sensitive user query';
        $match = $this->image(['title' => $phrase.' title']);
        Http::fake(['ai.test/api/v1/search' => Http::failedConnection('Transport detail including an API secret')]);
        Log::shouldReceive('warning')->once()->with(
            'Semantic search failed, using lexical only.',
            ['exception_type' => ConnectionException::class],
        );

        $this->actingAs($user)->postJson(self::URL, ['query' => $phrase])
            ->assertOk()->assertExactJson(['status' => 'ok', 'results' => [$this->card($match)]]);
        Queue::assertNothingPushed();
    }

    public function test_guest_receives_json_even_without_accept_header(): void
    {
        $this->post(self::URL, ['query' => self::QUERY])
            ->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_sanctum_token_alone_does_not_grant_browser_session_access(): void
    {
        $token = User::factory()->create()->createToken('test only')->plainTextToken;

        $this->withToken($token)->postJson(self::URL, ['query' => self::QUERY])->assertUnauthorized();
    }

    public function test_unverified_user_is_rejected_before_feature_flag_and_quota(): void
    {
        config(['ai.assistant_enabled' => false]);
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->postJson(self::URL, ['query' => self::QUERY])
            ->assertForbidden()->assertJson(['message' => 'Your email address is not verified.']);
    }

    public function test_stale_session_is_rejected_before_feature_flag(): void
    {
        config(['ai.assistant_enabled' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['password_hash_web' => 'invalidated'])
            ->postJson(self::URL, ['query' => self::QUERY])->assertUnauthorized();
    }

    public function test_browser_requests_require_valid_csrf_in_the_web_group(): void
    {
        $user = User::factory()->create();
        $token = 'test-session-csrf-token';
        $this->mockCandidates([]);
        $this->withSession([auth()->guard('web')->getName() => $user->id, '_token' => $token]);

        // Laravel skips the CSRF check when running PHPUnit in the testing environment.
        $this->app->instance('env', 'local');
        try {
            $this->postJson(self::URL, ['query' => self::QUERY])->assertStatus(419)
                ->assertHeader('Cache-Control', 'no-store, private');
            $this->postJson(self::URL, ['query' => self::QUERY], ['X-CSRF-TOKEN' => 'invalid'])->assertStatus(419);
            $this->postJson(self::URL, ['query' => self::QUERY], ['X-CSRF-TOKEN' => $token])
                ->assertOk()->assertExactJson(['status' => 'no_results', 'results' => []]);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_feature_is_disabled_before_quota_or_validation(): void
    {
        config(['ai.assistant_enabled' => false]);
        $this->actingAs(User::factory()->create());
        for ($count = 0; $count < 11; $count++) {
            $this->postJson(self::URL, [])->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_quota_is_per_user_and_resets_after_a_minute(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->mockCandidates([], times: 12);
        $this->actingAs($first);
        for ($count = 0; $count < 10; $count++) {
            $this->postJson(self::URL, ['query' => self::QUERY])->assertOk()->assertHeader('X-RateLimit-Limit', '10');
        }
        $this->postJson(self::URL, ['query' => self::QUERY])
            ->assertStatus(429)->assertHeader('Retry-After')->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($second)->postJson(self::URL, ['query' => self::QUERY])->assertOk();
        $this->travel(61)->seconds();
        $this->actingAs($first)->postJson(self::URL, ['query' => self::QUERY])->assertOk();
    }

    public function test_body_limit_measures_raw_bytes_and_is_inclusive(): void
    {
        $this->actingAs(User::factory()->create());
        $this->mockCandidates([]);
        $json = json_encode(['query' => self::QUERY], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $body = str_pad($json, 4096);

        $this->call('POST', self::URL, server: ['CONTENT_TYPE' => 'application/json'], content: $body)
            ->assertOk();
        $this->call('POST', self::URL, server: ['CONTENT_TYPE' => 'application/json'], content: $body.' ')
            ->assertStatus(413)->assertHeader('Cache-Control', 'no-store, private');
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_payloads_are_rejected(string $body): void
    {
        $response = $this->actingAs(User::factory()->create())->call(
            'POST', self::URL, server: ['CONTENT_TYPE' => 'application/json'], content: $body,
        );

        $response->assertUnprocessable()->assertJsonStructure(['message', 'errors']);
    }

    public static function invalidBodies(): array
    {
        return [
            'invalid JSON' => ['{"query":'],
            'array root' => ['[{"query":"bird"}]'],
            'missing query' => ['{"limit":10}'],
            'blank query' => ['{"query":"  "}'],
            'non-string query' => ['{"query":123}'],
            'too many Unicode characters' => [json_encode(['query' => str_repeat('я', 501)], JSON_UNESCAPED_UNICODE)],
            'under limit' => ['{"query":"bird","limit":0}'],
            'over limit' => ['{"query":"bird","limit":21}'],
            'numeric string' => ['{"query":"bird","limit":"10"}'],
            'float' => ['{"query":"bird","limit":1.0}'],
            'null' => ['{"query":"bird","limit":null}'],
            'boolean' => ['{"query":"bird","limit":true}'],
            'private filter' => ['{"query":"bird","include_private":true}'],
            'media filter' => ['{"query":"bird","type":"video"}'],
            'explicit ID' => ['{"query":"bird","id":441055}'],
            'URL' => ['{"query":"bird","url":"/artworks/441055"}'],
        ];
    }

    public function test_payload_requires_json_body_and_rejects_query_parameters(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post(self::URL, ['query' => self::QUERY])->assertUnprocessable();
        $this->postJson(self::URL.'?type=video', ['query' => self::QUERY])->assertUnprocessable();
    }

    public function test_trimmed_query_accepts_exactly_five_hundred_unicode_characters(): void
    {
        $query = str_repeat('я', 500);
        $this->mockCandidates([], query: $query);
        $this->actingAs(User::factory()->create())->postJson(self::URL, ['query' => ' '.$query.' '])
            ->assertOk()->assertExactJson(['status' => 'no_results', 'results' => []]);
    }

    public function test_normalization_runs_once_before_hybrid_search_and_keeps_canonical_read_only_results(): void
    {
        $this->enableLlm();
        $public = $this->image(['title' => 'Bird by water']);
        $normalized = 'птиц у воды';
        Http::fake([
            'ai.test/api/v1/assistant/normalize' => Http::response([
                'action' => 'search', 'search_query' => $normalized, 'clarification_code' => null,
            ]),
            'ai.test/api/v1/search' => Http::response([
                'results' => [['artwork_id' => $public->id, 'score' => 0.9, 'rank' => 1]],
            ]),
        ]);

        $this->actingAs(User::factory()->create())->postJson(self::URL, ['query' => self::QUERY])
            ->assertOk()->assertExactJson(['status' => 'ok', 'results' => [$this->card($public)]]);

        Http::assertSentCount(2);
        [$normalization, $search] = Http::recorded()->map(fn (array $entry) => $entry[0])->all();
        $this->assertSame('http://ai.test/api/v1/assistant/normalize', $normalization->url());
        $this->assertSame(['query' => self::QUERY], $normalization->data());
        $this->assertTrue($normalization->hasHeader('X-AI-Service-Key', 'assistant-test-key'));
        $this->assertSame('http://ai.test/api/v1/search', $search->url());
        $this->assertSame($normalized, $search['query']);
        $this->assertDatabaseCount('artwork_ai_metadata', 0);
        $this->assertDatabaseCount('ai_processing_runs', 0);
        $this->assertDatabaseCount('artwork_colbert_expectations', 0);
        Queue::assertNothingPushed();
    }

    public function test_normalized_search_still_rechecks_stale_private_and_blocked_candidates(): void
    {
        $this->enableLlm();
        $hidden = $this->image();
        $blocked = $this->image();
        $allowed = $this->image();
        DB::table('artworks')->where('id', $hidden->id)->update(['is_private' => true]);
        DB::table('artworks')->where('id', $blocked->id)->update(['is_blocked' => true]);
        DB::table('artworks')->where('id', $allowed->id)->update(['title' => 'Fresh bird image']);
        Http::fake(['ai.test/api/v1/assistant/normalize' => Http::response([
            'action' => 'search', 'search_query' => 'birds by water', 'clarification_code' => null,
        ])]);
        $this->mockCandidates([$hidden, $blocked, $allowed], query: 'birds by water');

        $this->actingAs(User::factory()->create())->postJson(self::URL, ['query' => 'Show me images of birds by water', 'limit' => 1])
            ->assertOk()->assertExactJson(['status' => 'ok', 'results' => [$this->card($allowed->fresh())]]);
        Http::assertSentCount(1);
    }

    public function test_clarification_returns_only_the_approved_code_without_search(): void
    {
        $this->enableLlm();
        Http::fake(['ai.test/api/v1/assistant/normalize' => Http::response([
            'action' => 'clarify', 'search_query' => null, 'clarification_code' => 'ambiguous_subject',
        ])]);
        $this->mock(HybridSearchService::class)->shouldNotReceive('search');

        $this->actingAs(User::factory()->create())->postJson(self::URL, ['query' => 'покажи это'])
            ->assertOk()->assertExactJson([
                'status' => 'clarification_required', 'clarification_code' => 'ambiguous_subject', 'results' => [],
            ])->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidNormalizations')]
    public function test_invalid_normalization_uses_one_search_with_the_original_phrase(int $status, array|string $body): void
    {
        $this->enableLlm();
        Http::fake(['ai.test/api/v1/assistant/normalize' => Http::response($body, $status)]);
        $this->mockCandidates([]);

        $this->actingAs(User::factory()->create())->postJson(self::URL, ['query' => self::QUERY])
            ->assertOk()->assertExactJson(['status' => 'no_results', 'results' => []]);
        Http::assertSentCount(1);
    }

    public static function invalidNormalizations(): array
    {
        $search = ['action' => 'search', 'search_query' => 'птиц у воды', 'clarification_code' => null];
        $clarify = ['action' => 'clarify', 'search_query' => null, 'clarification_code' => 'too_broad'];

        return [
            'http error' => [502, ['detail' => 'internal model response details']],
            'upstream timeout' => [504, ['detail' => 'assistant_normalization_timeout']],
            'malformed JSON' => [200, '{broken'],
            'unknown clarification' => [200, ['clarification_code' => 'model_says_anything'] + $clarify],
            'contradictory clarification' => [200, ['search_query' => 'птиц у воды'] + $clarify],
            'extra field' => [200, $search + ['url' => 'http://redirect.test/']],
            'missing field' => [200, ['action' => 'search', 'search_query' => 'птиц у воды']],
            'non-string query' => [200, ['search_query' => 4] + $search],
            'empty query' => [200, ['search_query' => '   '] + $search],
            'query too long' => [200, ['search_query' => str_repeat('я', 501)] + $search],
            'added author' => [200, ['search_query' => 'птиц у воды by Monet'] + $search],
            'removed geographic constraint' => [200, ['search_query' => 'птиц'] + $search],
        ];
    }

    public function test_normalization_connection_failure_preserves_search_and_only_logs_the_exception_type(): void
    {
        $this->enableLlm();
        Http::fake(['ai.test/api/v1/assistant/normalize' => Http::failedConnection('Prompt and secret fixture')]);
        Log::shouldReceive('warning')->once()->with(
            'Assistant query normalization unavailable.', ['exception_type' => ConnectionException::class],
        );
        $this->mockCandidates([]);

        $this->actingAs(User::factory()->create())->postJson(self::URL, ['query' => self::QUERY])
            ->assertOk()->assertExactJson(['status' => 'no_results', 'results' => []]);
    }

    public function test_flags_and_missing_service_key_prevent_normalization_requests(): void
    {
        Http::fake();
        $this->mockCandidates([], times: 2);
        $this->actingAs(User::factory()->create());
        $this->postJson(self::URL, ['query' => self::QUERY])->assertOk();
        $this->enableLlm();
        config(['ai.api_key' => ' ']);
        $this->postJson(self::URL, ['query' => self::QUERY])->assertOk();
        config(['ai.assistant_enabled' => false, 'ai.api_key' => 'assistant-test-key']);
        $this->postJson(self::URL, ['query' => self::QUERY])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_llm_is_not_called_before_authentication_validation_or_quota(): void
    {
        $this->enableLlm();
        Http::fake(['ai.test/api/v1/assistant/normalize' => Http::response([
            'action' => 'clarify', 'search_query' => null, 'clarification_code' => 'too_broad',
        ])]);
        $this->postJson(self::URL, ['query' => self::QUERY])->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->postJson(self::URL, ['query' => self::QUERY])
            ->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson(self::URL, ['query' => str_repeat('x', 501)])
            ->assertUnprocessable();
        Http::assertNothingSent();

        // The rejected body counts toward the verified user's existing request quota.
        for ($count = 0; $count < 9; $count++) {
            $this->postJson(self::URL, ['query' => self::QUERY])->assertOk();
        }
        $this->postJson(self::URL, ['query' => self::QUERY])->assertStatus(429);
        Http::assertSentCount(9);
    }

    private function enableLlm(): void
    {
        config(['ai.assistant_llm_enabled' => true, 'ai.api_key' => 'assistant-test-key']);
    }

    private function image(array $attributes = []): Artwork
    {
        $artwork = Artwork::factory()->create($attributes);
        $artwork->addMedia(base_path('public/images/favicons/apple.png'))->preservingOriginal()->toMediaCollection('artworks');

        return $artwork;
    }

    private function card(Artwork $artwork): array
    {
        return ['artwork_id' => $artwork->id, 'title' => $artwork->title, 'url' => '/artworks/'.$artwork->id];
    }

    private function mockCandidates(array $artworks, int $times = 1, string $query = self::QUERY): void
    {
        $this->mock(HybridSearchService::class)->shouldReceive('search')->times($times)
            ->with($query, ['type' => 'image'], 1, 20)
            ->andReturn(['artworks' => collect($artworks), 'total' => count($artworks)]);
    }
}
