<?php

namespace Tests\Feature;

use App\Actions\Artwork\DeleteArtwork;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Models\ArtworkColbertExpectation;
use App\Services\AiService\ArtworkIndexingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArtworkColbertInspectionTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = 'Exact indexed text — not a reconstruction';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.enabled' => true,
            'ai.service_url' => 'http://ai.test',
            'ai.colbert_sync.auto_enabled' => true,
            'ai.colbert_sync.enabled' => true,
            'ai.colbert_sync.url' => 'http://colbert.test',
            'ai.colbert_sync.api_key' => 'synthetic-sync-key',
        ]);
        Queue::fake();
    }

    public function test_repeated_dry_run_and_default_mode_only_read_sql_and_both_indexes(): void
    {
        [$artwork, $metadata, $expected, $point] = $this->indexed();
        $this->assertNotSame($metadata->content_hash, $expected->text_sha256);
        $stored = $expected->refresh()->getRawOriginal();
        $storedMetadata = $metadata->refresh()->getRawOriginal();
        Http::fake([
            'ai.test/*' => Http::response($point),
            'colbert.test/*' => Http::response($this->remote($artwork->id, $expected->text_sha256)),
        ]);

        DB::statement('PRAGMA query_only = ON');
        try {
            foreach ([false, true] as $dryRun) {
                $this->artisan('ai:reconcile-colbert', ['--id' => $artwork->id, '--dry-run' => $dryRun])
                    ->expectsOutputToContain('consistent')
                    ->doesntExpectOutputToContain(self::TEXT)
                    ->doesntExpectOutputToContain('synthetic-sync-key')
                    ->assertSuccessful();
            }
        } finally {
            DB::statement('PRAGMA query_only = OFF');
        }
        $this->assertSame($stored, $expected->refresh()->getRawOriginal());
        $this->assertSame($storedMetadata, $metadata->refresh()->getRawOriginal());
        $this->assertDatabaseCount('ai_processing_runs', 0);
        Queue::assertNothingPushed();
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    #[DataProvider('diagnosticCases')]
    public function test_diagnostic_categories(string $case, string $category, int $code): void
    {
        [$artwork, $metadata, $expected, $point] = $this->indexed();
        $remote = Http::response($this->remote($artwork->id, $expected->text_sha256));
        match ($case) {
            'stale text' => $remote = Http::response($this->remote($artwork->id, str_repeat('d', 64))),
            'missing document' => $remote = Http::response($this->remote($artwork->id)),
            'missing point' => $point = $this->absentPoint($artwork->id, 'missing'),
            'different point revision' => $point['points'][0]['content_hash'] = str_repeat('e', 64),
            'newer dense attempt' => $metadata->update(['attempts' => $metadata->attempts + 1]),
            'legacy text missing' => $expected->delete(),
            'bad ciphertext' => DB::table('artwork_colbert_expectations')->where('artwork_id', $artwork->id)
                ->update(['searchable_text' => 'unreadable ciphertext']),
            'hash mismatch' => $expected->update(['text_sha256' => str_repeat('a', 64)]),
            'colbert offline' => $remote = Http::response(['detail' => 'nonpublic details'], 503),
            'duplicate points' => $point['has_duplicates'] = true,
            'qdrant offline' => $point = Http::response(['detail' => 'nonpublic details'], 503),
            'conflicting absence' => $point = [...$this->absentPoint($artwork->id, 'matched'), 'vector_present' => true],
        };
        Http::fake(['ai.test/*' => is_array($point) ? Http::response($point) : $point, 'colbert.test/*' => $remote]);

        $this->artisan('ai:reconcile-colbert', ['--id' => $artwork->id])
            ->expectsOutputToContain($category)
            ->doesntExpectOutputToContain('nonpublic details')
            ->assertExitCode($code);
        Queue::assertNothingPushed();
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public static function diagnosticCases(): array
    {
        return [
            ['stale text', 'colbert_stale', 0],
            ['missing document', 'colbert_missing', 0],
            ['missing point', 'dense_unconfirmed', 0],
            ['different point revision', 'dense_unconfirmed', 0],
            ['newer dense attempt', 'dense_unconfirmed', 0],
            ['legacy text missing', 'no_saved_text', 0],
            ['bad ciphertext', 'no_saved_text', 0],
            ['hash mismatch', 'no_saved_text', 0],
            ['colbert offline', 'conflict_or_unavailable', 1],
            ['duplicate points', 'conflict_or_unavailable', 1],
            ['qdrant offline', 'conflict_or_unavailable', 1],
            ['conflicting absence', 'conflict_or_unavailable', 1],
        ];
    }

    public function test_deleted_artwork_is_still_inspected_in_both_indexes(): void
    {
        [$artwork, , $expected] = $this->indexed();
        $id = $artwork->id;
        $oldHash = $expected->text_sha256;
        app(DeleteArtwork::class)->handle($artwork);
        Queue::fake();
        Http::fake([
            'ai.test/*' => Http::response($this->absentPoint($id)),
            'colbert.test/*' => Http::sequence()->push($this->remote($id, $oldHash))->push($this->remote($id)),
        ]);

        $this->artisan('ai:reconcile-colbert', ['--id' => $id])->expectsOutputToContain('colbert_should_be_deleted')->assertSuccessful();
        $this->artisan('ai:reconcile-colbert', ['--limit' => 1])->expectsOutputToContain('consistent')->assertSuccessful();
        Http::assertSentCount(4);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        Queue::assertNothingPushed();
    }

    public function test_limit_counts_unique_laravel_ids_and_includes_legacy_metadata(): void
    {
        [$legacy, , $expected, $point] = $this->indexed();
        $expected->delete();
        [$tracked] = $this->indexed();
        Http::fake([
            "ai.test/api/v1/artworks/{$legacy->id}/index/inspect*" => Http::response($point),
            "colbert.test/api/v1/documents/{$legacy->id}" => Http::response($this->remote($legacy->id)),
        ]);

        $this->artisan('ai:reconcile-colbert', ['--limit' => 1])->expectsOutputToContain('no_saved_text')->assertSuccessful();
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), "/{$tracked->id}/"));
        $this->artisan('ai:reconcile-colbert', ['--limit' => 101])->assertExitCode(2);
        $this->artisan('ai:reconcile-colbert', ['--id' => 0])->assertExitCode(2);
        Http::assertSentCount(2);
    }

    public function test_a_local_change_while_inspecting_is_reported_as_a_conflict(): void
    {
        [$artwork, $metadata, $expected, $point] = $this->indexed();
        Http::fake([
            'ai.test/*' => Http::response($point),
            'colbert.test/*' => function () use ($artwork, $metadata, $expected) {
                $metadata->update(['attempts' => 2]);

                return Http::response($this->remote($artwork->id, $expected->text_sha256));
            },
        ]);

        $this->artisan('ai:reconcile-colbert', ['--id' => $artwork->id])
            ->expectsOutputToContain('Laravel state changed during inspection')->assertFailed();
        Queue::assertNothingPushed();
        Http::assertSentCount(2);
    }

    private function indexed(): array
    {
        $artwork = Artwork::factory()->create();
        $revision = app(ArtworkIndexingService::class)->contentHash($artwork);
        $pointId = '00000000-0000-4000-8000-000000000001';
        $metadata = $artwork->aiMetadata()->create([
            'status' => ArtworkAiMetadata::STATUS_INDEXED, 'attempts' => 1, 'content_hash' => $revision,
            'qdrant_point_id' => $pointId, 'metadata_schema_version' => config('ai.schema_version'),
            'prompt_version' => config('ai.prompt_version'), 'embedding_dimension' => 1024,
        ]);
        $expected = ArtworkColbertExpectation::create([
            'artwork_id' => $artwork->id, 'state' => ArtworkColbertExpectation::INDEXED,
            'revision_hash' => $revision, 'dense_attempt' => 1, 'searchable_text' => self::TEXT,
            'text_sha256' => hash('sha256', self::TEXT),
        ]);
        $point = $this->absentPoint($artwork->id);
        $point['saved_point'] = 'matched';
        $point['vector_present'] = true;
        $point['points'] = [[
            'id' => $pointId, 'artwork_id' => $artwork->id, 'content_hash' => $revision,
            'schema_version' => config('ai.schema_version'), 'prompt_version' => config('ai.prompt_version'),
            'is_published' => true, 'is_private' => false, 'is_blocked' => false,
        ]];

        return [$artwork, $metadata, $expected, $point];
    }

    private function absentPoint(int $id, string $savedPoint = 'not_provided'): array
    {
        return [
            'artwork_id' => $id, 'collection' => 'test', 'dimension' => 1024, 'saved_point' => $savedPoint,
            'points' => [], 'has_duplicates' => false, 'vector_present' => null,
        ];
    }

    private function remote(int $id, ?string $hash = null): array
    {
        return [
            'artwork_id' => $id, 'exists' => $hash !== null, 'content_hash' => $hash,
            'version' => $hash === null ? null : str_repeat('a', 32),
        ];
    }
}
