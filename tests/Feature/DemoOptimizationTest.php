<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Models\Artwork;
use App\Models\ArtworkImport;
use App\Services\DemoContent\CandidateRelevanceEvaluator;
use App\Services\DemoContent\ContentItem;
use App\Services\DemoContent\Manifest;
use App\Services\DemoContent\MediaUrlPolicy;
use App\Services\DemoContent\SourceHttp;
use App\Services\DemoContent\SourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        Queue::fake();
        config(['demo_content.root' => storage_path('framework/testing/optimization-'.bin2hex(random_bytes(5))), 'demo_content.sources.openverse.enabled' => true, 'demo_content.sources.openverse.client_id' => null, 'demo_content.sources.openverse.client_secret' => null, 'media-library.image_optimizers' => [], 'ai.enabled' => true]);
        foreach (array_keys(config('demo_content.sources')) as $s) {
            config(["demo_content.sources.$s.rate_limit" => 1000000, "demo_content.sources.$s.retry_delay" => 0]);
        }
        $theme = config('demo_content.presets.balanced.animals');
        $theme['sources'] = ['openverse'];
        $theme['quota'] = 3;
        $theme['queries'] = ['animals'];
        config(['demo_content.presets.small' => ['animals' => $theme]]);
        $this->partialMock(MediaUrlPolicy::class, fn ($m) => $m->shouldReceive('addresses')->andReturn(['93.184.216.34']));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('demo_content.root'));
        parent::tearDown();
    }

    private function fake(int $count = 3): void
    {
        $records = [];
        for ($i = 1; $i <= $count; $i++) {
            $records[] = ['id' => (string) $i, 'title' => 'Wolf '.$i, 'creator' => null, 'license' => 'cc0', 'license_url' => 'https://creativecommons.org/publicdomain/zero/1.0/', 'foreign_landing_url' => 'https://example.org/wolf/'.$i, 'url' => 'https://live.staticflickr.com/wolf/'.$i.'.png', 'tags' => [['name' => 'wolf']]];
        }
        Http::fake(['api.openverse.org/*' => fn () => Http::response(['results' => $records]), 'live.staticflickr.com/*' => function ($r) {
            $n = (int) pathinfo(parse_url($r->url(), PHP_URL_PATH), PATHINFO_FILENAME);
            $im = imagecreatetruecolor(24, 24);
            imagefill($im, 0, 0, imagecolorallocate($im, $n * 15, 80, 100));
            ob_start();
            imagepng($im);
            $bytes = ob_get_clean();
            imagedestroy($im);

            return Http::response($bytes);
        }]);
    }

    private function args(array $extra = []): array
    {
        return [...['--preset' => 'small', '--images' => 3, '--videos' => 0, '--authors' => 1, '--seed' => 77, '--source' => 'openverse', '--resume' => true], ...$extra];
    }

    private function state(): array
    {
        return json_decode(File::get(app(Manifest::class)->path(77, 'small')), true);
    }

    public function test_synonyms_and_word_boundaries_without_using_query_as_evidence(): void
    {
        $e = new CandidateRelevanceEvaluator;
        $theme = config('demo_content.presets.balanced.animals');
        $item = fn ($title) => new ContentItem('met', 'x', 'image', 'https://example.org', 'https://images.metmuseum.org/a.jpg', $title, null, 'Public Domain', 'https://creativecommons.org/publicdomain/zero/1.0/');
        $this->assertTrue($e->evaluate($item('  WOLF! '), 'animals', $theme)['accepted']);
        $this->assertTrue($e->evaluate($item('ВОЛК'), 'animals', $theme)['accepted']);
        $this->assertFalse($e->evaluate($item('Cathedral'), 'animals', $theme)['accepted']);
        $this->assertFalse($e->evaluate($item('Vase'), 'animals', $theme)['accepted']);
        $theme['exclude'] = ['toy'];
        $this->assertFalse($e->evaluate($item('toy wolf'), 'animals', $theme)['accepted']);
    }

    public function test_small_quota_openverse_unknown_author_and_precise_counters(): void
    {
        $this->fake(8);
        $this->artisan('demo:populate', $this->args())->assertSuccessful();
        $this->assertDatabaseCount('artworks', 3);
        $this->assertNull(ArtworkImport::first()->creator_name);
        $s = $this->state();
        $this->assertSame(8, $s['source_stats']['openverse']['api_candidates']);
        $this->assertSame(3, $s['run_counters']['evaluated']);
        $this->assertSame(3, $s['source_stats']['openverse']['download_attempts']);
        $this->assertSame(3, $s['run_counters']['downloads_successful']);
        $this->assertSame(0, $s['run_counters']['retry_attempts']);
        $this->assertSame(3, $s['source_stats']['openverse']['dataset_outcomes']['imported']);
        $this->artisan('ai:reconcile')->assertSuccessful();
        Queue::assertNotPushed(AnalyzeAndIndexArtworkJob::class);
        $this->artisan('demo:populate', $this->args())->assertSuccessful();
        $this->assertDatabaseCount('artworks', 3);
        Http::assertSentCount(4);
        $this->artisan('demo:verify')->assertSuccessful();
    }

    public function test_recheck_reconsiders_saved_metadata_after_rules_change(): void
    {
        $this->fake(1);
        config(['demo_content.presets.small.animals.synonyms' => []]);
        // The word wolf is absent from the query animals; the first run rejects it.
        $this->artisan('demo:populate', $this->args(['--images' => 1]))->assertFailed();
        $this->assertDatabaseCount('artworks', 0);
        config(['demo_content.presets.small.animals.synonyms' => ['wolf']]);
        $this->artisan('demo:populate', $this->args(['--images' => 1, '--recheck-skipped' => true]))->assertSuccessful();
        $this->assertDatabaseCount('artworks', 1);
        Http::assertSentCount(2); // Stored DTO plus one image; no second API search.
    }

    public function test_resume_detects_missing_original_and_repairs_without_overwriting_title(): void
    {
        $this->fake(1);
        $this->artisan('demo:populate', $this->args(['--images' => 1]))->assertSuccessful();
        $a = Artwork::first();
        $a->update(['title' => 'Edited by owner']);
        unlink($a->getFirstMedia('artworks')->getPath());
        $this->artisan('demo:populate', $this->args(['--images' => 1]))->assertFailed();
        $this->assertSame('repair_required', array_values($this->state()['entries'])[0]['status']);
        $this->artisan('demo:populate', $this->args(['--images' => 1, '--repair-media' => true]))->assertSuccessful();
        $this->assertSame('Edited by owner', $a->fresh()->title);
        $this->assertDatabaseCount('artworks', 1);
        $this->artisan('demo:verify')->assertSuccessful();
    }

    public function test_pilot_is_separate_and_metadata_cache_does_not_repeat_requests(): void
    {
        $this->assertSame(50, array_sum(array_column(config('demo_content.presets.pilot'), 'quota')));
        $this->assertCount(8, config('demo_content.presets.pilot'));
        foreach (config('demo_content.presets.pilot') as $theme) {
            $this->assertContains('openverse', $theme['sources']);
        }
        $m = app(Manifest::class);
        $this->assertNotSame($m->path(42, 'pilot'), $m->path(42, 'balanced'));
        Http::fake(['*' => Http::response(['ok' => true])]);
        $h = app(SourceHttp::class);
        $h->get('met', '/test');
        $h->get('met', '/test');
        Http::assertSentCount(1);
    }

    public function test_openverse_credentials_are_sent_only_to_token_endpoint(): void
    {
        config(['demo_content.sources.openverse.client_id' => 'test-client', 'demo_content.sources.openverse.client_secret' => 'test-secret']);
        Http::fake(['*/auth_tokens/token/' => Http::response(['access_token' => 'fake-token']), '*/images/*' => Http::response(['results' => []])]);
        app(SourceHttp::class)->get('openverse', '/images/', ['q' => 'wolves']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/token/') && $r['client_id'] === 'test-client' && $r['grant_type'] === 'client_credentials');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/images/') && $r->hasHeader('Authorization', 'Bearer fake-token') && ! str_contains($r->url(), 'test-secret'));
    }

    public function test_sources_check_does_not_write_catalog_and_ready_requires_media_test(): void
    {
        $this->fake(1);
        $this->artisan('demo:sources-check', ['--source' => 'openverse'])->assertSuccessful();
        $this->assertDatabaseCount('artworks', 0);
        $report = json_decode(File::get(File::glob(config('demo_content.root').'/reports/sources-*.json')[0]), true);
        $this->assertNotSame('Ready', $report['sources'][0]['status']);
        $this->artisan('demo:sources-check', ['--source' => 'openverse', '--media' => true])->assertSuccessful();
        $this->assertDatabaseCount('artworks', 0);
    }

    public function test_large_category_can_resume_deferred_source_when_alternative_returns_403(): void
    {
        $this->fake(20);
        config(['demo_content.presets.small.animals.sources' => ['openverse', 'artic'], 'demo_content.sources.openverse.weight' => 1000000, 'demo_content.sources.artic.retry_attempts' => 0]);
        Http::fake(['api.artic.edu/*' => Http::response([], 403)]);
        $this->artisan('demo:populate', $this->args(['--images' => 12, '--source' => 'openverse,artic']))->assertSuccessful();
        $this->assertDatabaseCount('artworks', 12);
        $this->assertSame(12, $this->state()['source_stats']['openverse']['download_attempts']);
        $this->assertSame(403, $this->state()['api_errors'][0]['http_status']);
    }

    public function test_growing_targets_and_changing_sources_preserves_existing_edits(): void
    {
        $this->fake(3);
        $this->artisan('demo:populate', $this->args(['--images' => 1]))->assertSuccessful();
        $a = Artwork::first();
        $a->update(['title' => 'Preserved title']);
        $this->artisan('demo:populate', $this->args(['--images' => 3, '--source' => 'met,openverse']))->assertSuccessful();
        $this->assertDatabaseCount('artworks', 3);
        $this->assertSame('Preserved title', $a->fresh()->title);
        $this->assertSame(['met', 'openverse'], $this->state()['sources']);
    }

    public function test_repair_refuses_to_overwrite_changed_original(): void
    {
        $this->fake(1);
        $this->artisan('demo:populate', $this->args(['--images' => 1]))->assertSuccessful();
        $path = Artwork::first()->getFirstMedia('artworks')->getPath();
        file_put_contents($path, 'manual change');
        $this->artisan('demo:populate', $this->args(['--images' => 1, '--repair-media' => true]))->assertFailed();
        $this->assertSame('manual change', file_get_contents($path));
        $this->assertDatabaseCount('artworks', 1);
    }

    public function test_unknown_creator_does_not_satisfy_cc_by_attribution(): void
    {
        $item = new ContentItem('openverse', 'x', 'image', 'https://example.org', 'https://live.staticflickr.com/a.png', 'Wolf', 'Unknown', 'CC BY', 'https://creativecommons.org/licenses/by/4.0/', attribution_text: 'Unknown, CC BY');
        $this->assertFalse((bool) app(SourceRegistry::class)->allowed($item));
    }

    public function test_resume_automatically_rechecks_after_rule_changes(): void
    {
        $this->fake(1);
        config(['demo_content.presets.small.animals.synonyms' => []]);
        $this->artisan('demo:populate', $this->args(['--images' => 1]))->assertFailed();
        config(['demo_content.presets.small.animals.synonyms' => ['wolf']]);
        $this->artisan('demo:populate', $this->args(['--images' => 1]))->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_resume_fills_category_when_existing_source_is_already_at_share_cap(): void
    {
        $this->fake(15);
        $this->artisan('demo:populate', $this->args(['--images' => 8]))->assertSuccessful();
        config(['demo_content.presets.small.animals.sources' => ['openverse', 'artic'], 'demo_content.sources.openverse.weight' => 1000000]);
        Http::fake(['api.artic.edu/*' => Http::response([], 403)]);
        $this->artisan('demo:populate', $this->args(['--images' => 12, '--source' => 'openverse,artic']))->assertSuccessful();
        $this->assertDatabaseCount('artworks', 12);
        $this->assertSame(4, $this->state()['source_stats']['openverse']['download_attempts']);
    }

    public function test_corrupt_thumbnail_is_detected_and_regenerated_locally(): void
    {
        $this->fake(1);
        $this->artisan('demo:populate', $this->args(['--images' => 1]))->assertSuccessful();
        $thumb = Artwork::first()->getFirstMedia('artworks')->getPath('thumb');
        file_put_contents($thumb, 'invalid thumbnail');
        $this->artisan('demo:verify')->assertFailed();
        $this->artisan('demo:populate', $this->args(['--images' => 1, '--repair-media' => true]))->assertSuccessful();
        $this->assertNotFalse(getimagesize($thumb));
        Http::assertSentCount(2);
    }

    public function test_http_200_api_error_is_not_reported_as_ready(): void
    {
        Http::fake(['commons.wikimedia.org/*' => Http::response(['error' => ['code' => 'ratelimited']])]);
        config(['demo_content.sources.commons.enabled' => true]);
        $this->artisan('demo:sources-check', ['--source' => 'commons'])->assertFailed();
        $report = json_decode(File::get(File::glob(config('demo_content.root').'/reports/sources-*.json')[0]), true);
        $this->assertSame('Unavailable', $report['sources'][0]['status']);
        $this->assertSame(1, $report['stats']['commons']['api_errors']);
    }

    public function test_recheck_does_not_reclassify_saved_legal_rejections(): void
    {
        $this->fake(1);
        $item = new ContentItem('openverse', '1', 'image', 'https://example.org/wolf', 'https://live.staticflickr.com/wolf/1.png', 'Wolf', null, 'unverified', null, original_tags: ['wolf']);
        app(Manifest::class)->save(app(Manifest::class)->path(77, 'small'), ['seed' => 77, 'preset' => 'small', 'entries' => ['openverse:1' => ['item' => (array) $item, 'category' => 'animals', 'status' => 'license']]]);
        $this->artisan('demo:populate', $this->args(['--images' => 1, '--recheck-skipped' => true]))->assertFailed();
        $this->assertSame('license', $this->state()['entries']['openverse:1']['status']);
        $this->assertDatabaseCount('artworks', 0);
        Http::assertSentCount(1);
    }

    public function test_smithsonian_indexes_and_iiif_media_are_parsed_and_known_ids_skip_details(): void
    {
        config(['demo_content.sources.smithsonian.api_key' => 'test-key']);
        Http::fake([
            '*/search*' => Http::response(['response' => ['rows' => [['id' => 'museum-1']]]]),
            '*/content/*' => Http::response(['response' => ['id' => 'museum-1', 'title' => 'Forest', 'content' => ['descriptiveNonRepeating' => ['record_link' => 'https://www.si.edu/object/museum-1', 'online_media' => ['media' => [['type' => 'Images', 'idsId' => 'SAAM-123', 'usage' => ['access' => 'CC0']]]]], 'freetext' => ['name' => [['label' => 'Place', 'content' => 'Unrelated'], ['label' => 'Painter', 'content' => 'Actual painter']]], 'indexedStructured' => ['topic' => ['Forest'], 'object_type' => ['Painting']]]]]),
        ]);
        $adapter = app(SourceRegistry::class)->get('smithsonian');
        $items = iterator_to_array($adapter->candidates('forest', 'image'));
        $this->assertSame('Actual painter', $items[0]->creator_name);
        $this->assertContains('Forest', $items[0]->original_tags);
        $this->assertStringContainsString('/SAAM-123/full/!1200,1200/', $items[0]->media_url);
        Http::assertSent(fn ($r) => ($r['api_key'] ?? null) === 'test-key' && str_contains($r['q'] ?? '', 'online_media_type:Images'));
        app(SourceHttp::class)->known['smithsonian:museum-1'] = true;
        $this->assertSame([], iterator_to_array($adapter->candidates('forest', 'image')));
        Http::assertSentCount(2);
    }

    public function test_missing_smithsonian_key_does_not_block_other_diagnostics(): void
    {
        $this->fake(1);
        config(['demo_content.sources.smithsonian.enabled' => true, 'demo_content.sources.smithsonian.api_key' => null]);
        $this->artisan('demo:sources-check', ['--source' => 'smithsonian,openverse'])->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('artworks', 0);
    }
}
