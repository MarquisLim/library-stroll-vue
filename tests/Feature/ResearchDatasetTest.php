<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideoPreviewJob;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Models\ArtworkImport;
use App\Models\Tag;
use App\Models\User;
use App\Services\DemoContent\Manifest;
use App\Services\ResearchDataset\DatasetCandidateSelector;
use App\Services\ResearchDataset\DatasetFiles;
use App\Services\ResearchDataset\DatasetMetadataExporter;
use App\Services\ResearchDataset\DatasetSampler;
use App\Services\ResearchDataset\DatasetValidator;
use App\Services\ResearchDataset\DatasetVersionManager;
use App\Services\ResearchDataset\RelevanceJudgmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ResearchDatasetTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
        Http::preventStrayRequests();
        $this->root = storage_path('framework/testing/research-'.bin2hex(random_bytes(6)));
        config(['research_dataset.root' => $this->root.'/research', 'demo_content.root' => $this->root.'/demo', 'media-library.image_optimizers' => [], 'ai.enabled' => true,
            'research_dataset.minimum_images' => 1, 'research_dataset.minimum_queries' => 2, 'research_dataset.query_target' => 12, 'research_dataset.pool_size' => 3,
            'research_dataset.minimum_judged_per_query' => 2, 'research_dataset.minimum_relevant_per_query' => 1]);
        $themes = array_intersect_key(config('demo_content.presets.balanced'), array_flip(['nature', 'animals', 'city', 'plants']));
        foreach ($themes as &$theme) {
            $theme['quota'] = 2;
        } unset($theme);
        config(['demo_content.presets.balanced' => $themes]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function fixtures(int $count = 8): array
    {
        $user = User::factory()->create(['is_demo' => true]);
        $records = [];
        $subjects = ['nature' => ['Mountain forest lake', 'mountains', 'forest', 'lake'], 'animals' => ['Wolf and birds', 'wolf', 'birds'], 'city' => ['Street and bridge', 'street', 'bridge'], 'plants' => ['Flowers in a vase', 'flowers', 'vase']];
        for ($n = 0; $n < $count; $n++) {
            $category = array_keys($subjects)[$n % 4];
            $title = $subjects[$category][0];
            $tags = array_slice($subjects[$category], 1);
            $source = $n % 2 ? 'commons' : 'met';
            $a = Artwork::factory()->create(['user_id' => $user->id, 'title' => $title, 'description' => 'Source facts '.$n, 'type' => 'image', 'is_published' => true, 'is_private' => false, 'is_blocked' => false, 'published_at' => now(), 'is_demo' => true, 'ai_indexing_enabled' => false]);
            $im = imagecreatetruecolor(32 + $n, 24);
            imagefill($im, 0, 0, imagecolorallocate($im, $n * 20, 80, 120));
            ob_start();
            imagepng($im);
            $bytes = ob_get_clean();
            imagedestroy($im);
            $a->addMediaFromString($bytes)->usingFileName('item-'.$n.'.png')->toMediaCollection('artworks');
            foreach ($tags as $tag) {
                $a->tags()->attach(Tag::firstOrCreate(['name' => $tag]));
            }
            $records[] = ArtworkImport::create(['artwork_id' => $a->id, 'source' => $source, 'external_id' => 'r'.$n, 'source_url' => 'https://example.org/art/'.$n, 'image_url' => 'https://images.metmuseum.org/'.$n.'.png', 'license' => 'Public Domain', 'license_url' => 'https://creativecommons.org/publicdomain/mark/1.0/', 'creator_name' => $n === 0 ? null : 'Original creator '.$n,
                'image_sha256' => hash('sha256', $bytes), 'media_sha256' => hash('sha256', $bytes), 'primary_category' => $category, 'is_demo' => true, 'original_date' => '1850', 'original_medium' => 'Watercolor', 'metadata' => ['dataset_seed' => 42, 'dataset_preset' => 'balanced', 'tags' => $tags, 'original' => ['title' => $title, 'tags' => $tags], 'language' => 'en']]);
        }
        app(Manifest::class)->save(app(Manifest::class)->path(42, 'balanced'), ['seed' => 42, 'preset' => 'balanced', 'quotas' => array_fill_keys(array_keys($subjects), 2), 'targets' => ['image' => 8, 'video' => 0], 'entries' => []]);

        return $records;
    }

    private function prepare(array $extra = []): void
    {
        $this->artisan('dataset:prepare', ['--name' => 'test-corpus', '--size' => 6, ...$extra])->assertSuccessful();
    }

    private function draft(): string
    {
        return config('research_dataset.root').'/test-corpus/draft';
    }

    private function rows(string $file): array
    {
        return app(DatasetFiles::class)->lines($this->draft().'/'.$file);
    }

    private function manuallyJudge(): void
    {
        $files = app(DatasetFiles::class);
        $queries = $this->rows('queries.jsonl');
        foreach ($queries as &$q) {
            $q['reviewed'] = true;
            $q['status'] = 'reviewed';
        } unset($q);
        $files->writeLines($this->draft().'/queries.jsonl', $queries);
        // Explicit fixture grades represent human input, never production automatic labels.
        $grades = array_map(fn ($p) => [...$p, 'relevance' => 3, 'annotator_id' => 'expert_1'], $this->rows('pool.jsonl'));
        $files->csv($this->root.'/filled.csv', RelevanceJudgmentService::HEADER, $grades);
        $this->artisan('dataset:annotations', ['--name' => 'test-corpus', '--import' => $this->root.'/filled.csv'])->assertSuccessful();
    }

    public function test_sampling_is_deterministic_stratified_and_preserves_provenance(): void
    {
        $this->fixtures();
        $items = app(DatasetCandidateSelector::class)->select('balanced')['items'];
        $sampler = app(DatasetSampler::class);
        $a = $sampler->sample($items, 6, 42, 'balanced');
        $b = $sampler->sample(array_reverse($items), 6, 42, 'balanced');
        $this->assertSame($a, $b);
        $this->assertCount(4, $a['distribution']);
        $this->prepare(['--size' => 8]);
        $rows = $this->rows('artworks.jsonl');
        $first = collect($rows)->firstWhere('dataset_item_id', 'met:r0');
        $this->assertNull($first['creator']);
        $this->assertSame('Public Domain', $first['license']);
        $this->assertSame('Original creator 1', collect($rows)->firstWhere('dataset_item_id', 'commons:r1')['creator']);
        $this->assertArrayHasKey('source_metadata', $first);
        $this->assertArrayHasKey('normalized_metadata', $first);
        $this->assertArrayNotHasKey('ai_metadata', $first);
    }

    public function test_shortfall_and_incomplete_catalog_are_explicit_and_cannot_freeze(): void
    {
        $this->fixtures(3);
        $this->prepare(['--size' => 20]);
        $m = app(DatasetFiles::class)->object($this->draft().'/manifest.json');
        $this->assertSame(17, $m['selection']['size_shortfall']);
        $this->assertFalse($m['catalog_audit']['catalog_complete']);
        $this->artisan('dataset:validate', ['--name' => 'test-corpus'])->assertFailed();
        $this->artisan('dataset:freeze', ['--name' => 'test-corpus', '--dataset-version' => '1.0'])->assertFailed();
        $this->assertDirectoryDoesNotExist(config('research_dataset.root').'/test-corpus/versions/1.0');
    }

    public function test_duplicate_hashes_are_excluded_before_sampling(): void
    {
        $records = $this->fixtures();
        $first = $records[0]->artwork->getFirstMedia('artworks')->getPath();
        $second = $records[1]->artwork->getFirstMedia('artworks')->getPath();
        copy($first, $second);
        $hash = hash_file('sha256', $first);
        $records[1]->update(['image_sha256' => $hash, 'media_sha256' => $hash]);
        $result = app(DatasetCandidateSelector::class)->select('balanced');
        $this->assertCount(7, $result['items']);
        $this->assertContains('duplicate_hash', array_column($result['audit']['warnings'], 'code'));
    }

    public function test_dry_run_writes_nothing_and_prepare_does_not_touch_database_or_demo_manifest(): void
    {
        $this->fixtures();
        $before = Artwork::withoutGlobalScopes()->get()->map->getAttributes()->all();
        $manifest = app(Manifest::class)->path(42, 'balanced');
        $hash = hash_file('sha256', $manifest);
        $this->prepare(['--dry-run' => true]);
        $this->assertDirectoryDoesNotExist(config('research_dataset.root'));
        $this->prepare();
        $this->assertSame($before, Artwork::withoutGlobalScopes()->get()->map->getAttributes()->all());
        $this->assertSame($hash, hash_file('sha256', $manifest));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_idempotent_preparation_preserves_manual_work_and_refresh_archives(): void
    {
        $records = $this->fixtures();
        $this->prepare();
        $this->manuallyJudge();
        $before = File::get($this->draft().'/judgments.csv');
        $this->prepare();
        $this->assertSame($before, File::get($this->draft().'/judgments.csv'));
        $records[0]->artwork->update(['description' => 'Edited outside research']);
        $this->artisan('dataset:prepare', ['--name' => 'test-corpus', '--size' => 6])->assertFailed();
        $this->prepare(['--refresh' => true]);
        $archives = File::directories(config('research_dataset.root').'/test-corpus/archives');
        $this->assertCount(1, $archives);
        $this->assertSame($before, File::get($archives[0].'/judgments.csv'));
    }

    public function test_equivalent_languages_never_cross_query_partitions_and_no_automatic_grades(): void
    {
        $this->fixtures();
        $this->prepare();
        $intents = [];
        foreach ($this->rows('queries.jsonl') as $q) {
            $intents[$q['intent_id']]['splits'][] = $q['split'];
            $intents[$q['intent_id']]['languages'][] = $q['language'];
            $this->assertFalse($q['reviewed']);
        }
        foreach ($intents as $intent) {
            $this->assertCount(1, array_unique($intent['splits']));
            $this->assertEqualsCanonicalizing(['ru', 'en'], $intent['languages']);
        }
        $report = app(DatasetValidator::class)->validate('test-corpus');
        $this->assertTrue($report['structurally_valid'], json_encode($report['errors']));
        $this->assertFalse($report['ready_to_freeze']);
        $this->assertSame(0, $report['annotation']['judged_pool_pairs']);
        $this->assertSame(6 * count($this->rows('queries.jsonl')), $report['annotation']['unjudged_corpus_pairs']);
        $this->assertSame([], app(DatasetFiles::class)->csvRows($this->draft().'/judgments.csv'));
    }

    public function test_blank_template_import_is_unjudged_and_valid_human_import_is_idempotent(): void
    {
        $this->fixtures();
        $this->prepare();
        $this->artisan('dataset:annotations', ['--name' => 'test-corpus', '--import' => $this->draft().'/annotation-template.csv'])->assertSuccessful();
        $this->assertSame([], app(DatasetFiles::class)->csvRows($this->draft().'/judgments.csv'));
        $this->manuallyJudge();
        $before = File::get($this->draft().'/judgments.csv');
        $this->artisan('dataset:annotations', ['--name' => 'test-corpus', '--import' => $this->root.'/filled.csv'])->assertSuccessful();
        $this->assertSame($before, File::get($this->draft().'/judgments.csv'));
        $row = app(DatasetFiles::class)->csvRows($this->draft().'/judgments.csv')[0];
        $this->assertSame('expert_1', $row['annotator_id']);
        $this->assertNotEmpty($row['judged_at']);
    }

    public function test_invalid_grades_unknown_ids_and_duplicate_expert_rows_are_rejected_atomically(): void
    {
        $this->fixtures();
        $this->prepare();
        $p = $this->rows('pool.jsonl')[0];
        $files = app(DatasetFiles::class);
        $good = [...$p, 'relevance' => 2, 'annotator_id' => 'expert'];
        foreach ([[...$good, 'relevance' => 4], [...$good, 'dataset_item_id' => 'met:missing'], [...$good, 'annotator_id' => 'someone@example.org']] as $bad) {
            $files->csv($this->root.'/bad.csv', RelevanceJudgmentService::HEADER, [$good, $bad]);
            $this->artisan('dataset:annotations', ['--name' => 'test-corpus', '--import' => $this->root.'/bad.csv'])->assertFailed();
            $this->assertSame([], $files->csvRows($this->draft().'/judgments.csv'));
        }
        $files->csv($this->root.'/bad.csv', RelevanceJudgmentService::HEADER, [$good, $good]);
        $this->artisan('dataset:annotations', ['--name' => 'test-corpus', '--import' => $this->root.'/bad.csv'])->assertFailed();
    }

    public function test_pool_extension_preserves_existing_pairs_and_grades(): void
    {
        $this->fixtures();
        $this->prepare();
        $this->manuallyJudge();
        $old = $this->rows('pool.jsonl');
        $grades = File::get($this->draft().'/judgments.csv');
        $this->artisan('dataset:pool', ['--name' => 'test-corpus', '--size' => 6])->assertSuccessful();
        $this->assertGreaterThan(count($old), count($this->rows('pool.jsonl')));
        $this->assertSame($grades, File::get($this->draft().'/judgments.csv'));
        foreach ($old as $p) {
            $this->assertTrue(collect($this->rows('pool.jsonl'))->contains(fn ($row) => $row['query_id'] === $p['query_id'] && $row['dataset_item_id'] === $p['dataset_item_id']));
        }
    }

    public function test_freeze_requires_human_labels_and_is_immutable_and_readable_offline(): void
    {
        $this->fixtures();
        $this->prepare();
        $this->manuallyJudge();
        $report = app(DatasetValidator::class)->validate('test-corpus');
        $this->assertTrue($report['ready_to_freeze'], json_encode($report));
        $this->artisan('dataset:freeze', ['--name' => 'test-corpus', '--dataset-version' => '1.0'])->assertSuccessful();
        $reader = app(DatasetVersionManager::class);
        $version = $reader->readVersion('test-corpus', '1.0');
        $hash = hash_file('sha256', $version['path'].'/manifest.json');
        $this->artisan('dataset:freeze', ['--name' => 'test-corpus', '--dataset-version' => '1.0'])->assertFailed();
        $this->assertSame($hash, hash_file('sha256', $version['path'].'/manifest.json'));
        $this->artisan('dataset:validate', ['--name' => 'test-corpus', '--dataset-version' => '1.0', '--offline' => true])->assertSuccessful();
        $this->artisan('dataset:freeze', ['--name' => 'test-corpus', '--dataset-version' => '1.1'])->assertSuccessful();
    }

    public function test_changed_original_and_metadata_are_detected_without_repair(): void
    {
        $records = $this->fixtures();
        $this->prepare(['--size' => 8]);
        $records[0]->artwork->update(['title' => 'Owner edit']);
        $path = $records[1]->artwork->getFirstMedia('artworks')->getPath();
        file_put_contents($path, 'changed bytes');
        $report = app(DatasetValidator::class)->validate('test-corpus');
        $this->assertContains('live_metadata_drift:met:r0', $report['errors']);
        $this->assertContains('missing_or_changed_original_path:commons:r1', $report['errors']);
        $this->assertSame('changed bytes', File::get($path));
        $this->assertSame('Owner edit', $records[0]->artwork->fresh()->title);
    }

    public function test_bad_thumbnail_license_and_private_publications_are_excluded(): void
    {
        $records = $this->fixtures();
        file_put_contents($records[0]->artwork->getFirstMedia('artworks')->getPath('thumb'), 'broken');
        $records[1]->update(['license' => 'CC BY-NC']);
        $records[2]->artwork->update(['is_private' => true]);
        $result = app(DatasetCandidateSelector::class)->select('balanced');
        $this->assertCount(5, $result['items']);
        $this->assertContains('missing_thumbnail', array_column($result['audit']['issues'], 'code'));
        $this->assertContains('license', array_column($result['audit']['issues'], 'code'));
    }

    public function test_path_traversal_and_intent_leakage_are_rejected(): void
    {
        $this->artisan('dataset:prepare', ['--name' => '../escape', '--dry-run' => true])->assertFailed();
        $this->fixtures();
        $this->prepare();
        $queries = $this->rows('queries.jsonl');
        $intent = $queries[0]['intent_id'];
        foreach ($queries as &$q) {
            if ($q['intent_id'] === $intent) {
                $q['split'] = $q['split'] === 'development' ? 'evaluation' : 'development';
                break;
            }
        } unset($q);
        app(DatasetFiles::class)->writeLines($this->draft().'/queries.jsonl', $queries);
        $report = app(DatasetValidator::class)->validate('test-corpus');
        $this->assertFalse($report['structurally_valid']);
        $this->assertTrue(collect($report['errors'])->contains(fn ($e) => str_starts_with($e, 'intent_leakage:')));
    }

    public function test_tampered_frozen_artifact_is_detected(): void
    {
        $this->fixtures();
        $this->prepare();
        $this->manuallyJudge();
        $this->artisan('dataset:freeze', ['--name' => 'test-corpus', '--dataset-version' => '1.0'])->assertSuccessful();
        $path = app(DatasetFiles::class)->version('test-corpus', '1.0');
        File::append($path.'/queries.jsonl', "{}\n");
        $report = app(DatasetValidator::class)->validate('test-corpus', '1.0', true);
        $this->assertFalse($report['structurally_valid']);
    }

    public function test_query_changes_after_grading_invalidate_judgments(): void
    {
        $this->fixtures();
        $this->prepare();
        $this->manuallyJudge();
        $queries = $this->rows('queries.jsonl');
        $queries[0]['text'] = 'A different user intent';
        app(DatasetFiles::class)->writeLines($this->draft().'/queries.jsonl', $queries);
        $report = app(DatasetValidator::class)->validate('test-corpus');
        $this->assertContains('judgment_query_changed:'.$queries[0]['query_id'], $report['errors']);
        $this->artisan('dataset:annotations', ['--name' => 'test-corpus', '--import' => $this->root.'/filled.csv'])->assertFailed();
    }

    public function test_grade_zero_is_explicit_and_conflicting_reimport_cannot_overwrite_it(): void
    {
        $this->fixtures();
        $this->prepare();
        $files = app(DatasetFiles::class);
        $p = $this->rows('pool.jsonl')[0];
        $files->csv($this->root.'/zero.csv', RelevanceJudgmentService::HEADER, [[...$p, 'relevance' => 0, 'annotator_id' => 'expert']]);
        $this->artisan('dataset:annotations', ['--name' => 'test-corpus', '--import' => $this->root.'/zero.csv'])->assertSuccessful();
        $before = File::get($this->draft().'/judgments.csv');
        $this->assertSame('0', $files->csvRows($this->draft().'/judgments.csv')[0]['relevance']);
        $files->csv($this->root.'/zero.csv', RelevanceJudgmentService::HEADER, [[...$p, 'relevance' => 3, 'annotator_id' => 'expert']]);
        $this->artisan('dataset:annotations', ['--name' => 'test-corpus', '--import' => $this->root.'/zero.csv'])->assertFailed();
        $this->assertSame($before, File::get($this->draft().'/judgments.csv'));
    }

    public function test_version_reader_has_no_database_dependency_and_ai_fields_are_never_exported(): void
    {
        $records = $this->fixtures();
        ArtworkAiMetadata::create(['artwork_id' => $records[0]->artwork_id, 'status' => 'indexed', 'summary' => 'DO_NOT_EXPORT_LLM_TEXT']);
        $this->prepare(['--size' => 8]);
        $this->assertStringNotContainsString('DO_NOT_EXPORT_LLM_TEXT', File::get($this->draft().'/artworks.jsonl'));
        $this->manuallyJudge();
        $this->artisan('dataset:freeze', ['--name' => 'test-corpus', '--dataset-version' => '1.0'])->assertSuccessful();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(DatasetVersionManager::class)->readVersion('test-corpus', '1.0');
        $this->assertSame([], DB::getQueryLog());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_local_path_traversal_and_source_credentials_in_urls_are_rejected(): void
    {
        $files = app(DatasetFiles::class);
        $this->assertFalse($files->publicLink('https://user:secret@example.org/item'));
        $this->assertFalse($files->publicLink('https://127.0.0.1/item'));
        $this->assertFalse($files->publicLink('https://example.org/item?api_key=must_not_export'));
        $this->assertFalse($files->publicLink('javascript:alert(1)'));
        $this->expectException(\RuntimeException::class);
        app(DatasetMetadataExporter::class)->path(['disk' => 'public', 'original_path' => '../.env'], 'original_path');
    }

    public function test_research_commands_refuse_production_without_writing_files(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('dataset:prepare', ['--name' => 'test-corpus', '--dry-run' => true])->assertFailed();
        $this->assertDirectoryDoesNotExist(config('research_dataset.root'));
    }

    public function test_optional_video_requires_real_preview_and_is_excluded_by_default(): void
    {
        $finder = new ExecutableFinder;
        $ffmpeg = $finder->find('ffmpeg');
        $ffprobe = $finder->find('ffprobe');
        if (! $ffmpeg || ! $ffprobe) {
            $this->markTestSkipped('FFmpeg/FFprobe required');
        }
        config(['media-library.ffmpeg_path' => $ffmpeg, 'media-library.ffprobe_path' => $ffprobe]);
        $record = $this->fixtures(1)[0];
        $a = $record->artwork;
        $a->clearMediaCollection('artworks');
        $a->update(['type' => 'video']);
        $path = $this->root.'/fixture.mp4';
        (new Process([$ffmpeg, '-y', '-f', 'lavfi', '-i', 'color=c=black:s=64x64:d=1', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $path]))->mustRun();
        $hash = hash_file('sha256', $path);
        $m = $a->addMedia($path)->usingFileName('fixture.mp4')->toMediaCollection('artworks');
        (new ProcessVideoPreviewJob($m))->handle();
        $record->update(['media_sha256' => $hash, 'image_sha256' => $hash]);
        $this->assertSame([], app(DatasetCandidateSelector::class)->select('balanced')['items']);
        $this->assertCount(1, app(DatasetCandidateSelector::class)->select('balanced', true)['items']);
        $this->prepare(['--size' => 1, '--include-videos' => true]);
        $report = app(DatasetValidator::class)->validate('test-corpus');
        $this->assertTrue($report['structurally_valid'], json_encode($report['errors']));
        file_put_contents($a->fresh()->getFirstMedia('preview')->getPath(), 'corrupt preview');
        $report = app(DatasetValidator::class)->validate('test-corpus');
        $this->assertContains('changed_video_preview:met:r0', $report['errors']);
    }
}
