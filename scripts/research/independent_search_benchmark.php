<?php

declare(strict_types=1);

use App\Contracts\AiServiceClient;
use App\Models\Artwork;
use App\Services\Search\HybridSearchService;
use App\Services\Search\ReciprocalRankFusion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function readJson(string $file): array
{
    return json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
}

[, $planPath, $warmupPath] = $argv + [null, null, null];
verify($planPath !== null && $warmupPath !== null, 'Usage: php independent_search_benchmark.php QUERY_PLAN WARMUP_PLAN');
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

final class ObservedFusion extends ReciprocalRankFusion
{
    public array $inputs = [];

    public array $output = [];

    public function fuse(array $rankedLists, int $k = 60, int $limit = 20): array
    {
        $this->inputs = $rankedLists;
        $this->output = parent::fuse($rankedLists, $k, $limit);

        return $this->output;
    }
}

function rowsWithSource(array $rows, string $source): array
{
    return array_map(fn (array $row) => ['id' => $row['artwork_id'], 'score' => $row['score'], 'score_source' => $source], $rows);
}

function isCandidatePermutation(array $reranked, array $head): bool
{
    if (count($reranked) !== count($head) || array_column($reranked, 'rank') !== range(1, count($head))) {
        return false;
    }

    $ids = array_column($reranked, 'artwork_id');
    sort($ids);
    sort($head);

    return $ids === $head;
}

function executeArm(
    string $arm, string $text, int $author, AiServiceClient $client, HybridSearchService $hybrid,
    ObservedFusion $fusion, array &$trace,
): array {
    $trace = [];
    $fusion->inputs = $fusion->output = [];
    config(['ai.rerank_enabled' => $arm === 'D']);
    $error = null;
    $begin = hrtime(true);
    if ($arm === 'A' || $arm === 'B') {
        $dense = $client->search($text, 50, ['user_id' => $author]);
        $results = rowsWithSource($dense->results, 'dense');
        if ($arm === 'B') {
            $head = array_slice($dense->artworkIds(), 0, 30);
            try {
                $reranked = $client->rerank($text, $head, count($head));
                verify($reranked->reranked && isCandidatePermutation($reranked->results, $head), 'Rerank response is not a candidate permutation.');
                $results = [...rowsWithSource($reranked->results, 'colbert'), ...array_slice($results, 30)];
            } catch (Throwable $e) {
                $error = ['stage' => 'colbert', 'kind' => $e::class, 'fallback' => 'dense'];
            }
        }
        $semantic = array_column($results, 'id');
        $lexical = [];
        $elapsed = (hrtime(true) - $begin) / 1e6;
    } else {
        $output = $hybrid->search($text, ['author_id' => $author], perPage: 100);
        $elapsed = (hrtime(true) - $begin) / 1e6;
        $ids = $output['artworks']->pluck('id')->all();
        verify(count($ids) === $output['total'], 'Benchmark limit unexpectedly truncated Hybrid results.');
        $scores = array_column($fusion->output, 'score', 'id');
        $results = array_map(fn (int $id) => ['id' => $id, 'score' => $scores[$id], 'score_source' => 'rrf'], $ids);
        $inputs = $fusion->inputs;
        $semantic = array_column(array_pop($inputs) ?? [], 'id');
        $lexical = array_column($inputs[0] ?? [], 'id');
        if ($arm === 'D' && isset($trace['fallback'])) {
            $error = ['stage' => 'colbert', 'kind' => $trace['fallback'], 'fallback' => 'dense'];
        }
    }

    $denseTrace = $trace['dense'] ?? [];
    verify(($denseTrace['status'] ?? null) === 200 && $denseTrace['model'] === 'qwen3-embedding:0.6b'
        && $denseTrace['filter'] === ['user_id' => $author] && $denseTrace['limit'] === 50
        && count($denseTrace['results']) === 50, "Invalid Dense response in {$arm}: {$text}");
    $hasRerank = $arm === 'B' || $arm === 'D';
    $colbertTrace = $trace['colbert'] ?? [];
    verify($hasRerank === ($colbertTrace !== []), "Unexpected ColBERT call in {$arm}: {$text}");
    if ($hasRerank) {
        verify($colbertTrace['candidates'] === array_slice(array_column($denseTrace['results'], 'artwork_id'), 0, 30)
            && $colbertTrace['limit'] === 30 && $colbertTrace['attempts'] === 1, "Unexpected ColBERT candidates or retry in {$arm}");
        if ($error === null) {
            verify(($colbertTrace['status'] ?? null) === 200 && $colbertTrace['model'] === 'jinaai/jina-colbert-v2',
                "Unexpected ColBERT service in {$arm}: {$text}");
        }
        $colbertTrace['elapsed_ms'] ??= (hrtime(true) - $colbertTrace['started']) / 1e6;
        unset($colbertTrace['started'], $colbertTrace['candidates'], $colbertTrace['results']);
    }

    return [
        'results' => $results, 'lexical_ids' => $lexical, 'semantic_ids' => $semantic, 'dense_results' => $denseTrace['results'],
        'elapsed_ms' => $elapsed, 'dense_ms' => $denseTrace['elapsed_ms'], 'colbert' => $colbertTrace, 'error' => $error,
    ];
}

verify(config('database.default') === 'mysql' && config('database.connections.mysql.host') === 'mysql'
    && DB::selectOne('SELECT DATABASE() AS name')->name === 'library_stroll_44_dataset'
    && config('ai.enabled') === true && config('ai.service_url') === 'http://ai-api:8001'
    && config('ai.colbert_url') === 'http://colbert:8001' && config('ai.colbert_timeout') === 0.5
    && config('ai.search.lexical_limit') === 50 && config('ai.search.semantic_limit') === 50 && config('ai.search.rrf_k') === 60,
    'Unexpected isolated environment or search settings.');
DB::statement('SET SESSION TRANSACTION READ ONLY');
$author = 1;
$corpus = Artwork::publiclyVisible()->where('user_id', $author)->orderBy('id')->pluck('id')->all();
verify($corpus === range(441001, 441100), 'Expected all 100 publicly visible artworks.');
$plan = readJson($planPath);
$warmup = array_slice(readJson($warmupPath), 0, 2);
verify(array_column($plan, 'id') === range(1, 40) && count($warmup) === 2, 'Unexpected query plans.');

$endpoints = [config('ai.service_url').'/api/v1/search' => 'dense', config('ai.colbert_url').'/api/v1/rerank' => 'colbert'];
$trace = [];
Event::listen(RequestSending::class, function (RequestSending $event) use (&$trace, $endpoints): void {
    $key = $endpoints[$event->request->url()] ?? null;
    if ($key !== null) {
        $trace[$key] = [
            'started' => hrtime(true), 'attempts' => ($trace[$key]['attempts'] ?? 0) + 1,
            'limit' => $event->request['limit'],
            'filter' => (array) ($event->request['filters'] ?? []),
            'candidates' => $event->request['artwork_ids'] ?? [],
        ];
    }
});
Event::listen(ResponseReceived::class, function (ResponseReceived $event) use (&$trace, $endpoints): void {
    $key = $endpoints[$event->request->url()] ?? null;
    if ($key !== null) {
        $elapsed = (hrtime(true) - $trace[$key]['started']) / 1e6;
        $body = $event->response->json();
        $trace[$key] += [
            'status' => $event->response->status(), 'elapsed_ms' => $elapsed, 'model' => $body['model'] ?? null,
            'server_ms' => $body['latency_ms'] ?? null, 'reranked' => $body['reranked'] ?? false, 'results' => $body['results'] ?? [],
        ];
    }
});
Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$trace): void {
    if ($event->message === 'ColBERT reranking failed. Using Dense order.') {
        $trace['fallback'] = $event->context['reason'];
    }
});

$fusion = new ObservedFusion;
$app->instance(ReciprocalRankFusion::class, $fusion);
$hybrid = $app->make(HybridSearchService::class);
$client = app(AiServiceClient::class);
$variants = ['A', 'B', 'C', 'D'];
$startWarmup = hrtime(true);
foreach ($warmup as $query) {
    foreach ($variants as $arm) {
        $warm = executeArm($arm, $query['text'], $author, $client, $hybrid, $fusion, $trace);
        verify($warm['error'] === null, 'Warmup could not reach the ColBERT model.');
    }
}
$warmupMs = (hrtime(true) - $startWarmup) / 1e6;
$output = [];
foreach ($plan as $i => $query) {
    $runs = [];
    for ($offset = 0; $offset < 4; $offset++) {
        $arm = $variants[($i + $offset) % 4];
        $runs[$arm] = executeArm($arm, $query['text'], $author, $client, $hybrid, $fusion, $trace);
    }
    $dense = $runs['A']['dense_results'];
    $denseIds = array_column($dense, 'artwork_id');
    verify(count(array_unique($denseIds)) === 50, 'Dense returned duplicate candidates.');
    foreach ($runs as $arm => &$measurement) {
        $rows = $measurement['dense_results'];
        verify(array_column($rows, 'artwork_id') === $denseIds, "Dense candidate order differs in query {$query['id']} arm {$arm}");
        foreach ($rows as $j => $row) {
            verify(abs($row['score'] - $dense[$j]['score']) <= 1e-6, 'Dense score differs between configurations.');
        }
        $ids = array_column($measurement['results'], 'id');
        verify(count(array_unique($ids)) === count($ids) && array_diff($ids, $corpus) === [], 'Rankings contain invisible or duplicate results.');
        $measurement['results'] = array_map(fn (array $item, int $index) => ['rank' => $index + 1, ...$item], $measurement['results'], array_keys($measurement['results']));
        unset($measurement['dense_results']);
    }
    unset($measurement);

    verify($runs['C']['semantic_ids'] === $denseIds && $runs['C']['lexical_ids'] === $runs['D']['lexical_ids']
        && array_slice($runs['B']['semantic_ids'], 30) === array_slice($denseIds, 30), 'A controlled search input changed.');
    if ($runs['B']['error'] === null && $runs['D']['error'] === null) {
        verify($runs['B']['semantic_ids'] === $runs['D']['semantic_ids'], 'ColBERT rankings differ between B and D before RRF.');
    }
    ksort($runs);
    $output[] = ['id' => $query['id'], 'lang' => $query['lang'], 'category' => $query['category'], 'methods' => $runs];
}

echo json_encode([
    'config' => [
        'author_id' => $author, 'semantic_limit' => 50, 'lexical_limit' => 50, 'rerank_limit' => 30, 'rrf_k' => 60,
        'max_results' => 100, 'colbert_timeout_seconds' => 0.5, 'embedding_model' => 'qwen3-embedding:0.6b',
        'colbert_model' => 'jinaai/jina-colbert-v2', 'plan_sha256' => hash_file('sha256', $planPath),
        'warmup_plan_sha256' => hash_file('sha256', $warmupPath), 'warmup_queries' => array_column($warmup, 'id'), 'warmup_ms' => $warmupMs,
    ],
    'queries' => $output,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
