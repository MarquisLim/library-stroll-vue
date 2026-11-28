<?php

namespace Tests\Feature;

use App\Models\Artwork;
use App\Models\AssistantConversation;
use App\Models\User;
use App\Services\AiService\ArtworkIndexingService;
use App\Services\Search\MetadataLexicalSearch;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class AssistantMetadataLexicalSearchTest extends TestCase
{
    use RefreshDatabase;

    private const DEBUG = ['X-Assistant-Debug' => '1'];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->instance('env', 'local');
        config(['ai.enabled' => false, 'ai.assistant_enabled' => true, 'ai.assistant_rag_enabled' => true,
            'ai.assistant_history_enabled' => true, 'ai.assistant_debug_enabled' => true,
            'ai.assistant_llm_enabled' => false, 'ai.assistant_lexical_metadata_enabled' => true,
            'ai.auto_analyze_on_publish' => false, 'ai.auto_reindex_on_update' => false, 'ai.rerank_enabled' => false,
            'ai.assistant_intent_routing_enabled' => false, 'ai.service_url' => 'http://ai.test', 'ai.api_key' => 'lexical-test']);
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('public');
        $this->owner = User::factory()->unverified()->create();
        $this->actingAs($this->owner);
    }

    public function test_normalizes_case_punctuation_and_yo_without_substring_matches(): void
    {
        $this->assertSame(['желтый', 'фон', 'bird'], MetadataLexicalSearch::tokens('Покажи ЖЁЛТЫЙ—фон! &nbsp; BIRD.'));
        $unrelated = $this->image('Телефон');
        $phone = $this->image('Фон');
        $this->postJson('/assistant/search', ['query' => 'фон'], self::DEBUG)->assertOk()
            ->assertJsonPath('results.0.artwork_id', $phone->id)->assertJsonCount(1, 'results')
            ->assertJsonMissing(['artwork_id' => $unrelated->id]);
    }

    public function test_uses_bilingual_metadata_exact_order_and_deterministic_ties(): void
    {
        $partial = $this->image('Untitled A');
        $first = $this->image('Untitled B');
        $second = $this->image('Untitled C');
        $this->vision($partial, ['tags_ru' => ['птица'], 'tags_en' => ['parrots']]);
        foreach ([$first, $second] as $work) {
            $this->vision($work, ['tags_ru' => ['птицы']]);
        }
        $result = $this->postJson('/assistant/search', ['query' => 'Найди ПТИЦЫ!'], self::DEBUG)->assertOk()
            ->assertJsonPath('diagnostics.stages.lexical.strategy', 'metadata_tokens')
            ->assertJsonPath('diagnostics.stages.lexical.terms', ['птицы'])->json();
        $this->assertSame([$first->id, $second->id, $partial->id], array_column($result['results'], 'artwork_id'));
        $candidates = collect($result['diagnostics']['candidates'])->keyBy('artwork_id');
        $this->assertSame(['tags_ru' => 'exact'], $candidates[$first->id]['lexical_fields']);
        $this->assertSame(['tags_ru' => 'inflection'], $candidates[$partial->id]['lexical_fields']);
        $this->assertGreaterThan($candidates[$partial->id]['lexical_score'], $candidates[$first->id]['lexical_score']);
        $this->postJson('/assistant/search', ['query' => 'Find a parrot'], self::DEBUG)->assertOk()
            ->assertJsonPath('results.0.artwork_id', $partial->id)->assertJsonPath('diagnostics.candidates.0.lexical_fields.tags_en', 'inflection');
    }

    public function test_vision_scanning_batches_sql_and_checks_stale_metadata_and_palette(): void
    {
        $palette = $this->image('Gallery item');
        $this->vision($palette, ['colors' => ['yellow']]);
        $background = $this->image('Another item');
        $this->vision($background, ['summary' => 'A bird on a yellow background']);
        $stale = $this->image('Old item');
        $this->vision($stale, ['summary' => 'A vivid yellow background', 'content_hash' => str_repeat('0', 64)]);
        DB::enableQueryLog();
        try {
            $rows = app(MetadataLexicalSearch::class)->rank('yellow background', Artwork::publiclyVisible())['rows'];
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(4, $queries);
        $this->assertSame([$background->id], array_column($rows, 'id'));
        $this->assertSame(['summary' => 'phrase'], $rows[0]['fields']);
        $this->postJson('/assistant/search', ['query' => 'yellow'], self::DEBUG)->assertOk()->assertJsonCount(2, 'results');
    }

    public function test_checks_access_ai_flag_deletion_and_actual_file_before_cutoff(): void
    {
        $visible = $this->image('Bird photograph');
        $private = $this->image('Bird', ['is_private' => true]);
        $disabled = $this->image('Bird', ['ai_indexing_enabled' => false]);
        $missing = $this->image('Bird');
        $media = $missing->getFirstMedia('artworks');
        Storage::disk($media->disk)->delete($media->getPathRelativeToRoot());
        $deleted = $this->image('Bird');
        $deleted->delete();
        $denied = $this->image('Bird');
        Gate::before(fn ($user, $ability, $args) => $ability === 'view' && ($args[0]->id ?? null) === $denied->id ? false : null);

        $result = $this->postJson('/assistant/search', ['query' => 'bird'], self::DEBUG)->assertOk()
            ->assertJsonPath('results.0.artwork_id', $visible->id)->assertJsonCount(1, 'results')
            ->assertJsonPath('diagnostics.stages.lexical.excluded_visibility_or_media', 2)->json();
        foreach ([$private, $disabled, $missing, $deleted, $denied] as $work) {
            $this->assertNotContains($work->id, array_column($result['diagnostics']['candidates'], 'artwork_id'));
        }
    }

    public function test_fuses_dense_and_exclusive_lexical_candidates_and_exports_diagnostics(): void
    {
        config(['ai.enabled' => true]);
        $both = $this->image('A bird in the sky');
        $dense = $this->image('Sky only');
        $lexical = $this->image('Bird drawing');
        Http::fake([
            'http://ai.test/api/v1/search' => Http::response(['results' => [
                ['artwork_id' => $dense->id, 'score' => 0.91, 'rank' => 1], ['artwork_id' => $both->id, 'score' => 0.81, 'rank' => 2],
            ]]),
            'http://ai.test/api/v1/assistant/compose' => Http::response(['action' => 'compose', 'selections' => [['source_ref' => 's1', 'fact_ref' => 'f1']]]),
        ]);
        $dialogue = '/assistant/conversations/'.AssistantConversation::factory()->for($this->owner)->create()->id;
        $turn = $this->postJson($dialogue.'/turns', ['query' => 'bird'], self::DEBUG)->assertCreated()
            ->assertJsonPath('sources.0.artwork_id', $both->id)->json();
        $export = json_decode($this->get($dialogue.'/export?debug=1')->assertOk()->streamedContent(), true, flags: JSON_THROW_ON_ERROR);
        $trace = $export['diagnostics'][$turn['turn_id']];
        $this->assertSame('metadata_tokens', $trace['stages']['lexical']['strategy']);
        $byId = collect($trace['candidates'])->keyBy('artwork_id');
        $this->assertTrue($byId[$lexical->id]['lexical_only']);
        $this->assertSame(1, $byId[$both->id]['rrf_rank']);
        $this->assertSame(0.81, $byId[$both->id]['dense_score']);
        Http::assertSentCount(2);
    }

    public function test_falls_back_to_title_and_leaves_flag_off_and_ordinary_search_contracts_intact(): void
    {
        $title = $this->image('Bird');
        $description = $this->image('Another work', ['description' => 'Bird']);
        $this->get('/search?q=Bird')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Search/Index')
            ->has('artworks', 1)->where('artworks.0.id', $title->id));
        config(['ai.assistant_lexical_metadata_enabled' => false]);
        $this->postJson('/assistant/search', ['query' => 'Bird'], self::DEBUG)->assertOk()
            ->assertJsonPath('diagnostics.stages.lexical.strategy', 'title_like')->assertJsonCount(1, 'results');

        config(['ai.assistant_lexical_metadata_enabled' => true]);
        $this->mock(MetadataLexicalSearch::class)->shouldReceive('rank')->once()->andThrow(new RuntimeException('Private diagnostic detail'));
        $this->postJson('/assistant/search', ['query' => 'Bird'], self::DEBUG)->assertOk()
            ->assertJsonPath('results.0.artwork_id', $title->id)->assertJsonMissing(['artwork_id' => $description->id])
            ->assertJsonPath('diagnostics.stages.lexical.fallback_reason', 'metadata_lexical_unavailable')
            ->assertJsonPath('diagnostics.stages.lexical.requested_strategy', 'metadata_tokens');
    }

    private function image(string $title, array $attributes = []): Artwork
    {
        $work = Artwork::factory()->create(['title' => $title, ...$attributes, 'description' => $attributes['description'] ?? '']);
        $work->addMedia(base_path('public/images/favicons/apple.png'))->preservingOriginal()->toMediaCollection('artworks');

        return $work;
    }

    private function vision(Artwork $work, array $data): void
    {
        $work->aiMetadata()->create($data + ['status' => 'indexed', 'vision_model' => 'test-vision',
            'source_media_uuid' => $work->getFirstMedia('artworks')->uuid,
            'content_hash' => app(ArtworkIndexingService::class)->contentHash($work)]);
    }
}
