<?php

namespace App\Jobs;

use App\Contracts\AiServiceClient;
use App\Jobs\Middleware\ArtworkAiJobLock;
use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Models\ArtworkColbertExpectation;
use App\Services\AiService\ArtworkIndexingService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\NullQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use SensitiveParameter;
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
        $claim = $this->claimRevision($indexing, $requestId, $startedAt);

        if ($claim === null) {
            return;
        }

        [$artwork, $currentMedia, $contentHash, $metadata] = $claim;

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

            [$outcome, $freshArtwork] = $this->saveCurrentRevision($indexing, $metadata, $contentHash, [
                ...$analysis->toMetadataArray(), 'processed_at' => now(),
            ]);
            if ($outcome === 'ineligible') {
                $this->recordRun($requestId, 'analyze_and_index', 'skipped', $startedAt, parameters: ['reason' => 'artwork_unpublished_or_deleted']);

                return;
            }
            if ($outcome === 'changed') {
                $this->dispatchReplacement($freshArtwork, $indexing);

                $this->recordRun($requestId, 'analyze_and_index', 'skipped', $startedAt, parameters: ['reason' => 'revision_changed']);

                return;
            }

            $run = AiProcessingRun::where('request_id', $requestId)->firstOrFail();
            $run->update(['model' => $analysis->chatModel, 'parameters' => [...$run->parameters, 'stage' => 'embedding_and_indexing',
                'analysis_source' => $analysis->analysisSource, 'warnings' => $analysis->warnings,
                'vision_attempts' => $analysis->visionAttempts]]);

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
            [$outcome, $latest] = $this->saveCurrentRevision($indexing, $metadata, $contentHash, [
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
            ], $searchableText);
            if ($outcome === 'ineligible') {
                $aiClient->deleteArtworkIndex($this->artworkId);
                $this->recordRun($requestId, 'analyze_and_index', 'skipped', $startedAt, parameters: ['reason' => 'artwork_unpublished_or_deleted']);

                return;
            }
            if ($outcome === 'changed') {
                $this->dispatchReplacement($latest, $indexing);
                $this->recordRun($requestId, 'analyze_and_index', 'skipped', $startedAt, parameters: ['reason' => 'revision_changed']);

                return;
            }

            $this->recordRun($requestId, 'analyze_and_index', 'success', $startedAt, $analysis->chatModel,
                parameters: ['analysis_source' => $analysis->analysisSource, 'warnings' => $analysis->warnings]);

            Log::info('Artwork analyzed and indexed.', [
                'artwork_id' => $artwork->id,
                'request_id' => $requestId,
                'latency_ms' => $analysis->latencyMs,
            ]);
        } catch (Throwable $e) {
            $this->processingMetadata($metadata)->update([
                'status' => ArtworkAiMetadata::STATUS_FAILED,
                'last_error' => Str::limit($e->getMessage(), 500),
            ]);

            $this->recordRun($requestId, 'analyze_and_index', 'failed', $startedAt, error: $e->getMessage());

            throw $e;
        }

        if (config('ai.colbert_sync.auto_enabled') && config('ai.colbert_sync.enabled')) {
            DB::afterCommit(function () use ($contentHash, $searchableText, $metadata, $requestId) {
                try {
                    $queue = Queue::connection();
                    if ($queue instanceof SyncQueue || $queue instanceof NullQueue) {
                        throw new \LogicException('ColBERT synchronization requires an asynchronous queue.');
                    }

                    $job = new SyncArtworkColbertJob(
                        $this->artworkId, $contentHash, $searchableText, $metadata->id, $metadata->attempts,
                    );
                    $queue->push($job, queue: $job->queue);
                } catch (Throwable $error) {
                    Log::error('Could not enqueue ColBERT document sync after Dense indexing.', [
                        'artwork_id' => $this->artworkId,
                        'request_id' => $requestId,
                        'content_hash' => $contentHash,
                        'exception_type' => $error::class,
                    ]);
                }
            });
        }
    }

    private function claimRevision(ArtworkIndexingService $indexing, string $requestId, \DateTimeInterface $startedAt): ?array
    {
        return DB::transaction(function () use ($indexing, $requestId, $startedAt) {
            $artwork = Artwork::withoutGlobalScopes()->lockForUpdate()->find($this->artworkId);
            if (! $indexing->canIndex($artwork)) {
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
            AiProcessingRun::create([
                'artwork_id' => $artwork->id, 'operation' => 'analyze_and_index', 'status' => 'processing',
                'request_id' => $requestId, 'started_at' => $startedAt,
                'parameters' => ['stage' => 'analysis', 'content_hash' => $hash, 'attempt' => $metadata->attempts],
            ]);

            return [$artwork, $media, $hash, $metadata];
        });
    }

    private function saveCurrentRevision(
        ArtworkIndexingService $indexing,
        ArtworkAiMetadata $metadata,
        string $hash,
        array $fields,
        #[SensitiveParameter] ?string $searchableText = null,
    ): array {
        return DB::transaction(function () use ($indexing, $metadata, $hash, $fields, $searchableText) {
            $latest = Artwork::withoutGlobalScopes()->lockForUpdate()->find($this->artworkId);
            $current = $this->processingMetadata($metadata)->lockForUpdate()->first();
            if (! $indexing->canIndex($latest)) {
                $current?->markStale();

                return ['ineligible', $latest];
            }
            if (! $current || $indexing->contentHash($latest) !== $hash) {
                $current?->markStale();

                return ['changed', $latest];
            }

            $current->update($fields);
            if ($searchableText !== null && config('ai.colbert_sync.auto_enabled')) {
                ArtworkColbertExpectation::updateOrCreate(['artwork_id' => $this->artworkId], [
                    'state' => ArtworkColbertExpectation::INDEXED,
                    'revision_hash' => $hash,
                    'dense_attempt' => $current->attempts,
                    'searchable_text' => $searchableText,
                    'text_sha256' => hash('sha256', $searchableText),
                ]);
            }

            return ['current', $latest];
        });
    }

    private function processingMetadata(ArtworkAiMetadata $metadata): \Illuminate\Database\Eloquent\Builder
    {
        return ArtworkAiMetadata::whereKey($metadata->id)->where('status', ArtworkAiMetadata::STATUS_PROCESSING);
    }

    private function dispatchReplacement(Artwork $artwork, ArtworkIndexingService $indexing): void
    {
        if ($indexing->dispatch($artwork) || $this->job === null || $this->expectedContentHash === '') {
            return;
        }

        // If the edit was reverted, the unique key can still belong to this running job.
        $latest = $artwork->fresh();
        if ($indexing->canIndex($latest)
            && $indexing->contentHash($latest) === $this->expectedContentHash
            && ! $latest->aiMetadata()->where('status', ArtworkAiMetadata::STATUS_INDEXED)
                ->where('content_hash', $this->expectedContentHash)->exists()) {
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
        $previous = AiProcessingRun::where('request_id', $requestId)->firstOrFail();
        $previous->update([
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
