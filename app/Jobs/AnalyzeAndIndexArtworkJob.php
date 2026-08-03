<?php

namespace App\Jobs;

use App\Contracts\AiServiceClient;
use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AnalyzeAndIndexArtworkJob implements ShouldQueue, ShouldBeUnique
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public bool $failOnTimeout = true;

    public function __construct(
        protected int $artworkId,
        protected string $expectedContentHash = '',
        protected ?string $expectedMediaUuid = null,
    ) {
        $this->onQueue(config('ai.queue', 'ai'));
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return "analyze-artwork-{$this->artworkId}-{$this->expectedContentHash}";
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

        $artwork = Artwork::withoutGlobalScopes()->find($this->artworkId);

        if (! $artwork || ! $artwork->is_published) {
            Log::info('Skipping AI analysis for non-existent or unpublished artwork.', [
                'artwork_id' => $this->artworkId,
            ]);
            return;
        }

        $currentMedia = $artwork->getFirstMedia('artworks');
        if ($this->expectedMediaUuid && $currentMedia?->uuid !== $this->expectedMediaUuid) {
            Log::info('Skipping AI analysis: media has been replaced.', [
                'artwork_id' => $this->artworkId,
                'expected_uuid' => $this->expectedMediaUuid,
                'current_uuid' => $currentMedia?->uuid,
            ]);
            return;
        }

        $metadata = $artwork->aiMetadata ?? new ArtworkAiMetadata(['artwork_id' => $artwork->id]);
        $metadata->status = ArtworkAiMetadata::STATUS_PROCESSING;
        $metadata->attempts = $metadata->attempts + 1;
        $metadata->artwork_id = $artwork->id;
        $metadata->save();

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

            $searchableText = implode(' ', array_filter([
                $artwork->title,
                $artwork->description,
                $analysis->summary,
                implode(' ', $analysis->tagsEn),
                implode(' ', $analysis->tagsRu),
            ]));

            $indexResult = $aiClient->indexArtwork(
                artworkId: $artwork->id,
                metadata: $analysis->toMetadataArray(),
                searchableText: $searchableText,
                payload: [
                    'artwork_id' => $artwork->id,
                    'is_published' => $artwork->is_published,
                    'is_private' => (bool) $artwork->is_private,
                    'is_blocked' => (bool) $artwork->is_blocked,
                    'user_id' => $artwork->user_id,
                    'media_type' => $artwork->type,
                    'has_ai' => (bool) $artwork->has_ai,
                    'tags' => $existingTags,
                ],
                contentHash: $this->expectedContentHash,
                idempotencyKey: $metadata->qdrant_point_id ?? '',
            );

            $metadata->fill([
                'status' => ArtworkAiMetadata::STATUS_INDEXED,
                'qdrant_point_id' => $indexResult['qdrant_point_id'] ?? null,
                'content_hash' => $this->expectedContentHash,
                'source_media_uuid' => $currentMedia?->uuid,
                ...$analysis->toMetadataArray(),
                'embedding_dimension' => null,
                'metadata_schema_version' => config('ai.schema_version'),
                'prompt_version' => config('ai.prompt_version'),
                'last_error' => null,
                'processed_at' => now(),
                'indexed_at' => now(),
            ]);
            $metadata->save();

            $this->recordRun($requestId, 'analyze_and_index', 'success', $startedAt, $analysis->chatModel);

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
    ): void {
        AiProcessingRun::create([
            'artwork_id' => $this->artworkId,
            'operation' => $operation,
            'status' => $status,
            'request_id' => $requestId,
            'model' => $model,
            'latency_ms' => (int) (now()->diffInMilliseconds($startedAt)),
            'error' => $error ?: null,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }
}
