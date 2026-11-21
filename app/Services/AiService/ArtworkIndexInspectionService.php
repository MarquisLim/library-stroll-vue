<?php

namespace App\Services\AiService;

use App\Contracts\AiServiceClient;
use App\Models\AiProcessingRun;
use App\Models\Artwork;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use UnexpectedValueException;

class ArtworkIndexInspectionService
{
    public function __construct(
        private readonly AiServiceClient $client,
        private readonly ArtworkIndexingService $indexing,
    ) {}

    public function inspect(int $artworkId): array
    {
        $artwork = Artwork::withoutGlobalScopes()->with('aiMetadata')->find($artworkId);
        $metadata = $artwork?->aiMetadata;
        $report = [
            'mysql_status' => $metadata?->status ?? 'missing',
            'qdrant_status' => 'not checked',
            'content_hash' => 'unknown',
            'versions' => 'unknown',
            'embedding_model' => $metadata?->embedding_model ?: 'unknown',
            'fingerprint' => 'unknown',
        ];

        if ($artwork === null) {
            return [...$report, 'group' => 'E', 'reason' => 'artwork does not exist'];
        }

        $eligible = $this->isEligible($artwork);
        $hash = $this->indexing->contentHash($artwork);
        $savedId = $metadata?->qdrant_point_id;
        try {
            $inspection = $this->client->inspectArtworkIndex($artworkId, $savedId);
        } catch (RequestException $e) {
            $status = $e->response->status() === 503 && $e->response->json('detail') === 'qdrant_collection_missing'
                ? 'collection missing' : 'unavailable';
        } catch (ConnectionException|UnexpectedValueException) {
            $status = 'unavailable';
        }

        if (isset($status)) {
            return [...$report, 'qdrant_status' => $status, 'group' => $eligible ? 'F' : 'E',
                'reason' => $eligible ? 'index inspection failed' : 'artwork is not eligible for indexing'];
        }

        $points = $inspection['points'] ?? null;
        $savedPoint = $inspection['saved_point'] ?? null;
        $dimension = $inspection['dimension'] ?? null;
        $collection = $inspection['collection'] ?? null;
        if (($inspection['artwork_id'] ?? null) !== $artworkId || ! is_array($points) || ! array_is_list($points)
            || count($points) > 2 || ! is_bool($inspection['has_duplicates'] ?? null)
            || ! array_key_exists('vector_present', $inspection)
            || ! is_string($collection) || $collection === '' || ! is_int($dimension) || $dimension < 1
            || ! in_array($savedPoint, $savedId === null ? ['not_provided'] : ['matched', 'missing', 'conflict'], true)) {
            return [...$report, 'qdrant_status' => 'invalid response', 'group' => $eligible ? 'F' : 'E',
                'reason' => $eligible ? 'invalid index inspection response' : 'artwork is not eligible for indexing'];
        }

        $duplicates = $inspection['has_duplicates'] || count($points) > 1;
        $report['qdrant_status'] = match (true) {
            $duplicates => 'multiple points found',
            $savedPoint === 'conflict' => 'saved point conflict',
            $points === [] => 'point not found',
            default => 'point found',
        };
        if ($duplicates || $savedPoint === 'conflict'
            || ($points === [] && ($savedPoint === 'matched' || $inspection['vector_present'] !== null))) {
            return [...$report, 'group' => 'F', 'reason' => 'conflicting point identities'];
        }
        if (! $eligible) {
            return [...$report, 'group' => 'E', 'reason' => 'artwork is not eligible for indexing'];
        }
        if ($points === []) {
            return [...$report, 'group' => 'D', 'reason' => 'point absent after a successful collection check'];
        }
        if (($inspection['vector_present'] ?? null) !== true) {
            return [...$report, 'group' => 'F', 'reason' => 'no vector confirmed for the point'];
        }

        $point = $points[0];
        if (! is_array($point) || ($point['artwork_id'] ?? null) !== $artworkId || $savedPoint !== 'matched'
            || ! is_string($point['id'] ?? null) || strtolower($point['id']) !== strtolower($savedId)) {
            return [...$report, 'group' => 'F', 'reason' => 'point identity does not match MySQL'];
        }

        $pointHash = $point['content_hash'] ?? null;
        $savedHash = $metadata->content_hash;
        $report['content_hash'] = match (true) {
            ! is_string($pointHash) || $pointHash === '' => 'unknown',
            $pointHash !== $hash || (filled($savedHash) && $pointHash !== $savedHash) => 'mismatch',
            filled($savedHash) => 'match',
            default => 'unknown',
        };
        $versions = [config('ai.schema_version'), config('ai.prompt_version')];
        $pointVersions = [$point['schema_version'] ?? null, $point['prompt_version'] ?? null];
        $report['versions'] = match (true) {
            in_array(null, $pointVersions, true) => 'unknown',
            $pointVersions === $versions && $versions === [$metadata->metadata_schema_version, $metadata->prompt_version] => 'match',
            default => 'mismatch',
        };
        $digest = $this->fingerprint($point['embedding_model_digest'] ?? null);
        $fingerprint = $this->fingerprint($point['embedding_fingerprint'] ?? null);
        $report['fingerprint'] = $digest ?? $fingerprint ?? 'unknown';

        if ($report['content_hash'] === 'mismatch') {
            return [...$report, 'group' => 'C', 'reason' => 'point hash differs from the current or saved revision'];
        }

        $visibilityMatches = ($point['is_published'] ?? null) === (bool) $artwork->is_published
            && ($point['is_private'] ?? null) === (bool) $artwork->is_private
            && ($point['is_blocked'] ?? null) === (bool) $artwork->is_blocked;
        if ($report['content_hash'] !== 'match' || $report['versions'] !== 'match' || ! $visibilityMatches
            || ($metadata->embedding_dimension !== null && $metadata->embedding_dimension !== $dimension)) {
            return [...$report, 'group' => 'F', 'reason' => 'revision, versions, visibility or dimensions cannot be reconciled'];
        }

        $pointModel = $point['embedding_model'] ?? null;
        if (($digest !== null && $fingerprint !== null && $digest !== $fingerprint)
            || (filled($pointModel) && filled($metadata->embedding_model) && $pointModel !== $metadata->embedding_model)) {
            return [...$report, 'group' => 'F', 'reason' => 'conflicting embedding provenance'];
        }
        $report['embedding_model'] = $metadata->embedding_model ?: ($pointModel ?: 'unknown');
        if ($report['fingerprint'] === 'unknown' || ! filled($pointModel) || ! filled($metadata->embedding_model)) {
            return [...$report, 'group' => 'B', 'reason' => 'embedding provenance has not been confirmed'];
        }

        $run = $this->lastSuccessfulIndexRun($artworkId);
        $receipt = $run?->parameters ?? [];
        $recordedFingerprint = $this->fingerprint($receipt['embedding_model_digest'] ?? null);
        if ($recordedFingerprint === null || ($receipt['collection'] ?? null) !== $collection
            || ($receipt['qdrant_point_id'] ?? null) !== $point['id'] || ($receipt['content_hash'] ?? null) !== $hash
            || ! filled($receipt['embedding_model'] ?? null)) {
            return [...$report, 'group' => 'B', 'reason' => 'no matching provenance in the latest successful run'];
        }

        if ($recordedFingerprint !== $report['fingerprint'] || $receipt['embedding_model'] !== $pointModel) {
            return [...$report, 'group' => 'F', 'reason' => 'point and successful run disagree on embedding provenance'];
        }

        return [...$report, 'group' => 'A', 'reason' => 'point and successful run record the same embedding provenance',
            'recovery_evidence' => [
                'sql' => $this->sqlRevision($artwork, $hash, $run),
                'indexed_at' => $metadata->getRawOriginal('indexed_at'),
                'point' => [$collection, $point['id'], $pointHash, $dimension, $report['fingerprint']],
            ]];
    }

    public function sqlRevisionMatches(int $artworkId, string $expected): bool
    {
        $artwork = Artwork::withoutGlobalScopes()->with('aiMetadata')->find($artworkId);
        if ($artwork === null || $artwork->aiMetadata === null || ! $this->isEligible($artwork)) {
            return false;
        }

        $run = $this->lastSuccessfulIndexRun($artworkId);

        return $run !== null && $this->sqlRevision($artwork, $this->indexing->contentHash($artwork), $run) === $expected;
    }

    private function isEligible(Artwork $artwork): bool
    {
        return $artwork->indexingEnabled() && Artwork::publiclyVisible()->whereKey($artwork->getKey())->exists();
    }

    private function lastSuccessfulIndexRun(int $artworkId): ?AiProcessingRun
    {
        return AiProcessingRun::where('artwork_id', $artworkId)
            ->where('operation', 'analyze_and_index')->where('status', 'success')->whereNotNull('finished_at')
            ->latest('finished_at')->latest('id')->first();
    }

    private function sqlRevision(Artwork $artwork, string $contentHash, AiProcessingRun $run): string
    {
        return hash('sha256', serialize([
            $contentHash,
            $artwork->aiMetadata->getRawOriginal(),
            $run->getRawOriginal(),
        ]));
    }

    private function fingerprint(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A(?:sha256:)?([a-f0-9]{64})\z/i', $value, $matches)
            ? 'sha256:'.strtolower($matches[1]) : null;
    }
}
