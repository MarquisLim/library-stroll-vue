<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiArtworkVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/internal/ai/artwork-visibility';

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.enabled' => true, 'ai.api_key' => 'visibility-test-key']);
    }

    public function test_returns_only_currently_eligible_ids_in_one_read_only_query(): void
    {
        $public = Artwork::factory()->create();
        $public->aiMetadata()->create(['status' => 'failed', 'attempts' => 7]);
        $withoutMetadata = Artwork::factory()->create();
        $private = Artwork::factory()->create(['is_private' => true]);
        $draft = Artwork::factory()->create(['is_published' => false]);
        $blocked = Artwork::factory()->create(['is_blocked' => true]);
        $blockedAuthor = Artwork::factory()->for(User::factory()->create(['is_blocked' => true]))->create();
        $disabled = Artwork::factory()->create(['ai_indexing_enabled' => false]);
        $deleted = Artwork::factory()->create();
        $deletedId = $deleted->id;
        $deleted->delete();

        Queue::fake();
        Http::fake();
        DB::statement('PRAGMA query_only = ON');
        DB::enableQueryLog();
        try {
            $response = $this->withServerVariables(['AI_VISIBILITY_INTERNAL' => '1'])->postJson(self::PATH, [
                'artwork_ids' => [
                    $public->id, $withoutMetadata->id, $private->id, $draft->id, $blocked->id,
                    $blockedAuthor->id, $disabled->id, $deletedId,
                ],
            ], ['X-AI-Service-Key' => 'visibility-test-key']);

            $response->assertOk()->assertExactJson(['visible_ids' => [$public->id, $withoutMetadata->id]]);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertCount(1, DB::getQueryLog());
            $this->assertStringStartsWith('select ', DB::getQueryLog()[0]['query']);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
            DB::statement('PRAGMA query_only = OFF');
        }

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_a_header_cannot_replace_the_internal_gateway_marker(): void
    {
        $this->postJson(self::PATH, ['artwork_ids' => [1]], [
            'X-AI-Service-Key' => 'visibility-test-key',
            'AI-VISIBILITY-INTERNAL' => '1',
        ])->assertNotFound();
    }

    #[DataProvider('invalidKeys')]
    public function test_requires_a_valid_nonempty_service_key(string $configured, ?string $sent): void
    {
        config(['ai.api_key' => $configured]);
        $this->actingAs(User::factory()->create())
            ->withServerVariables(['AI_VISIBILITY_INTERNAL' => '1'])
            ->postJson(self::PATH, ['artwork_ids' => [1]], $sent === null ? [] : ['X-AI-Service-Key' => $sent])
            ->assertUnauthorized();
    }

    public static function invalidKeys(): array
    {
        return [
            'missing header' => ['visibility-test-key', null],
            'wrong header' => ['visibility-test-key', 'wrong'],
            'blank configured key' => ['   ', '   '],
        ];
    }

    #[DataProvider('invalidLists')]
    public function test_rejects_invalid_or_oversized_candidate_lists(array $ids): void
    {
        $this->withServerVariables(['AI_VISIBILITY_INTERNAL' => '1'])
            ->postJson(self::PATH, ['artwork_ids' => $ids], ['X-AI-Service-Key' => 'visibility-test-key'])
            ->assertUnprocessable();
    }

    public static function invalidLists(): array
    {
        return [[[]], [[0]], [['1']], [[true]], [['other' => 1]], [range(1, 102)]];
    }

    public function test_allows_a_full_batch_including_the_similar_source(): void
    {
        $this->withServerVariables(['AI_VISIBILITY_INTERNAL' => '1'])->postJson(
            self::PATH, ['artwork_ids' => range(1, 101)], ['X-AI-Service-Key' => 'visibility-test-key'],
        )->assertOk()->assertExactJson(['visible_ids' => []]);
    }

    public function test_rejects_an_oversized_body(): void
    {
        $this->withServerVariables(['AI_VISIBILITY_INTERNAL' => '1'])->postJson(
            self::PATH, ['artwork_ids' => [1], 'padding' => str_repeat('x', 8192)], ['X-AI-Service-Key' => 'visibility-test-key'],
        )->assertStatus(413);
    }
}
