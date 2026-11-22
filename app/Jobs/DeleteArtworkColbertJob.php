<?php

namespace App\Jobs;

use App\Jobs\Middleware\ArtworkAiJobLock;
use App\Models\Artwork;
use App\Services\AiService\ArtworkIndexingService;
use App\Services\AiService\ColbertDocumentClient;
use App\Services\AiService\ColbertDocumentException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

class DeleteArtworkColbertJob implements ShouldQueue
{
    use Queueable;

    public int $tries = ArtworkAiJobLock::MAX_RESERVATIONS;

    public int $maxExceptions = ArtworkAiJobLock::MAX_EXCEPTIONS;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(protected int $artworkId, protected string $requestId)
    {
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
        if (! config('ai.colbert_sync.auto_enabled') || ! config('ai.colbert_sync.enabled') || ! $this->canDelete($indexing)) {
            return;
        }

        try {
            $remote = $client->get($this->artworkId);
            if (! $remote['exists'] || ! $this->canDelete($indexing)) {
                return;
            }

            $deleted = $client->delete($this->artworkId, $remote['content_hash'], $remote['version']);
            if ($deleted['status'] === 'deleted' && ($deleted['version'] === null || $deleted['version'] === $remote['version'])) {
                throw new ColbertDocumentException('ColBERT did not return a new document deletion version.', 502);
            }
        } catch (ColbertDocumentException $error) {
            if (in_array($error->getCode(), [0, 503], true)) {
                throw $error;
            }
            if ($error->getCode() === 409 && $this->conflictIsObsolete($indexing, $client)) {
                return;
            }

            $this->fail($error);
        } catch (LogicException) {
            $this->fail(new LogicException('ColBERT document client configuration is invalid.'));
        }
    }

    private function conflictIsObsolete(ArtworkIndexingService $indexing, ColbertDocumentClient $client): bool
    {
        if (! $this->canDelete($indexing)) {
            return true;
        }

        try {
            $remote = $client->get($this->artworkId);
        } catch (ColbertDocumentException|LogicException) {
            // Keep the conflict terminal if the original deletion precondition cannot be confirmed.
            return false;
        }

        return ! $remote['exists'] || ! $this->canDelete($indexing);
    }

    private function canDelete(ArtworkIndexingService $indexing): bool
    {
        return ! $indexing->canIndex(Artwork::withoutGlobalScopes()->find($this->artworkId));
    }

    public function failed(?Throwable $error): void
    {
        Log::error('ColBERT document deletion failed.', [
            'artwork_id' => $this->artworkId,
            'request_id' => $this->requestId,
            'exception_type' => $error ? $error::class : null,
            'http_status' => $error instanceof ColbertDocumentException && $error->getCode() !== 0 ? $error->getCode() : null,
            'attempts' => $this->attempts(),
        ]);
    }
}
