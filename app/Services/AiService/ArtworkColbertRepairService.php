<?php

namespace App\Services\AiService;

use App\Jobs\DeleteArtworkColbertJob;
use App\Jobs\Middleware\ArtworkAiJobLock;
use App\Jobs\SyncArtworkColbertJob;
use App\Models\AiProcessingRun;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\NullQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

class ArtworkColbertRepairService
{
    public function __construct(private ArtworkColbertInspectionService $inspection) {}

    public function enqueue(int $artworkId): string
    {
        if (! config('ai.colbert_sync.enabled') || ! config('ai.colbert_sync.auto_enabled')) {
            return 'manual_review_required';
        }

        try {
            $lock = app(Cache::class)->lock(ArtworkAiJobLock::key($artworkId), ArtworkAiJobLock::LOCK_TTL);
            if (! $lock->get()) {
                return 'skipped_stale';
            }
        } catch (Throwable $error) {
            return $this->queueFailure($artworkId, $error);
        }

        try {
            $observation = $this->inspection->observe($artworkId);
            $report = $observation['report'];
            $category = $report['category'];
            if ($observation['changed']) {
                return 'skipped_stale';
            }
            if ($report['colbert'] === 'unavailable' || in_array($report['qdrant'], ['unavailable', 'collection missing'], true)) {
                return 'service_unavailable';
            }
            if ($category === 'consistent') {
                return 'already_consistent';
            }

            $job = $this->makeJob($artworkId, $observation);
            if ($job === null) {
                return 'manual_review_required';
            }

            try {
                $queue = Queue::connection();
                if ($queue instanceof SyncQueue || $queue instanceof NullQueue
                    || app('db.transactions')->callbackApplicableTransactions()->isNotEmpty()) {
                    return 'queue_failed';
                }
                $queue->push($job, queue: $job->queue);
            } catch (Throwable $error) {
                return $this->queueFailure($artworkId, $error);
            }

            return 'repair_queued';
        } finally {
            try {
                $lock->release();
            } catch (Throwable $error) {
                Log::warning('Could not release the ColBERT repair lock.', [
                    'artwork_id' => $artworkId, 'exception_type' => $error::class,
                ]);
            }
        }
    }

    private function makeJob(int $id, array $observation): SyncArtworkColbertJob|DeleteArtworkColbertJob|null
    {
        $report = $observation['report'];
        $remote = $observation['remote'];
        $revision = ['content_hash' => $remote['content_hash'], 'version' => $remote['version']];
        if (in_array($report['category'], ['colbert_missing', 'colbert_stale'], true)) {
            $expected = $observation['expected'];
            $text = $expected->verifiedSearchableText();
            $metadata = $observation['metadata'];

            return $text === null ? null : new SyncArtworkColbertJob(
                $id, $expected->revision_hash, $text, $metadata->id, $expected->dense_attempt, $revision,
            );
        }
        if ($report['category'] === 'colbert_should_be_deleted' && $report['qdrant'] === 'point not found') {
            $intent = AiProcessingRun::latestPublicationCleanup($id);
            if ($intent?->status === 'success' && $intent->finished_at !== null && filled($intent->request_id)) {
                return new DeleteArtworkColbertJob($id, $intent->request_id, $revision);
            }
        }

        return null;
    }

    private function queueFailure(int $artworkId, Throwable $error): string
    {
        Log::error('Could not queue ColBERT repair.', ['artwork_id' => $artworkId, 'exception_type' => $error::class]);

        return 'queue_failed';
    }
}
