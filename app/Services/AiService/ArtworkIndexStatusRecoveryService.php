<?php

namespace App\Services\AiService;

use App\Jobs\AnalyzeAndIndexArtworkJob;
use App\Models\ArtworkAiMetadata;
use Illuminate\Contracts\Cache\Repository as Cache;

class ArtworkIndexStatusRecoveryService
{
    public function __construct(private readonly ArtworkIndexInspectionService $inspection) {}

    /** Return the reason for refusing recovery, or null if the index is already confirmed and marked indexed. */
    public function refusalReason(int $artworkId, array $initial): ?string
    {
        if ($initial['group'] !== 'A') {
            return 'Status recovery requires a verified group A point.';
        }
        if (! in_array($initial['mysql_status'], [ArtworkAiMetadata::STATUS_FAILED, ArtworkAiMetadata::STATUS_INDEXED], true)
            || $initial['recovery_evidence']['indexed_at'] === null) {
            return 'Status recovery requires a failed or indexed record with its original indexed_at.';
        }

        // Reuse the index job's current cache key. Delete and other writers do not share it yet.
        $job = new AnalyzeAndIndexArtworkJob($artworkId);
        $middleware = $job->middleware()[0];
        $lock = app(Cache::class)->lock($middleware->getLockKey($job), 30);
        if (! $lock->get()) {
            return 'The artwork is currently locked by indexing.';
        }

        try {
            $current = $this->inspection->inspect($artworkId);
            if ($current['group'] !== 'A' || $current['recovery_evidence'] !== $initial['recovery_evidence']
                || ! $this->inspection->sqlRevisionMatches($artworkId, $current['recovery_evidence']['sql'])) {
                return 'The artwork, SQL metadata or Qdrant point changed during verification.';
            }

            if ($current['mysql_status'] === ArtworkAiMetadata::STATUS_INDEXED) {
                return null;
            }

            return 'Status writes are disabled until index, delete and publication writers share a concurrency guard.';
        } finally {
            $lock->release();
        }
    }
}
