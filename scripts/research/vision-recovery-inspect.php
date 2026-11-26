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

$action = getenv('RECOVERY_ACTION');
if (!in_array($action, ['environment', 'audit', 'inspect', 'enable'], true)) {
    throw new InvalidArgumentException('Invalid recovery action');
}

function assertEndpoint(string $host, int $port, string $expectedIp, int $expectedPort): void
{
    if ($port !== $expectedPort || gethostbynamel($host) !== [$expectedIp]) {
        throw new RuntimeException('Endpoint or DNS does not match the selected Compose service');
    }
}

// Verify effective Laravel connections, including overrides resolved from URLs.
$target = json_decode(getenv('RECOVERY_TARGET'), true, flags: JSON_THROW_ON_ERROR);
$db = DB::connection();
if ($db->getDriverName() !== 'mysql' || $db->getConfig('read') || $db->getConfig('write')
    || $db->getConfig('unix_socket') || $db->getConfig('database') !== $target['database']) {
    throw new RuntimeException('Unexpected Laravel MySQL connection');
}
assertEndpoint($db->getConfig('host'), (int) $db->getConfig('port'), $target['mysql'], 3306);
$database = $db->selectOne('SELECT DATABASE() AS name, VERSION() AS version, @@server_uuid AS server_uuid');
if ($database->name !== $target['database']) {
    throw new RuntimeException('Unexpected active MySQL database');
}
$redisConnection = config('queue.connections.redis.connection');
$redis = config("database.redis.$redisConnection");
if (config('queue.default') !== 'redis' || config('queue.connections.redis.driver') !== 'redis'
    || config('ai.queue') !== 'ai' || !is_array($redis) || !empty($redis['url'])) {
    throw new RuntimeException('Unexpected Laravel AI queue connection');
}
assertEndpoint($redis['host'], (int) $redis['port'], $target['redis'], 6379);
$api = parse_url(config('ai.service_url'));
if (($api['scheme'] ?? null) !== 'http' || !in_array($api['path'] ?? '', ['', '/'], true)
    || isset($api['user']) || isset($api['pass']) || isset($api['query']) || isset($api['fragment'])) {
    throw new RuntimeException('Unexpected Laravel AI service URL');
}
assertEndpoint($api['host'], $api['port'] ?? 80, $target['api'], 8001);
$configOk = config('ai.enabled') && !config('ai.rerank_enabled') && !config('ai.colbert_sync.enabled')
    && !config('ai.colbert_sync.auto_enabled');

$queue = Queue::connection();
$queueName = config('ai.queue');
$queueState = [
    'pending' => $queue->pendingSize($queueName),
    'reserved' => $queue->reservedSize($queueName),
    'delayed' => $queue->delayedSize($queueName),
];
$environment = [
    'database' => $database, 'queue' => $queueState, 'queue_name' => $queueName,
    'redis_connection' => $redisConnection, 'redis_database' => (int) $redis['database'],
    'redis_prefix' => config('database.redis.options.prefix'), 'api' => config('ai.service_url'),
    'schema_version' => config('ai.schema_version'), 'prompt_version' => config('ai.prompt_version'),
    'indexing_config_ok' => $configOk,
];
function output(array $value): void
{
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
}
if ($action === 'environment') {
    output($environment);
    exit;
}

$artworks = Artwork::withoutGlobalScopes()->with(['media', 'aiMetadata', 'user' => fn ($q) => $q->withoutGlobalScopes()]);
function eligibility(?Artwork $artwork): ?string
{
    if (!$artwork) {
        return 'missing';
    }
    $media = $artwork->getFirstMedia('artworks');
    return match (true) {
        $artwork->type !== 'image' => 'not_image',
        !$artwork->is_published || $artwork->is_private || $artwork->is_blocked => 'not_public',
        !$artwork->user || $artwork->user->is_blocked => 'owner_unavailable',
        !$media || !str_starts_with((string) $media->mime_type, 'image/') => 'not_image_media',
        !is_file($media->getPath()) => 'missing_original',
        !is_file($media->getPath('thumb')) => 'missing_thumb',
        default => null,
    };
}
$latestRuns = fn () => AiProcessingRun::where('operation', 'analyze_and_index');
function hasHistoricalFallback(Artwork $artwork, iterable $runs): bool
{
    $metadata = $artwork->aiMetadata;
    if (!$metadata?->indexed_at || !$metadata->qdrant_point_id || $metadata->vision_model) {
        return false;
    }
    $matching = [];
    foreach ($runs as $run) {
        if ($run->finished_at && abs($run->finished_at->timestamp - $metadata->indexed_at->timestamp) <= 2) {
            $matching[] = $run;
        }
    }

    return count($matching) === 1 && ($matching[0]->parameters['analysis_source'] ?? null) === 'text_fallback';
}
if ($action === 'audit') {
    $latestIds = $latestRuns()->selectRaw('MAX(id)')->groupBy('artwork_id');
    $runs = AiProcessingRun::whereIn('id', $latestIds)->get()->keyBy('artwork_id');
    $successes = $latestRuns()->where('status', 'success')->get()->groupBy('artwork_id');
    $rows = [];
    foreach ($artworks->orderBy('id')->get() as $row) {
        $run = $runs->get($row->id);
        $rows[] = [
            'id' => $row->id, 'demo' => (bool) $row->is_demo, 'indexing_enabled' => $row->indexingEnabled(),
            'exclusion' => eligibility($row), 'status' => $row->aiMetadata?->status,
            'analysis_source' => $run?->parameters['analysis_source'] ?? null,
            'historical_fallback' => hasHistoricalFallback($row, $successes->get($row->id, [])),
            'has_run' => $run !== null, 'has_saved_point' => filled($row->aiMetadata?->qdrant_point_id),
        ];
    }
    output(['environment' => $environment, 'rows' => $rows]);
    exit;
}

function inspectArtwork($artwork, int $id, bool $demoAllowed, bool $verify, string $action, array $queueState, array $target): array
{
    if (!$artwork) {
        return [
            'id' => $id, 'classification' => 'ineligible', 'reason' => 'missing', 'queue' => $queueState,
            'inspection' => $verify ? app(AiServiceClient::class)->inspectArtworkIndex($id, null) : null,
        ];
    }

    $metadata = $artwork->aiMetadata;
    $media = $artwork->getFirstMedia('artworks');
    $latest = AiProcessingRun::where('artwork_id', $id)->where('operation', 'analyze_and_index')
        ->orderByDesc('id')->first();
    $historicalFallback = $metadata?->indexed_at && !$metadata->vision_model && hasHistoricalFallback(
        $artwork,
        AiProcessingRun::where('artwork_id', $id)->where('operation', 'analyze_and_index')->where('status', 'success')->get(),
    );
    $hash = app(ArtworkIndexingService::class)->contentHash($artwork);
    $reason = eligibility($artwork);
    $needsEnable = $reason === null && !$artwork->indexingEnabled() && $artwork->is_demo && $demoAllowed;
    if ($reason !== null) {
        $classification = 'ineligible';
    } elseif (!$artwork->indexingEnabled() && !$needsEnable) {
        $classification = $artwork->is_demo ? 'approval_required' : 'ineligible';
        $reason = 'AI indexing disabled';
    } elseif (in_array($metadata?->status, ['pending', 'processing'], true)) {
        $classification = 'in_progress';
    } elseif ($metadata?->status === 'indexed'
        && $metadata->content_hash === $hash
        && $metadata->source_media_uuid === $media->uuid
        && $metadata->vision_model === 'qwen3-vl:4b-instruct'
        && $metadata->embedding_model === 'qwen3-embedding:0.6b'
        && $metadata->embedding_dimension === 1024
        && $metadata->metadata_schema_version === config('ai.schema_version')
        && $metadata->prompt_version === config('ai.prompt_version')
        && $latest?->status === 'success'
        && ($latest->parameters['analysis_source'] ?? null) === 'vision'
        && ($latest->parameters['warnings'] ?? []) === []
        && $latest->finished_at && $metadata->indexed_at
        && abs($latest->finished_at->timestamp - $metadata->indexed_at->timestamp) <= 2) {
        $classification = 'vision_success';
    } elseif (($latest?->parameters['analysis_source'] ?? null) === 'text_fallback' || $historicalFallback) {
        $classification = 'text_fallback';
    } elseif ($metadata?->status === 'failed') {
        $classification = 'failed';
    } elseif ($metadata?->status === 'stale' || ($metadata?->status === 'indexed'
        && ($metadata->content_hash !== $hash || ($latest?->parameters['analysis_source'] ?? null) === 'vision'))) {
        $classification = 'revision_mismatch';
    } elseif ($metadata?->status === 'indexed') {
        $classification = 'provenance_unknown';
    } else {
        $classification = 'needs_index';
    }
    if ($needsEnable && $classification === 'vision_success') {
        $classification = 'revision_mismatch';
        $reason = 'Previously indexed artwork currently has AI disabled';
    }

    $inspection = null;
    $pointOk = null;
    if ($classification === 'vision_success' || ($action === 'inspect' && $verify)) {
        $inspection = app(AiServiceClient::class)->inspectArtworkIndex($id, $metadata?->qdrant_point_id);
        $points = $inspection['points'] ?? [];
        $point = $points[0] ?? [];
        $pointOk = count($points) === 1 && !($inspection['has_duplicates'] ?? true)
            && ($inspection['collection'] ?? null) === $target['collection']
            && ($inspection['saved_point'] ?? '') === 'matched'
            && ($inspection['vector_present'] ?? false) === true
            && ($inspection['dimension'] ?? 0) === 1024
            && ($point['artwork_id'] ?? null) === $id
            && ($point['content_hash'] ?? null) === $hash
            && ($point['schema_version'] ?? null) === config('ai.schema_version')
            && ($point['prompt_version'] ?? null) === config('ai.prompt_version')
            && ($point['embedding_model'] ?? $metadata?->embedding_model) === 'qwen3-embedding:0.6b'
            && ($point['is_published'] ?? null) === true && ($point['is_private'] ?? null) === false
            && ($point['is_blocked'] ?? null) === false;
        if (!$pointOk && $classification === 'vision_success') {
            $classification = 'revision_mismatch';
            $reason = 'Qdrant point did not match';
        }
    }

    if ($action === 'enable') {
        if (!$needsEnable || !in_array($classification, ['needs_index', 'failed', 'text_fallback', 'revision_mismatch'], true)
            || array_sum($queueState) !== 0) {
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

    return [
        'id' => $id,
        'classification' => $classification,
        'reason' => $reason,
        'demo' => (bool) $artwork->is_demo,
        'needs_enable' => $needsEnable,
        'queue' => $queueState,
        'content_hash' => $hash,
        'media_uuid' => $media?->uuid,
        'metadata' => $metadata?->only(['status', 'attempts', 'content_hash', 'source_media_uuid', 'qdrant_point_id',
            'embedding_dimension', 'vision_model', 'embedding_model', 'metadata_schema_version', 'prompt_version',
            'summary', 'objects', 'themes', 'tags_ru', 'tags_en']),
        'latest_run' => $latest ? [
            'id' => $latest->id, 'status' => $latest->status, 'latency_ms' => $latest->latency_ms,
            'stage' => $latest->parameters['stage'] ?? null,
            'analysis_source' => $latest->parameters['analysis_source'] ?? null,
            'warnings' => $latest->parameters['warnings'] ?? [],
            'reason' => $latest->parameters['reason'] ?? null, 'content_hash' => $latest->parameters['content_hash'] ?? null,
            'error_present' => filled($latest->error),
        ] : null,
        'inspection' => $inspection,
        'qdrant_revision_matches' => $pointOk,
    ];
}

$request = json_decode(getenv('RECOVERY_REQUEST'), true, flags: JSON_THROW_ON_ERROR);
if (empty($request['ids']) || ($action === 'enable' && (count($request['ids']) !== 1 || !$configOk))) {
    throw new InvalidArgumentException('Invalid inspection or enable request');
}
$rows = $artworks->whereKey($request['ids'])->get()->keyBy('id');
foreach ($request['ids'] as $id) {
    output(inspectArtwork($rows->get($id), $id, in_array($id, $request['approved'], true), $request['verify'], $action, $queueState, $target));
}
