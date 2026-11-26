<?php

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AiProcessingRun;
use App\Models\Artwork;
use App\Services\AiService\ArtworkIndexingService;
use App\Contracts\AiServiceClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

$id = filter_var(getenv('RECOVERY_ARTWORK_ID'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = getenv('RECOVERY_ACTION');
$demoAllowed = getenv('RECOVERY_DEMO_ALLOWED') === '1';
if (!$id || !in_array($action, ['inspect', 'enable'], true)) {
    throw new InvalidArgumentException('Invalid recovery action or artwork ID');
}
if (!config('ai.enabled') || config('ai.rerank_enabled') || config('ai.colbert_sync.enabled')
    || config('ai.colbert_sync.auto_enabled') || config('ai.queue') !== 'ai'
    || config('queue.default') !== 'redis') {
    throw new RuntimeException('Recovery requires the configured AI service, Redis AI queue and disabled reranking/ColBERT');
}

$queue = Queue::connection();
$queueName = config('ai.queue');
$queueState = [
    'pending' => $queue->pendingSize($queueName),
    'reserved' => $queue->reservedSize($queueName),
    'delayed' => $queue->delayedSize($queueName),
];
$artwork = Artwork::withoutGlobalScopes()->with(['media', 'aiMetadata', 'user' => fn ($q) => $q->withoutGlobalScopes()])->find($id);
if (!$artwork) {
    echo json_encode(['id' => $id, 'classification' => 'ineligible', 'reason' => 'missing', 'queue' => $queueState]);
    exit;
}

$metadata = $artwork->aiMetadata;
$media = $artwork->getFirstMedia('artworks');
$latest = AiProcessingRun::where('artwork_id', $id)->where('operation', 'analyze_and_index')
    ->orderByDesc('id')->first();
$hash = app(ArtworkIndexingService::class)->contentHash($artwork);
$eligible = $artwork->type === 'image' && $artwork->is_published && !$artwork->is_private
    && !$artwork->is_blocked && $artwork->user && !$artwork->user->is_blocked
    && $media && str_starts_with((string) $media->mime_type, 'image/')
    && is_file($media->getPath()) && is_file($media->getPath('thumb'));
$reason = null;
if (!$eligible) {
    $classification = 'ineligible';
    $reason = 'visibility, owner, type, original or 300px thumbnail';
} elseif (!$artwork->indexingEnabled()) {
    $classification = $artwork->is_demo && $demoAllowed ? 'enable_demo' : 'ineligible';
    $reason = 'AI indexing disabled';
} elseif (in_array($metadata?->status, ['pending', 'processing'], true)) {
    $classification = 'in_progress';
} elseif ($metadata?->status === 'indexed'
    && $metadata->content_hash === $hash
    && $metadata->source_media_uuid === $media->uuid
    && $metadata->vision_model === 'qwen3-vl:4b-instruct'
    && $latest?->status === 'success'
    && ($latest->parameters['analysis_source'] ?? null) === 'vision'
    && ($latest->parameters['warnings'] ?? []) === []
    && $latest->finished_at && $metadata->indexed_at
    && abs($latest->finished_at->timestamp - $metadata->indexed_at->timestamp) <= 2) {
    $classification = 'vision_success';
} elseif ($metadata?->status === 'failed') {
    $classification = 'failed';
} elseif ($latest && ($latest->parameters['analysis_source'] ?? null) === 'text_fallback') {
    $classification = 'text_fallback';
} elseif ($metadata?->status === 'stale' || ($metadata?->status === 'indexed' && $metadata->content_hash !== $hash)) {
    $classification = 'revision_mismatch';
} elseif ($metadata?->status === 'indexed') {
    $classification = 'provenance_unknown';
} else {
    $classification = 'needs_index';
}

$inspection = null;
if ($classification === 'vision_success' || ($action === 'inspect' && getenv('RECOVERY_VERIFY_POINT') === '1')) {
    $inspection = app(AiServiceClient::class)->inspectArtworkIndex($id, $metadata?->qdrant_point_id);
    $points = $inspection['points'] ?? [];
    $point = $points[0] ?? [];
    $pointOk = count($points) === 1 && !($inspection['has_duplicates'] ?? true)
        && ($inspection['saved_point'] ?? '') === 'matched'
        && ($inspection['vector_present'] ?? false) === true
        && ($inspection['dimension'] ?? 0) === 1024
        && ($point['artwork_id'] ?? null) === $id
        && ($point['content_hash'] ?? null) === $hash
        && ($point['schema_version'] ?? null) === config('ai.schema_version')
        && ($point['prompt_version'] ?? null) === config('ai.prompt_version')
        && ($point['is_published'] ?? null) === true && ($point['is_private'] ?? null) === false
        && ($point['is_blocked'] ?? null) === false;
    if (!$pointOk && $classification === 'vision_success') {
        $classification = 'revision_mismatch';
        $reason = 'Qdrant point did not match';
    }
}

if ($action === 'enable') {
    if ($classification !== 'enable_demo' || array_sum($queueState) !== 0) {
        throw new RuntimeException('Demo permission cannot be enabled in this state');
    }
    DB::transaction(function () use ($id) {
        $row = Artwork::withoutGlobalScopes()->lockForUpdate()->findOrFail($id);
        $media = $row->getFirstMedia('artworks');
        if (!$row->is_demo || $row->type !== 'image' || $row->indexingEnabled() || !$row->is_published || $row->is_private || $row->is_blocked
            || !$media || !str_starts_with((string) $media->mime_type, 'image/')
            || !is_file($media->getPath()) || !is_file($media->getPath('thumb'))
            || !$row->user()->where('is_blocked', false)->exists()) {
            throw new RuntimeException('Artwork eligibility changed');
        }
        // Explicitly enqueue one job later using ai:index-artwork.
        $row->forceFill(['ai_indexing_enabled' => true])->saveQuietly();
    });
}

echo json_encode([
    'id' => $id,
    'classification' => $classification,
    'reason' => $reason,
    'demo' => (bool) $artwork->is_demo,
    'queue' => $queueState,
    'content_hash' => $hash,
    'media_uuid' => $media?->uuid,
    'metadata' => $metadata?->only(['status', 'attempts', 'content_hash', 'source_media_uuid', 'qdrant_point_id',
        'embedding_dimension', 'vision_model', 'embedding_model', 'summary', 'objects', 'themes', 'tags_ru', 'tags_en']),
    'latest_run' => $latest ? [
        'id' => $latest->id, 'status' => $latest->status, 'latency_ms' => $latest->latency_ms,
        'stage' => $latest->parameters['stage'] ?? null,
        'analysis_source' => $latest->parameters['analysis_source'] ?? null,
        'warnings' => $latest->parameters['warnings'] ?? [],
        'reason' => $latest->parameters['reason'] ?? null,
        'error_present' => filled($latest->error),
    ] : null,
    'inspection' => $inspection,
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
