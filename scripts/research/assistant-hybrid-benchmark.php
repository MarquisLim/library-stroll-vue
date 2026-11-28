<?php

use App\Contracts\AiServiceClient;
use App\Models\Artwork;
use App\Models\User;
use App\Services\Search\AssistantArtworkSearch;
use App\Services\Search\AssistantSearchDiagnostics;
use App\Services\Search\HybridSearchService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$split = $argv[1] ?? '';
if (! in_array($split, ['development', 'evaluation'], true)) {
    throw new InvalidArgumentException('Specify development or evaluation');
}
$output = '/research';
$datasetPath = __DIR__.'/assistant-hybrid-cases.json';
$dataset = json_decode(file_get_contents($datasetPath), true, flags: JSON_THROW_ON_ERROR);
$freeze = json_decode(file_get_contents($output.'/dataset-freeze.json'), true, flags: JSON_THROW_ON_ERROR);
if (! hash_equals($freeze['sha256'], hash_file('sha256', $datasetPath))) {
    throw new RuntimeException('Frozen query plan changed');
}
if (config('ai.rerank_enabled') || config('ai.colbert_sync.enabled') || config('ai.colbert_sync.auto_enabled')) {
    throw new RuntimeException('ColBERT must be disabled for this experiment');
}
DB::statement('SET SESSION TRANSACTION READ ONLY');
$user = User::query()->where('is_blocked', false)->orderBy('id')->firstOrFail();
if (! AssistantSearchDiagnostics::allowed($user) || config('ai.search.lexical_limit') !== 50 || config('ai.search.semantic_limit') !== 50) {
    throw new RuntimeException('Local diagnostics and the fixed candidate limit are required');
}

$write = static function (string $name, array $value) use ($output): void {
    file_put_contents($output.'/'.$name.'.json', json_encode($value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
};
$indexes = DB::select('SELECT table_name, index_name, seq_in_index, column_name, index_type FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name IN (?, ?, ?, ?) ORDER BY table_name, index_name, seq_in_index',
    ['artworks', 'artwork_ai_metadata', 'artwork_tag', 'tags']);
$like = Artwork::publiclyVisible()->where('type', 'image')->where('title', 'like', '%Тарелка.%')->withCount('likes')->orderByDesc('likes_count')->limit(50);
$write('mysql-audit', ['server' => (array) DB::selectOne('SELECT VERSION() AS version, @@collation_database AS collation, @@innodb_ft_min_token_size AS ft_min_token_size'),
    'indexes' => $indexes, 'title_like_explain' => DB::select('EXPLAIN '.$like->toSql(), $like->getBindings())]);

$search = $app->make(AssistantArtworkSearch::class);
$hybrid = $app->make(HybridSearchService::class);
$dense = $app->make(AiServiceClient::class);
$admissible = fn (Collection $ids) => $search->visible($ids, $user)->pluck('id');

$run = static function (string $query, string $mode, bool $capture) use ($app, $user, $search, $hybrid, $dense, $admissible): array {
    config(['ai.enabled' => $mode !== 'C', 'ai.assistant_lexical_metadata_enabled' => in_array($mode, ['C', 'D'], true)]);
    $request = Request::create('/assistant/search', 'POST', server: ['HTTP_X_ASSISTANT_DEBUG' => '1']);
    $app->instance('request', $request);
    $request->setUserResolver(fn () => $user);
    $trace = AssistantSearchDiagnostics::begin($request, $query);
    if ($trace === null) {
        throw new RuntimeException('Could not start local diagnostics');
    }
    $started = hrtime(true);
    if ($mode === 'A') {
        $trace->used($query);
        $denseStart = hrtime(true);
        $result = $dense->search($query, 50, ['media_type' => 'image']);
        $trace->stage('dense', $denseStart, count($result->results), ['limit' => 50, 'service_ms' => $result->latencyMs]);
        $trace->model('dense', $result->model);
        foreach ($result->results as $item) {
            $trace->candidate($item['artwork_id'], ['dense_rank' => $item['rank'], 'dense_score' => $item['score']]);
        }
        $ids = collect($result->artworkIds());
    } else {
        $result = $hybrid->search($query, ['type' => 'image'], page: 1, perPage: 100,
            assistantVisibility: in_array($mode, ['C', 'D'], true) ? $admissible : null);
        $ids = $result['artworks']->pluck('id');
    }
    $checking = hrtime(true);
    $works = $search->visible($ids, $user)->take(50);
    $rows = $works->map(AssistantArtworkSearch::card(...))->all();
    $ms = AssistantSearchDiagnostics::ms($started);
    $trace->stage('assistant_visibility', $checking, $works->count(), ['input_count' => $ids->count()]);
    foreach ($works as $work) {
        $trace->candidate($work->id, ['retrieved' => true]);
    }
    $trace->data['benchmark_mode'] = $mode;
    if ($trace->data['errors'] !== []) {
        throw new RuntimeException('Unexpected retrieval error for '.$mode);
    }
    $diagnostics = $capture ? $trace->finish($user, $rows === [] ? 'no_results' : 'ok', $rows, $search) : null;

    return ['mode' => $mode, 'ms' => $ms, 'components' => $trace->data['stages'],
        'ids' => $works->pluck('id')->all(), 'diagnostics' => $diagnostics];
};

foreach (['A', 'B', 'C', 'D'] as $mode) {
    $run('A picture of a bird', $mode, false);
}

$rows = [];
$pools = [];
foreach ($dataset[$split] as $index => [$id, $language, $category, $query, $criterion, $seeds]) {
    $modes = ['A', 'B', 'C', 'D'];
    $runs = [];
    $poolIds = collect($seeds);
    for ($repeat = 0; $repeat < 3; $repeat++) {
        $rotation = ($index + $repeat) % 4;
        foreach ([...array_slice($modes, $rotation), ...array_slice($modes, 0, $rotation)] as $mode) {
            $result = $run($query, $mode, $repeat === 0);
            if ($repeat !== 0 && $runs[$mode][0]['ids'] !== $result['ids']) {
                throw new RuntimeException('Candidate repeat diverged for '.$id.' '.$mode);
            }
            $runs[$mode][] = $result;
            $poolIds = $poolIds->concat(array_slice($result['ids'], 0, 15));
        }
    }
    $pool = $poolIds->unique()->sortBy(fn ($artwork) => hash('sha256', $id.':'.$artwork))->values();
    $pools[$id] = $pool->all();
    $rows[] = ['id' => $id, 'language' => $language, 'category' => $category, 'query' => $query,
        'criterion' => $criterion, 'runs' => $runs];
    fwrite(STDERR, $id.' '.$pool->count()."\n");
}
$write('runs-'.$split, ['dataset_sha256' => $freeze['sha256'], 'rows' => $rows]);
$write('pool-'.$split, $pools);

if ($split !== 'evaluation') {
    exit;
}
$allIds = collect($pools)->flatten()->unique();
$images = $search->visible($allIds, $user)->keyBy('id');
$tiles = [];
$width = 143;
$height = 119;
foreach ($images as $id => $artwork) {
    $media = $artwork->getFirstMedia('artworks');
    $source = @imagecreatefromstring(Storage::disk($media->disk)->get($media->getPathRelativeToRoot()));
    if ($source === false) {
        continue;
    }
    $ratio = min(($width - 10) / imagesx($source), ($height - 22) / imagesy($source));
    $tiles[$id] = imagescale($source, max(1, (int) (imagesx($source) * $ratio)), max(1, (int) (imagesy($source) * $ratio)));
}
foreach ($pools as $id => $artworkIds) {
    $cols = 8;
    $canvas = imagecreatetruecolor($cols * $width, max(1, (int) ceil(count($artworkIds) / $cols)) * $height);
    imagefill($canvas, 0, 0, 0xffffff);
    foreach ($artworkIds as $position => $artworkId) {
        $x = $position % $cols * $width;
        $y = intdiv($position, $cols) * $height;
        imagefilledrectangle($canvas, $x + 2, $y + 2, $x + $width - 2, $y + $height - 2, 0xeeeeee);
        imagestring($canvas, 4, $x + 6, $y + 3, '#'.$artworkId, 0x111111);
        if (isset($tiles[$artworkId])) {
            $tile = $tiles[$artworkId];
            imagecopy($canvas, $tile, $x + intdiv($width - imagesx($tile), 2), $y + 19, 0, 0, imagesx($tile), imagesy($tile));
        }
    }
    imagepng($canvas, $output.'/pool-'.$id.'.png');
}
