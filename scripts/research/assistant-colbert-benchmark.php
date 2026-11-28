<?php

use App\Contracts\AiServiceClient;
use App\Models\User;
use App\Services\Search\AssistantArtworkSearch;
use App\Services\Search\AssistantCandidateReranker;
use App\Services\Search\AssistantSearchDiagnostics;
use App\Services\Search\HybridSearchService;
use App\Services\Search\ReciprocalRankFusion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$out = '/research';
$split = $argv[1] ?? '';
if (! in_array($split, ['development', 'evaluation', 'regression'], true)) {
    throw new InvalidArgumentException('Specify development, evaluation or regression');
}
$read = fn ($path) => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$write = fn ($name, $value) => file_put_contents($out.'/'.$name.'.json',
    json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
$freeze = $read($out.'/experiment-freeze.json');
$paths = ['dataset' => __DIR__.'/assistant-colbert-cases.json', 'protocol' => __DIR__.'/assistant-colbert-protocol.json',
    'regression' => __DIR__.'/assistant-hybrid-cases.json', 'regression_labels' => __DIR__.'/assistant-hybrid-judgments.json',
    'runner' => __FILE__, 'documents' => $out.'/documents.json'];
foreach ($paths as $key => $path) {
    if (! hash_equals($freeze[$key], hash_file('sha256', $path))) {
        throw new RuntimeException("Frozen input changed: $key");
    }
}
$protocol = $read($paths['protocol']);
$depths = $split === 'development' ? $protocol['rerank_depths'] : [$read($out.'/depth-lock.json')['depth']];
if (array_diff($depths, $protocol['rerank_depths']) || config('ai.rerank_enabled') || config('ai.colbert_sync.enabled')
    || config('ai.colbert_sync.auto_enabled') || config('ai.search.rrf_k') !== $protocol['rrf_k']
    || config('ai.search.semantic_limit') !== $protocol['dense_limit'] || config('ai.search.lexical_limit') !== $protocol['lexical_limit']) {
    throw new RuntimeException('Unexpected experimental configuration');
}
config(['ai.enabled' => true, 'ai.assistant_colbert_url' => 'http://library-stroll-colbert-541d:8001']);
DB::statement('SET SESSION TRANSACTION READ ONLY');
$user = User::where('is_blocked', false)->orderBy('id')->firstOrFail();
$search = $app->make(AssistantArtworkSearch::class);
$hybrid = $app->make(HybridSearchService::class);
$dense = $app->make(AiServiceClient::class);
$rerank = $app->make(AssistantCandidateReranker::class);
$rrf = $app->make(ReciprocalRankFusion::class);
$canUse = fn (Collection $ids) => $search->visible($ids, $user)->pluck('id');
$load = fn (Collection $ids) => $search->visible($ids, $user, withMetadata: true);

$ordered = static function (array $trace, string $rank): array {
    $ids = collect($trace['candidates'])->whereNotNull($rank)->sortBy($rank)->pluck('artwork_id')->values()->all();
    return $ids;
};
$baseline = static function (string $query, string $mode) use ($app, $user, $search, $hybrid, $dense, $canUse, $ordered): array {
    $request = Request::create('/assistant/search', 'POST', server: ['HTTP_X_ASSISTANT_DEBUG' => '1']);
    $app->instance('request', $request);
    $request->setUserResolver(fn () => $user);
    $trace = AssistantSearchDiagnostics::begin($request, $query);
    if ($trace === null) {
        throw new RuntimeException('Local diagnostics are required');
    }
    $start = hrtime(true);
    if ($mode === 'A') {
        $result = $dense->search($query, 50, ['media_type' => 'image']);
        $raw = $result->artworkIds();
        $lexical = [];
    } else {
        $result = $hybrid->search($query, ['type' => 'image'], perPage: 100, assistantVisibility: $mode === 'C' ? $canUse : null);
        $raw = $result['artworks']->pluck('id')->all();
    }
    $ids = $canUse(collect($raw))->values()->all();
    $ms = AssistantSearchDiagnostics::ms($start);
    if ($mode !== 'A') {
        $snap = $trace->finish($user, 'ok', [], $search);
        $lexical = $ordered($snap, 'lexical_rank');
        if ($snap['errors'] !== [] || count($ids) !== count($raw) || $raw !== $ordered($snap, 'rrf_rank')) {
            throw new RuntimeException('Baseline trace and live order differ');
        }
    }
    return ['ids' => $ids, 'raw' => $raw, 'lexical' => $lexical, 'ms' => $ms,
        'dense' => $mode === 'A' ? $raw : $ordered($snap, 'dense_rank')];
};

$cases = $split === 'regression'
    ? array_map(fn ($row) => array_pad($row, 8, []), $read($paths['regression'])['evaluation'])
    : $read($paths['dataset'])[$split];
$previous = $split === 'regression' ? $read($paths['regression_labels']) : [];
$rows = [];
$pool = [];
$repeatCount = $split === 'development' ? 1 : $protocol['repeats'];
foreach ($cases as $offset => [$id, $lang, $category, $query, $criterion, $full, $part, $zero]) {
    if ($split === 'regression') {
        $seeds = $full;
        ['full' => $full, 'partial' => $part] = $previous[$id];
        $zero = [];
        $id = 'G'.$id;
    } else {
        $seeds = array_merge($full, $part, $zero);
    }
    $modes = ['A', 'B', 'C'];
    $runs = [];
    for ($repeat = 0; $repeat < $repeatCount; $repeat++) {
        $rotation = ($offset + $repeat) % 3;
        $base = [];
        foreach (array_merge(array_slice($modes, $rotation), array_slice($modes, 0, $rotation)) as $mode) {
            $base[$mode] = $baseline($query, $mode);
            $runs[$mode][] = $base[$mode];
        }
        if ($base['A']['dense'] !== $base['B']['dense'] || $base['A']['dense'] !== $base['C']['dense']) {
            throw new RuntimeException('Dense rank changed between strategies');
        }
        if (array_column($rrf->fuse([$base['C']['lexical'], $base['A']['dense']], 60, 100), 'id') !== $base['C']['raw']) {
            throw new RuntimeException('Paired RRF inputs no longer reproduce C');
        }
        foreach ($depths as $depth) {
            $hybridFirst = ($offset + $repeat) % 2 !== 0;
            $reranked = [];
            foreach ($hybridFirst ? ['F', 'D'] : ['D', 'F'] as $mode) {
                $input = $base[$mode === 'F' ? 'C' : 'A']['raw'];
                $result = $rerank->rerank($query, $input, $load, $depth, $mode === 'F' ? 'post_rrf' : 'dense');
                $reranked[$mode] = $result;
                $ids = $result['ids'];
                if (count($ids) !== count($input) || array_diff($ids, $input) !== [] || count(array_unique($ids)) !== count($input)) {
                    throw new RuntimeException('Reranking modified the candidate set');
                }
                $runs[$mode.$depth][] = ['ids' => $ids, 'input' => $input, 'rerank' => $result,
                    'ms' => $base[$mode === 'F' ? 'C' : 'A']['ms'] + $result['ms']];
            }
            $start = hrtime(true);
            $ids = array_column($rrf->fuse([$base['C']['lexical'], $reranked['D']['ids']], 60, 100), 'id');
            if (array_diff($ids, $base['C']['ids']) !== [] || count($ids) !== count($base['C']['ids'])) {
                throw new RuntimeException('E and C must have the same candidate set');
            }
            $runs['E'.$depth][] = ['ids' => $ids, 'input' => $base['C']['ids'], 'rerank' => $reranked['D'],
                'ms' => $base['C']['ms'] + $reranked['D']['ms'] + AssistantSearchDiagnostics::ms($start)];
        }
    }
    foreach ($runs as $mode => $repeats) {
        foreach ($repeats as $run) {
            if ($run['ids'] !== $repeats[0]['ids']) {
                throw new RuntimeException("Repeat diverged: $id $mode");
            }
        }
    }
    $rows[] = compact('id', 'lang', 'category', 'query', 'criterion', 'full', 'part', 'zero', 'runs');
    $ids = collect($runs)->flatMap(fn ($runs) => array_slice($runs[0]['ids'], 0, $protocol['review_depth']))->merge($seeds);
    $pool[$id] = $ids->unique()->values()->all();
    fwrite(STDERR, $split.' '.$id.' '.$ids->unique()->count()." pairs\n");
}
$write('runs-'.$split, ['freeze' => $freeze, 'depths' => $depths, 'rows' => $rows]);
$write('pool-'.$split, $pool);
if ($split === 'development') {
    exit;
}

$imageDir = $out.'/review-images';
if (! is_dir($imageDir)) {
    mkdir($imageDir);
}
$imageHashes = [];
foreach ($load(collect($pool)->flatten()->unique()->values()) as $artwork) {
    $path = $imageDir.'/'.$artwork->id.'.jpg';
    if (! is_file($path)) {
        $media = $artwork->getFirstMedia('artworks');
        $original = Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
        $image = @imagecreatefromstring($original);
        if ($image === false) {
            throw new RuntimeException('Unsupported source image '.$artwork->id);
        }
        $ratio = min(1000 / max(imagesx($image), imagesy($image)), 1);
        $thumb = imagescale($image, max(1, (int) round(imagesx($image) * $ratio)));
        imagejpeg($thumb, $path, 87);
    }
    $imageHashes[$artwork->id] = hash_file('sha256', $path);
}
$queue = collect($rows)->flatMap(fn ($row) => collect($pool[$row['id']])->map(fn ($id) => [
    'query_id' => $row['id'], 'artwork_id' => $id, 'query' => ['text' => $row['query'], 'lang' => $row['lang'], 'criterion' => $row['criterion']],
    'sha256' => $imageHashes[$id] ?? throw new RuntimeException('A pooled work became unavailable'),
]))->sortBy(fn ($pair) => hash('sha256', $pair['query_id'].':'.$pair['artwork_id']))->values()->all();
$write('review-queue-'.$split, $queue);
