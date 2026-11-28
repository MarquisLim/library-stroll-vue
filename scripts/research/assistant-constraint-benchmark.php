<?php

use App\Contracts\AiServiceClient;
use App\Models\User;
use App\Services\Search\AssistantArtworkSearch;
use App\Services\Search\AssistantCandidateReranker;
use App\Services\Search\AssistantConstraintReranker;
use App\Services\Search\AssistantSearchDiagnostics;
use App\Services\Search\HybridSearchService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $split = $argv[1] ?? '';
    if (! in_array($split, ['development', 'evaluation', 'regression-1c', 'regression-1d1'], true)) {
        throw new InvalidArgumentException('Unknown split');
    }
    $out = '/research';
    $read = static fn ($file) => json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $write = fn ($name, $value) => file_put_contents($out.'/'.$name.'.json',
        json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");
    $freeze = $read($out.'/experiment-freeze.json');
    $implementation = $read($out.'/ranking-freeze.json');
    foreach (['dataset' => __DIR__.'/assistant-constraint-cases.json', 'evidence' => 'app/Services/Search/AssistantConstraintEvidence.php',
        'terms' => 'app/Services/Search/AssistantConstraintTerms.php', 'scorer' => 'app/Services/Search/AssistantConstraintReranker.php',
        'protocol' => __DIR__.'/assistant-constraint-protocol.json', 'runner' => __FILE__] as $key => $path) {
        if (($freeze + $implementation)[$key] !== hash_file('sha256', $path)) {
            throw new RuntimeException('Frozen data changed: '.$key);
        }
    }
    $protocol = $read(__DIR__.'/assistant-constraint-protocol.json');
    $plan = $read(__DIR__.'/assistant-constraint-cases.json');
    $extraction = $read($out.'/extraction-'.$split.'.json');
    $reuseBaselines = in_array('--reuse-baselines', $argv, true);
    $recorded = $reuseBaselines ? collect($read($out.'/first-pass/runs-'.$split.'.json')['rows'])->keyBy('id') : null;
    $replay = str_starts_with($split, 'regression-');
    $priorSplit = $split === 'regression-1c' ? 'regression' : 'evaluation';
    $old = $replay ? $read('/prior/runs-'.$priorSplit.'.json') : null;
    $cases = $replay ? $old['rows'] : $plan[$split];
    if ($split === 'development') {
        $parameters = [];
        foreach ($protocol['depths'] as $depth) {
            foreach ($protocol['strengths'] as $strength) {
                $parameters[] = [$depth, (float) $strength];
            }
        }
    } else {
        $lock = $read($out.'/ranking-lock.json');
        $parameters = [[$lock['depth'], (float) $lock['strength']]];
    }
    DB::statement('SET SESSION TRANSACTION READ ONLY');
    if (config('ai.rerank_enabled') || config('ai.colbert_sync.enabled') || config('ai.colbert_sync.auto_enabled')
        || config('ai.search.lexical_limit') !== 50 || config('ai.search.semantic_limit') !== 50 || config('ai.search.rrf_k') !== 60) {
        throw new RuntimeException('Wrong baseline config');
    }
    config(['ai.enabled' => true, 'ai.assistant_colbert_url' => 'http://library-stroll-constraints-colbert:8001']);
    $user = User::where('is_blocked', false)->orderBy('id')->firstOrFail();
    $search = $app->make(AssistantArtworkSearch::class);
    $hybrid = $app->make(HybridSearchService::class);
    $dense = $app->make(AiServiceClient::class);
    $colbert = $app->make(AssistantCandidateReranker::class);
    $reranker = $app->make(AssistantConstraintReranker::class);
    $visible = fn (Collection $ids) => $search->visible($ids, $user)->pluck('id')->all();
    $lexicalScope = fn (Collection $ids) => collect($visible($ids));
    $loader = fn (Collection $ids) => $search->visible($ids, $user, withMetadata: true);
    $base = static function (string $query, string $mode) use ($dense, $hybrid, $colbert, $lexicalScope, $visible, $loader, $protocol): array {
        $start = hrtime(true);
        $colbertStage = null;
        if ($mode === 'C') {
            $ids = $dense->search($query, 50, ['media_type' => 'image'])->artworkIds();
        } else {
            $ids = $hybrid->search($query, ['type' => 'image'], perPage: 100,
                assistantVisibility: $mode === 'A' ? null : $lexicalScope)['artworks']->pluck('id')->all();
        }
        $ids = $visible(collect($ids));
        if (in_array($mode, ['C', 'D'], true)) {
            $colbertStage = $colbert->rerank($query, $ids, $loader, $protocol['colbert_depth'], $mode === 'D' ? 'post_rrf' : 'dense');
            if ($colbertStage['fallback_reason'] !== null) {
                throw new RuntimeException('Live ColBERT fallback: '.$colbertStage['fallback_reason']);
            }
            $ids = $colbertStage['ids'];
        }

        return ['ids' => $ids, 'ms' => AssistantSearchDiagnostics::ms($start), 'colbert_ms' => $colbertStage['ms'] ?? null];
    };

    $reports = [];
    $pools = [];
    $repeatCount = $split === 'development' ? 1 : $protocol['repeats'];
    if (! $replay && ! $reuseBaselines) {
        foreach (['A', 'B', 'C', 'D'] as $mode) {
            $base('A bird above the water', $mode);
        }
    }
    foreach ($cases as $index => $row) {
        $id = $row['id'];
        $query = $row['query'];
        if ($replay && $split === 'regression-1c') {
            $id = substr($id, 1);
        }
        $parsed = $extraction[$id];
        if ($parsed['query'] !== $query) {
            throw new RuntimeException('Extraction query mismatch');
        }
        if ($reuseBaselines && ($recorded->get($id)['query'] ?? null) !== $query) {
            throw new RuntimeException('Recorded baseline query mismatch');
        }
        $oracle = $row['oracle'] ?? null;
        $labels = $row['labels'] ?? ['full' => $row['full'], 'partial' => $row['part'], 'zero' => $row['zero']];
        $all = [];
        $trials = [];
        $modes = ['A', 'B', 'C', 'D'];
        for ($repeat = 0; $repeat < $repeatCount; $repeat++) {
            $rotation = ($index + $repeat) % 4;
            $ordered = array_merge(array_slice($modes, $rotation), array_slice($modes, 0, $rotation));
            $baseline = [];
            foreach ($ordered as $mode) {
                if ($reuseBaselines) {
                    $baseline[$mode] = $recorded->get($id)['baselines'][$mode][$repeat];
                } elseif ($replay) {
                    $source = ['A' => 'B', 'B' => 'C', 'C' => 'D20', 'D' => 'F20'][$mode];
                    $baseline[$mode] = array_intersect_key($row['runs'][$source][$repeat], ['ids' => true, 'ms' => true]);
                } else {
                    $baseline[$mode] = $base($query, $mode);
                }
                $all[$mode][] = $baseline[$mode];
            }
            foreach ($parameters as [$depth, $strength]) {
                foreach ($replay ? ['e2e' => $parsed['structure']] : ['oracle' => $oracle, 'e2e' => $parsed['structure']] as $method => $structure) {
                    foreach (['E' => 'A', 'F' => 'B', 'G' => 'D'] as $mode => $source) {
                        $input = $baseline[$source]['ids'];
                        $result = $reranker->rerank($query, $structure, $input, $loader, $depth, $strength, $source);
                        if (count($input) !== count($result['ids']) || array_diff($input, $result['ids']) !== []
                            || array_slice($input, $depth) !== array_slice($result['ids'], $depth)) {
                            throw new RuntimeException('Reranker altered its fixed candidate set or tail');
                        }
                        $run = ['ids' => $result['ids'], 'ms' => $result['ms'], 'fallback' => $result['fallback_reason']];
                        if ($repeat === 0 && $split !== 'development') {
                            $run['ranks'] = $result['ranks'];
                            $run['conditions'] = $result['conditions'];
                        }
                        $trials[$method]["$depth/$strength"][$mode][] = $run;
                    }
                }
            }
        }
        $checks = $row['checks'] ?? [];
        $checkIds = collect($checks)->flatMap(fn ($judgments) => array_keys($judgments))->unique()->values()->all();
        $evidence = [];
        if ($checkIds !== []) {
            foreach (['oracle' => $oracle, 'e2e' => $parsed['structure']] as $method => $structure) {
                $evidence[$method] = $reranker->rerank($query, $structure, $checkIds, $loader, 50, 0.5, 'evidence_controls');
            }
        }
        $variants = array_merge(array_values($all), collect($trials)->flatMap(fn ($options) => array_values($options))
            ->flatMap(fn ($orderings) => array_values($orderings))->all());
        $pool = collect($variants)->flatMap(fn ($runs) => array_slice($runs[0]['ids'], 0, $protocol['pool_depth']))
            ->merge($labels['full'])->merge($labels['partial'])->merge($labels['zero'])->merge($checkIds)->unique()->values()->all();
        $pools[$id] = $pool;
        $reports[] = ['id' => $id, 'language' => $row['language'] ?? $row['lang'], 'query' => $query, 'criterion' => $row['criterion'],
            'oracle' => $oracle, 'extraction' => $parsed, 'labels' => $labels, 'checks' => $checks, 'evidence' => $evidence,
            'baselines' => $all, 'trials' => $trials];
        fwrite(STDERR, $split.' '.$id.' '.count($pool)." pairs\n");
    }
    $write('runs-'.$split, ['split' => $split, 'replay' => $replay, 'freeze' => $freeze, 'protocol' => $implementation, 'rows' => $reports]);
    $write('pool-'.$split, $pools);
    if ($split === 'development') {
        exit(0);
    }
    $pairs = [];
    $previous = [];
    if ($replay) {
        foreach ($read('/prior/review-queue-'.$priorSplit.'.json') as $pair) {
            $previous[hash('sha256', json_encode([$pair['query']['text'], $pair['query']['criterion'], $pair['artwork_id']]))] = true;
        }
    }
    foreach ($reports as $row) {
        foreach ($pools[$row['id']] as $artworkId) {
            if (isset($previous[hash('sha256', json_encode([$row['query'], $row['criterion'], $artworkId]))])) {
                continue;
            }
            $pairs[] = ['query_id' => $row['id'], 'artwork_id' => $artworkId,
                'query' => ['text' => $row['query'], 'lang' => $row['language'], 'criterion' => $row['criterion']]];
        }
    }
    if (! is_dir($out.'/review-images')) {
        mkdir($out.'/review-images');
    }
    $hashes = [];
    foreach ($loader(collect(array_column($pairs, 'artwork_id'))->unique()->values()) as $work) {
        $path = $out.'/review-images/'.$work->id.'.jpg';
        if (! is_file($path)) {
            $media = $work->getFirstMedia('artworks');
            $image = imagecreatefromstring(Storage::disk($media->disk)->get($media->getPathRelativeToRoot()));
            $ratio = min(1, 1000 / max(imagesx($image), imagesy($image)));
            $image = imagescale($image, max(1, (int) (imagesx($image) * $ratio)));
            imagejpeg($image, $path, 87);
        }
        $hashes[$work->id] = hash_file('sha256', $path);
    }
    foreach ($pairs as &$pair) {
        $pair['sha256'] = $hashes[$pair['artwork_id']] ?? throw new RuntimeException('An evaluated image is not available');
    }
    unset($pair);
    $pairs = collect($pairs)->sortBy(fn ($pair) => hash('sha256', $pair['query_id'].':'.$pair['artwork_id']))->values()->all();
    $write('review-queue-'.$split, $pairs);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
