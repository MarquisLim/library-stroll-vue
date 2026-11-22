<?php

namespace App\Services\AiService;

use App\Models\Artwork;
use App\Models\ArtworkAiMetadata;
use App\Models\ArtworkColbertExpectation;
use LogicException;

class ArtworkColbertInspectionService
{
    public function __construct(
        private ArtworkIndexingService $indexing,
        private ArtworkIndexInspectionService $denseInspection,
        private ColbertDocumentClient $colbert,
    ) {}

    public function inspect(int $artworkId): array
    {
        return $this->observe($artworkId)['report'];
    }

    public function observe(int $artworkId): array
    {
        [$revision, $metadata, $expected] = $this->readLocal($artworkId);
        $dense = $this->denseInspection->inspect($artworkId, inspectMissingArtwork: true);
        try {
            $remote = $this->colbert->get($artworkId);
        } catch (ColbertDocumentException|LogicException) {
            $remote = null;
        }
        [$latestRevision, $latestMetadata, $latestExpected] = $this->readLocal($artworkId);

        $qdrant = $dense['qdrant_status'];
        $report = [
            'eligible' => $revision !== null ? 'yes' : 'no',
            'mysql' => $metadata?->status ?? 'missing',
            'qdrant' => $qdrant,
            'dense_revision' => $dense['content_hash'],
            'expected' => $expected?->state ?? 'missing',
            'colbert' => match (true) {
                $remote === null => 'unavailable',
                $remote['exists'] => 'present',
                default => 'absent',
            },
        ];

        $changed = $revision !== $latestRevision || $metadata?->getRawOriginal() !== $latestMetadata?->getRawOriginal()
            || $expected?->getRawOriginal() !== $latestExpected?->getRawOriginal();
        $report = $this->classify($report, $revision, $metadata, $expected, $dense, $remote, $changed);

        return ['report' => $report, 'metadata' => $metadata, 'expected' => $expected, 'remote' => $remote, 'changed' => $changed];
    }

    private function classify(
        array $report,
        ?string $revision,
        ?ArtworkAiMetadata $metadata,
        ?ArtworkColbertExpectation $expected,
        array $dense,
        ?array $remote,
        bool $changed,
    ): array {
        if ($changed) {
            return $report + ['category' => 'conflict_or_unavailable', 'reason' => 'Laravel state changed during inspection'];
        }
        $qdrant = $report['qdrant'];
        if ($remote === null || ! in_array($qdrant, ['point found', 'point not found'], true)
            || ($dense['group'] === 'F' && $qdrant === 'point not found')) {
            return $report + ['category' => 'conflict_or_unavailable', 'reason' => 'service or point identity could not be verified'];
        }

        if ($revision === null) {
            if ($expected?->state !== ArtworkColbertExpectation::DELETED) {
                return $report + ['category' => 'recovery_required', 'reason' => 'no saved deletion expectation'];
            }
            if ($remote['exists']) {
                return $report + ['category' => 'colbert_should_be_deleted', 'reason' => 'ineligible artwork still has a ColBERT document'];
            }

            return $report + ($qdrant === 'point not found'
                ? ['category' => 'consistent', 'reason' => 'both indexes are absent']
                : ['category' => 'dense_unconfirmed', 'reason' => 'the Qdrant point is still present']);
        }

        if (! $metadata?->isIndexed() || $metadata->content_hash !== $revision || ! in_array($dense['group'], ['A', 'B'], true)) {
            return $report + ['category' => 'dense_unconfirmed', 'reason' => 'current Dense revision is not confirmed'];
        }
        if ($expected?->state !== ArtworkColbertExpectation::INDEXED) {
            return $report + ['category' => 'no_saved_text', 'reason' => 'no exact text recorded for the current Dense index'];
        }
        if ($expected->revision_hash !== $revision || $expected->dense_attempt !== $metadata->attempts) {
            return $report + ['category' => 'dense_unconfirmed', 'reason' => 'saved expectation belongs to a different Dense revision or attempt'];
        }
        if ($expected->verifiedSearchableText() === null) {
            return $report + ['category' => 'no_saved_text', 'reason' => 'saved text failed decryption or hash verification'];
        }

        return $report + match (true) {
            ! $remote['exists'] => ['category' => 'colbert_missing', 'reason' => 'the expected ColBERT document is absent'],
            $remote['content_hash'] !== $expected->text_sha256 => ['category' => 'colbert_stale', 'reason' => 'the ColBERT text hash differs'],
            default => ['category' => 'consistent', 'reason' => 'the current Dense revision and ColBERT text are confirmed'],
        };
    }

    private function readLocal(int $artworkId): array
    {
        $artwork = Artwork::withoutGlobalScopes()->with('aiMetadata')->find($artworkId);

        return [
            $this->indexing->canIndex($artwork) ? $this->indexing->contentHash($artwork) : null,
            $artwork?->aiMetadata,
            ArtworkColbertExpectation::find($artworkId),
        ];
    }
}
