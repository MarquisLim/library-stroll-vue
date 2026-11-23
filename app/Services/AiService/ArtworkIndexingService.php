<?php

namespace App\Services\AiService;

use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\Events\UniqueJobSkipped;
use Illuminate\Queue\NullQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Queue;
use Throwable;

class ArtworkIndexingService
{
    public const RECONCILIATION_ATTEMPT_LIMIT = 5;

    public function canIndex(?Artwork $artwork): bool
    {
        return $artwork && $artwork->is_published && ! $artwork->is_private && ! $artwork->is_blocked
            && $artwork->indexingEnabled() && $artwork->user()->where('is_blocked', false)->exists();
    }

    public function contentHash(Artwork $artwork, bool $useWritePdo = false): string
    {
        $tags = $artwork->tags()->orderBy('name');
        if ($useWritePdo) {
            $tags->useWritePdo();
        }

        return hash('sha256', json_encode([
            'title' => $artwork->title,
            'description' => $artwork->description,
            'media' => $artwork->getFirstMedia('artworks')?->uuid,
            'tags' => $tags->pluck('name')->all(),
            'published' => (bool) $artwork->is_published,
            'private' => (bool) $artwork->is_private,
            'blocked' => (bool) $artwork->is_blocked,
            'has_ai' => (bool) $artwork->has_ai,
            'schema' => config('ai.schema_version'),
            'prompt' => config('ai.prompt_version'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function dispatch(Artwork $artwork, bool $force = false): bool
    {
        if (! config('ai.enabled') || ! $artwork->exists) {
            return false;
        }

        $accepted = true;
        $artworkId = $artwork->id;
        $artwork->getConnection()->afterCommit(function () use ($artworkId, $force, &$accepted) {
            $accepted = $this->enqueue($artworkId, $force);
        });

        // A true result inside a transaction means the request is registered for after commit.
        return $accepted;
    }

    public function dispatchForReconciliation(int $artworkId, ?ArtworkAiMetadata $observed): bool
    {
        // Reconcile must report an actual enqueue, so it cannot defer it to an outer commit.
        if (app('db.transactions')->callbackApplicableTransactions()->isNotEmpty()) {
            return false;
        }

        return $this->enqueue($artworkId, force: false, observed: $observed?->getRawOriginal() ?? []);
    }

    private function enqueue(int $artworkId, bool $force, ?array $observed = null): bool
    {
        $artwork = Artwork::withoutGlobalScopes()->find($artworkId);
        if (! config('ai.enabled') || ! $this->canIndex($artwork)) {
            return false;
        }
        $hash = $this->contentHash($artwork);
        $metadata = $artwork->aiMetadata()->first();
        if (($observed !== null && $observed !== ($metadata?->getRawOriginal() ?? []))
            || (! $force && $metadata?->isIndexed() && $metadata->content_hash === $hash)) {
            return false;
        }

        $queue = Queue::connection();
        if ($queue instanceof SyncQueue || $queue instanceof NullQueue) {
            return false;
        }

        $job = new AnalyzeAndIndexArtworkJob($artworkId, $hash, $artwork->getFirstMedia('artworks')?->uuid,
            force: $force, reconciliation: $observed !== null);
        // PendingDispatch suppresses duplicates in its destructor without returning the outcome.
        $unique = new UniqueLock(app(Cache::class));
        if (! $unique->acquire($job)) {
            event(new UniqueJobSkipped($job));

            return false;
        }

        try {
            $queue->push($job, queue: $job->queue);
        } catch (Throwable $error) {
            $unique->release($job);

            throw $error;
        }

        return true;
    }
}
