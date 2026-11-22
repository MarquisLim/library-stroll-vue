<?php

namespace App\Jobs;

use App\Jobs\Middleware\ArtworkAiJobLock;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Services\AiService\ArtworkIndexingService;
use App\Services\AiService\ColbertDocumentClient;
use App\Services\AiService\ColbertDocumentException;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use LogicException;
use SensitiveParameter;
use Throwable;

class SyncArtworkColbertJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = ArtworkAiJobLock::MAX_RESERVATIONS;

    public int $maxExceptions = ArtworkAiJobLock::MAX_EXCEPTIONS;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(
        protected int $artworkId,
        protected string $expectedContentHash,
        #[SensitiveParameter]
        protected string $searchableText,
        protected int $metadataId,
        protected int $denseAttempt,
    ) {
        $this->onQueue(config('ai.queue', 'ai'));
        $this->afterCommit();
    }

    public function middleware(): array
    {
        return [ArtworkAiJobLock::middleware($this->artworkId)];
    }

    public function backoff(): array
    {
        return [15, 60, 120];
    }

    public function handle(ArtworkIndexingService $indexing, ColbertDocumentClient $client): void
    {
        if (! config('ai.colbert_sync.auto_enabled') || ! config('ai.colbert_sync.enabled') || ! $this->isCurrent($indexing)) {
            return;
        }

        $textHash = hash('sha256', $this->searchableText);
        try {
            $remote = $client->get($this->artworkId);
            try {
                $this->updateIfCurrent($indexing, $client, $textHash, $remote);
            } catch (ColbertDocumentException $error) {
                if ($error->getCode() !== 409) {
                    throw $error;
                }

                $this->resolveConflict($indexing, $client, $textHash, $remote);
            }
        } catch (ColbertDocumentException $error) {
            if (in_array($error->getCode(), [0, 503], true)) {
                throw $error;
            }

            $this->fail($error);
        } catch (LogicException) {
            $this->fail(new LogicException('ColBERT document client configuration is invalid.'));
        }
    }

    private function resolveConflict(
        ArtworkIndexingService $indexing,
        ColbertDocumentClient $client,
        string $textHash,
        array $previous,
    ): void {
        if (! $this->isCurrent($indexing)) {
            return;
        }

        try {
            $remote = $client->get($this->artworkId);
            if ($remote['content_hash'] === $textHash) {
                return;
            }
            // The remote API does not expose a Dense revision. A different remote text or deletion needs review.
            if ($remote['exists'] && $previous['exists'] && $remote['content_hash'] === $previous['content_hash']) {
                $this->updateIfCurrent($indexing, $client, $textHash, $remote);

                return;
            }
        } catch (ColbertDocumentException $error) {
            // A new queue reservation would lose the original remote precondition after a known conflict.
            if (! in_array($error->getCode(), [0, 409, 503], true)) {
                throw $error;
            }
        }

        throw new ColbertDocumentException('ColBERT document conflict could not be resolved safely.', 409);
    }

    private function updateIfCurrent(
        ArtworkIndexingService $indexing,
        ColbertDocumentClient $client,
        string $textHash,
        array $remote,
    ): void {
        if ($remote['content_hash'] === $textHash || ! $this->isCurrent($indexing)) {
            return;
        }

        $written = $client->upsert($this->artworkId, $this->searchableText, $remote['content_hash'], $remote['version']);
        if ($written['content_hash'] !== $textHash) {
            throw new ColbertDocumentException('ColBERT returned a different document hash.', 502);
        }
    }

    private function isCurrent(ArtworkIndexingService $indexing): bool
    {
        $artwork = Artwork::withoutGlobalScopes()->find($this->artworkId);

        return $indexing->canIndex($artwork) && $indexing->contentHash($artwork) === $this->expectedContentHash
            && $artwork->aiMetadata()->whereKey($this->metadataId)
                ->where('status', ArtworkAiMetadata::STATUS_INDEXED)
                ->where('content_hash', $this->expectedContentHash)
                ->where('attempts', $this->denseAttempt)
                ->exists();
    }

    public function failed(?Throwable $error): void
    {
        Log::error('ColBERT document sync failed.', [
            'artwork_id' => $this->artworkId,
            'content_hash' => $this->expectedContentHash,
            'dense_attempt' => $this->denseAttempt,
            'exception_type' => $error ? $error::class : null,
            'http_status' => $error instanceof ColbertDocumentException ? $error->getCode() : null,
        ]);
    }
}
