<?php

namespace App\Services\AiService;

use App\Jobs\DeleteArtworkIndexJob;
use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Models\ArtworkColbertExpectation;
use App\Models\User;
use Illuminate\Queue\NullQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ArtworkIndexVisibility
{
    public function __construct(private ArtworkIndexingService $indexing) {}

    public function artworkUpdated(Artwork $artwork): void
    {
        if (! config('ai.enabled') || ! $artwork->wasChanged(['is_published', 'is_private', 'is_blocked', 'ai_indexing_enabled'])) {
            return;
        }

        if ($this->indexing->canIndex($artwork)) {
            if (! $artwork->wasChanged('is_published') || config('ai.auto_analyze_on_publish')) {
                $this->indexAfterCommit($artwork);
            }

            return;
        }

        // Drafts and AI-disabled imports with no index history need no cleanup.
        if (($artwork->getRawOriginal('is_published') && $artwork->getRawOriginal('ai_indexing_enabled'))
            || $artwork->aiMetadata()->exists()) {
            $this->removeAfterCommit($artwork);
        }
    }

    public function authorUpdated(User $author): void
    {
        if (! config('ai.enabled') || ! $author->is_blocked || ! $author->wasChanged('is_blocked')) {
            return;
        }

        // On unblock, the regular bounded reconciliation can pick up stale or missing indexes.
        Artwork::withoutGlobalScopes()->where('user_id', $author->id)
            ->where('is_published', true)->where('is_private', false)
            ->where('is_blocked', false)->where('ai_indexing_enabled', true)
            ->lockForUpdate()->eachById(fn (Artwork $artwork) => $this->removeAfterCommit($artwork));
    }

    public function authorDeleting(User $author): void
    {
        if (config('ai.enabled')) {
            Artwork::withoutGlobalScopes()->where('user_id', $author->id)->lockForUpdate()
                ->eachById(fn (Artwork $artwork) => $this->removeAfterCommit($artwork));
        }
    }

    public function indexAfterCommit(Artwork $artwork): void
    {
        if (! config('ai.enabled')) {
            return;
        }

        $artwork->getConnection()->afterCommit(function () use ($artwork) {
            try {
                $this->indexing->dispatch($artwork);
            } catch (Throwable $error) {
                Log::error('Could not queue AI indexing after artwork commit.', [
                    'artwork_id' => $artwork->id, 'error_type' => $error::class,
                ]);
            }
        });
    }

    public function removeAfterCommit(Artwork $artwork): void
    {
        if (! config('ai.enabled')) {
            return;
        }

        $artwork->aiMetadata()->whereIn('status', [
            ArtworkAiMetadata::STATUS_INDEXED, ArtworkAiMetadata::STATUS_PROCESSING,
        ])->update(['status' => ArtworkAiMetadata::STATUS_STALE]);

        $intent = AiProcessingRun::create([
            'artwork_id' => $artwork->id,
            'operation' => 'delete_index',
            'status' => 'pending',
            'request_id' => Str::uuid()->toString(),
            'parameters' => ['deleted_artwork_id' => $artwork->id, 'stage' => 'publication_cleanup'],
            'started_at' => now(),
        ]);
        if (config('ai.colbert_sync.auto_enabled')) {
            ArtworkColbertExpectation::updateOrCreate(['artwork_id' => $artwork->id], [
                'state' => ArtworkColbertExpectation::DELETED,
                'revision_hash' => $this->indexing->contentHash($artwork),
                'dense_attempt' => $artwork->aiMetadata()->value('attempts'),
                'searchable_text' => null,
                'text_sha256' => null,
            ]);
        }
        $artwork->getConnection()->afterCommit(function () use ($intent, $artwork) {
            try {
                $this->enqueueRemoval($artwork->id, $intent->id);
            } catch (Throwable $error) {
                Log::error('AI index cleanup is pending after a queue error.', [
                    'artwork_id' => $artwork->id, 'intent_id' => $intent->id, 'error_type' => $error::class,
                ]);
            }
        });
    }

    public function enqueueRemoval(int $artworkId, int $intentId): void
    {
        $queue = Queue::connection();
        if ($queue instanceof SyncQueue || $queue instanceof NullQueue) {
            throw new RuntimeException('AI index cleanup requires an asynchronous queue.');
        }

        $job = new DeleteArtworkIndexJob($artworkId, $intentId);
        $queue->push($job, queue: $job->queue);
    }
}
