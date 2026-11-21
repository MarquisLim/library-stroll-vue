<?php

namespace App\Jobs;

use App\Contracts\AiServiceClient;
use App\Jobs\Middleware\ArtworkAiJobLock;
use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Services\AiService\ArtworkIndexingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AnalyzeAndIndexArtworkJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = ArtworkAiJobLock::MAX_RESERVATIONS;

    public int $maxExceptions = ArtworkAiJobLock::MAX_EXCEPTIONS;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public bool $failOnTimeout = true;

    // Older serialized jobs predate these fields and retain their original behavior.
    protected bool $force = true;

    protected bool $reconciliation = false;

    public function __construct(
        protected int $artworkId,
        protected string $expectedContentHash = '',
        protected ?string $expectedMediaUuid = null,
        bool $force = true,
        bool $reconciliation = false,
    ) {
        $this->force = $force;
        $this->reconciliation = $reconciliation;
        $this->onQueue(config('ai.queue', 'ai'));
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return "analyze-artwork-{$this->artworkId}-{$this->expectedContentHash}";
    }

    public function middleware(): array
    {
        return [ArtworkAiJobLock::middleware($this->artworkId)];
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [15, 60, 120];
    }

    public function handle(AiServiceClient $aiClient): void
    {
        $requestId = Str::uuid()->toString();
        $startedAt = now();

        $indexing = app(ArtworkIndexingService::class);
        $claim = $this->claimRevision($indexing);

        if ($claim === null) {
            return;
        }

        [$artwork, $currentMedia, $contentHash, $metadata] = $claim;

        AiProcessingRun::create([
            'artwork_id' => $artwork->id, 'operation' => 'analyze_and_index', 'status' => 'processing',
            'request_id' => $requestId, 'started_at' => $startedAt,
            'parameters' => ['stage' => 'analysis', 'content_hash' => $contentHash, 'attempt' => $metadata->attempts],
        ]);

        try {
            $imagePath = null;
            if ($currentMedia) {
                $thumbPath = $currentMedia->getPath('thumb');
                if (is_file($thumbPath)) {
                    $imagePath = $thumbPath;
                } elseif ($artwork->type === 'image') {
                    $originalPath = $currentMedia->getPath();
                    if (is_file($originalPath)) {
                        $imagePath = $originalPath;
                    }
                }
            }

            $existingTags = $artwork->tags()->pluck('name')->all();

            $analysis = $aiClient->analyzeArtwork(
                artworkId: $artwork->id,
                title: $artwork->title ?? '',
                description: $artwork->description ?? '',
                existingTags: $existingTags,
                imagePath: $imagePath,
            );
            if ($analysis->warnings !== []) {
                Log::warning('AI analysis used a fallback.', [
                    'artwork_id' => $artwork->id,
                    'analysis_source' => $analysis->analysisSource,
                    'warnings' => $analysis->warnings,
                ]);
            }

            $searchableText = implode(' ', array_filter([
                $artwork->title,
                $artwork->description,
                $analysis->summary,
                implode(' ', $analysis->tagsEn),
                implode(' ', $analysis->tagsRu),
                implode(' ', $existingTags),
                implode(' ', $analysis->objects),
                implode(' ', $analysis->themes),
                implode(' ', $analysis->styles),
                implode(' ', $analysis->mood),
                implode(' ', $analysis->colors),
            ]));

            $freshArtwork = Artwork::withoutGlobalScopes()->find($this->artworkId);
            if (! $freshArtwork || ! $freshArtwork->is_published || ! $freshArtwork->indexingEnabled()) {
                $metadata->update(['status' => ArtworkAiMetadata::STATUS_STALE]);
                $this->recordRun($requestId, 'analyze_and_index', 'skipped', $startedAt, parameters: ['reason' => 'artwork_unpublished_or_deleted']);

                return;
            }
            if ($indexing->contentHash($freshArtwork) !== $contentHash) {
                $metadata->markStale();
                $this->dispatchReplacement($freshArtwork, $indexing);

                $this->recordRun($requestId, 'analyze_and_index', 'skipped', $startedAt, parameters: ['reason' => 'revision_changed']);

                return;
            }

            $metadata->fill([...$analysis->toMetadataArray(), 'processed_at' => now()])->save();
            $run = AiProcessingRun::where('request_id', $requestId)->firstOrFail();
            $run->update(['model' => $analysis->chatModel, 'parameters' => [...$run->parameters, 'stage' => 'embedding_and_indexing',
                'analysis_source' => $analysis->analysisSource, 'warnings' => $analysis->warnings]]);

            $indexResult = $aiClient->indexArtwork(
                artworkId: $artwork->id,
                metadata: $analysis->toMetadataArray(),
                searchableText: $searchableText,
                payload: [
                    'artwork_id' => $artwork->id,
                    'is_published' => (bool) $artwork->is_published,
                    'is_private' => (bool) $artwork->is_private,
                    'is_blocked' => (bool) $artwork->is_blocked,
                    'user_id' => $artwork->user_id,
                    'media_type' => $artwork->type,
                    'has_ai' => (bool) $artwork->has_ai,
                    'tags' => $existingTags,
                ],
                contentHash: $contentHash,
                idempotencyKey: $metadata->qdrant_point_id ?? '',
            );

            if (empty($indexResult['qdrant_point_id']) || ($indexResult['embedding_dimension'] ?? 0) < 1) {
                throw new \RuntimeException('AI index response has no point ID or valid embedding dimension. Update the AI service.');
            }
            $latest = Artwork::withoutGlobalScopes()->find($this->artworkId);
            if (! $latest || ! $latest->is_published || ! $latest->indexingEnabled()) {
                $metadata->update(['status' => ArtworkAiMetadata::STATUS_STALE]);
                $aiClient->deleteArtworkIndex($this->artworkId);

                $this->recordRun($requestId, 'analyze_and_index', 'skipped', $startedAt, parameters: ['reason' => 'artwork_unpublished_or_deleted']);

                return;
            }
            if ($indexing->contentHash($latest) !== $contentHash) {
                $metadata->markStale();
                $this->dispatchReplacement($latest, $indexing);

                $this->recordRun($requestId, 'analyze_and_index', 'skipped', $startedAt, parameters: ['reason' => 'revision_changed']);

                return;
            }

            $metadata->fill([
                'status' => ArtworkAiMetadata::STATUS_INDEXED,
                'qdrant_point_id' => $indexResult['qdrant_point_id'] ?? null,
                'content_hash' => $contentHash,
                'source_media_uuid' => $currentMedia?->uuid,
                ...$analysis->toMetadataArray(),
                'embedding_dimension' => $indexResult['embedding_dimension'] ?? null,
                'metadata_schema_version' => config('ai.schema_version'),
                'prompt_version' => config('ai.prompt_version'),
                'last_error' => null,
                'processed_at' => now(),
                'indexed_at' => now(),
            ]);
            $metadata->save();

            $this->recordRun($requestId, 'analyze_and_index', 'success', $startedAt, $analysis->chatModel,
                parameters: ['analysis_source' => $analysis->analysisSource, 'warnings' => $analysis->warnings]);

            Log::info('Artwork analyzed and indexed.', [
                'artwork_id' => $artwork->id,
                'request_id' => $requestId,
                'latency_ms' => $analysis->latencyMs,
            ]);
        } catch (Throwable $e) {
            $metadata->update([
                'status' => ArtworkAiMetadata::STATUS_FAILED,
                'last_error' => Str::limit($e->getMessage(), 500),
            ]);

            $this->recordRun($requestId, 'analyze_and_index', 'failed', $startedAt, error: $e->getMessage());

            throw $e;
        }
    }

    private function claimRevision(ArtworkIndexingService $indexing): ?array
    {
        return DB::transaction(function () use ($indexing) {
            $artwork = Artwork::withoutGlobalScopes()->lockForUpdate()->find($this->artworkId);
            if (! $artwork || ! $artwork->is_published || ! $artwork->indexingEnabled()) {
                return null;
            }

            $metadata = $artwork->aiMetadata()->lockForUpdate()->first()
                ?? new ArtworkAiMetadata(['artwork_id' => $artwork->id]);
            if ($this->reconciliation && $metadata->isFailed()
                && $metadata->attempts >= ArtworkIndexingService::RECONCILIATION_ATTEMPT_LIMIT) {
                return null;
            }

            $media = $artwork->getFirstMedia('artworks');
            $hash = $indexing->contentHash($artwork);
            if (($this->expectedContentHash !== '' && $this->expectedContentHash !== $hash)
                || ($this->expectedMediaUuid && $media?->uuid !== $this->expectedMediaUuid)) {
                DB::afterCommit(fn () => $this->dispatchReplacement($artwork, $indexing));

                return null;
            }

            if (! $this->force && $metadata->isIndexed() && $metadata->content_hash === $hash) {
                return null;
            }

            $metadata->status = ArtworkAiMetadata::STATUS_PROCESSING;
            $metadata->attempts = $metadata->attempts + 1;
            $metadata->save();

            return [$artwork, $media, $hash, $metadata];
        });
    }

    private function dispatchReplacement(Artwork $artwork, ArtworkIndexingService $indexing): void
    {
        if ($indexing->dispatch($artwork) || $this->job === null || $this->expectedContentHash === '') {
            return;
        }

        // If the edit was reverted, the unique key can still belong to this running job.
        $latest = $artwork->fresh();
        if ($latest && $latest->is_published && $latest->indexingEnabled()
            && $indexing->contentHash($latest) === $this->expectedContentHash) {
            $this->release(ArtworkAiJobLock::RELEASE_DELAY);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('AnalyzeAndIndexArtworkJob failed permanently.', [
            'artwork_id' => $this->artworkId,
            'attempts' => $this->attempts(),
            'error' => $exception?->getMessage(),
        ]);
    }

    private function recordRun(
        string $requestId,
        string $operation,
        string $status,
        \DateTimeInterface $startedAt,
        string $model = '',
        string $error = '',
        array $parameters = [],
    ): void {
        $previous = AiProcessingRun::where('request_id', $requestId)->first();
        AiProcessingRun::updateOrCreate(['request_id' => $requestId], [
            'artwork_id' => Artwork::withoutGlobalScopes()->whereKey($this->artworkId)->exists() ? $this->artworkId : null,
            'operation' => $operation,
            'status' => $status,
            'request_id' => $requestId,
            'model' => $model ?: $previous?->model,
            'parameters' => [...($previous?->parameters ?? []), ...$parameters, 'stage' => match ($status) {
                'success' => 'completed', 'skipped' => 'skipped', default => $previous?->parameters['stage'] ?? 'analysis',
            }],
            'latency_ms' => (int) abs(now()->diffInMilliseconds($startedAt)),
            'error' => $error ?: null,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }
}
