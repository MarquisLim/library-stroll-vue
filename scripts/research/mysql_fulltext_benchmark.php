<?php

declare(strict_types=1);

use App\Contracts\AiServiceClient;
use App\Models\Artwork;
use App\Services\Search\ReciprocalRankFusion;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function decode(string $path): array
{
    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

function emit(array $data): void
{
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
}

function targetRank(array $rows, int $target): ?int
{
    $offset = array_search($target, array_column($rows, 'id'), true);

    return $offset === false ? null : $offset + 1;
}

function verifyDense(array $queries, array $plan, array $cohort, int $limit): void
{
    verify(array_column($queries, 'id') === array_column($plan, 'id'), 'Dense query IDs differ from the freeze plan.');
    foreach ($plan as $i => $query) {
        $dense = $queries[$i];
        $ids = array_column($dense['results'], 'id');
        verify($dense['text'] === $query['text'] && $dense['relevant'] === $query['relevant']
            && count($ids) === $limit && count(array_unique($ids)) === $limit
            && array_diff($ids, $cohort) === [], 'Invalid Dense candidates for query '.$query['id']);
    }
}

function freezeDense(array $plan, string $historyPath, array $cohort, int $limit, int $author): array
{
    $history = array_map(
        fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        file($historyPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
    );
    verify(count($history) === 24, 'Expected the 24 original Dense lists.');
    $client = app(AiServiceClient::class);
    $queries = [];
    foreach ($plan as $i => $query) {
        if ($i < 24) {
            $prior = $history[$i];
            verify($prior['id'] === $query['id'] && $prior['lang'] === $query['lang']
                && $prior['text'] === $query['text'] && $prior['relevant'] === $query['relevant'],
                'Original query or target changed.');
            $results = array_slice($prior['results'], 0, $limit);
            $source = '4.4C';
        } else {
            $result = $client->search($query['text'], $limit, ['user_id' => $author]);
            verify($result->model === 'qwen3-embedding:0.6b' && ! $result->reranked, 'Unexpected Dense model or reranking.');
            $results = array_map(fn (array $hit) => ['id' => $hit['artwork_id'], 'score' => $hit['score']], $result->results);
            $source = '4.6A';
        }
        $queries[] = [
            'id' => $query['id'],
            'text' => $query['text'],
            'relevant' => $query['relevant'],
            'source' => $source,
            'results' => array_map(fn (array $hit) => ['id' => $hit['id'], 'score' => $hit['score']], $results),
        ];
    }
    verifyDense($queries, $plan, $cohort, $limit);

    return ['model' => 'qwen3-embedding:0.6b', 'author_id' => $author, 'limit' => $limit, 'queries' => $queries];
}

function summarize(array $queries, string $variant, string $stage): array
{
    $nonempty = $hit1 = $hit5 = 0;
    $reciprocalSum = 0.0;
    $times = [];
    foreach ($queries as $query) {
        $arm = $query['variants'][$variant][$stage];
        $nonempty += (int) ($arm['results'] !== []);
        $rank = $arm['target_rank'];
        $hit1 += (int) ($rank === 1);
        if ($rank !== null && $rank <= 5) {
            $hit5++;
            $reciprocalSum += 1 / $rank;
        }
        array_push($times, ...$arm['latency_ms']);
    }
    sort($times, SORT_NUMERIC);
    $n = count($times);

    return [
        'queries' => count($queries),
        'nonempty' => $nonempty,
        'hit_at_1' => $hit1,
        'hit_at_5' => $hit5,
        'mrr_at_5' => $reciprocalSum / count($queries),
        'samples' => $n,
        'mean_ms' => array_sum($times) / $n,
        'p95_ms' => $times[(int) ceil($n * 0.95) - 1],
    ];
}

function measure(
    array $plan, array $dense, array $catalog, array $columnTypes,
    string $charset, string $collation, int $author, int $limit, int $k, int $rounds,
): array {
    $table = 'research_46a_'.bin2hex(random_bytes(6));
    $titleType = $columnTypes['title'];
    $descriptionType = $columnTypes['description'];
    DB::statement("CREATE TABLE `{$table}` (
        id BIGINT UNSIGNED PRIMARY KEY,
        title {$titleType} NOT NULL,
        description {$descriptionType} NULL,
        likes_count BIGINT UNSIGNED NOT NULL,
        FULLTEXT KEY ft_title (title),
        FULLTEXT KEY ft_title_description (title, description)
    ) ENGINE=InnoDB DEFAULT CHARACTER SET {$charset} COLLATE {$collation}");

    try {
        DB::table($table)->insert($catalog);
        verify(DB::table($table)->count() === count($catalog), 'Incomplete test copy.');
        $columns = ['B' => 'title', 'C' => 'title, description'];
        $variants = ['A', 'B', 'C'];
        $rrf = app(ReciprocalRankFusion::class);
        $all = [];

        foreach ($plan as $i => $query) {
            $text = $query['text'];
            $target = $query['relevant'];
            $semanticIds = array_column($dense['queries'][$i]['results'], 'id');
            $arms = [];
            // The first iteration warms each query. Rotate the measured variant order afterwards.
            for ($round = 0; $round <= $rounds; $round++) {
                for ($offset = 0; $offset < count($variants); $offset++) {
                    $variant = $variants[($i + $round + $offset) % count($variants)];
                    $start = hrtime(true);
                    if ($variant === 'A') {
                        $rows = DB::select("SELECT id, likes_count AS score FROM `{$table}`
                            WHERE title LIKE ? ORDER BY likes_count DESC LIMIT {$limit}", ["%{$text}%"]);
                    } else {
                        $match = "MATCH({$columns[$variant]}) AGAINST (? IN NATURAL LANGUAGE MODE)";
                        $rows = DB::select("SELECT id, {$match} AS score FROM `{$table}`
                            WHERE {$match} > 0 ORDER BY score DESC, id ASC LIMIT {$limit}", [$text, $text]);
                    }
                    $elapsedSql = hrtime(true) - $start;
                    $lexical = array_map(fn (object $row) => ['id' => (int) $row->id, 'score' => (float) $row->score], $rows);
                    $rankedLists = array_filter([array_column($lexical, 'id'), $semanticIds]);
                    $hybrid = $rrf->fuse($rankedLists, $k, $limit + $dense['limit']);
                    $elapsedHybrid = hrtime(true) - $start;

                    if ($round === 0) {
                        $arms[$variant] = [
                            'lexical' => ['results' => $lexical, 'target_rank' => targetRank($lexical, $target), 'latency_ms' => []],
                            'hybrid' => ['results' => $hybrid, 'target_rank' => targetRank($hybrid, $target), 'latency_ms' => []],
                        ];
                    } else {
                        verify($arms[$variant]['lexical']['results'] === $lexical, 'Unstable lexical results for query '.$query['id']);
                        $arms[$variant]['lexical']['latency_ms'][] = $elapsedSql / 1e6;
                        $arms[$variant]['hybrid']['latency_ms'][] = $elapsedHybrid / 1e6;
                    }
                }
            }

            $original = Artwork::publiclyVisible()
                ->where('user_id', $author)
                ->where('title', 'like', "%{$text}%")
                ->withCount('likes')
                ->orderByDesc('likes_count')
                ->limit($limit)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            verify($original === array_column($arms['A']['lexical']['results'], 'id'), 'Copied LIKE order differs from the actual implementation for query '.$query['id']);
            ksort($arms);
            $all[] = ['id' => $query['id'], 'lang' => $query['lang'], 'variants' => $arms];
        }

        $sets = ['original_24' => array_slice($all, 0, 24), 'lexical_8' => array_slice($all, 24)];
        $metrics = [];
        foreach ($sets as $set => $queries) {
            foreach (['all', 'ru', 'en'] as $lang) {
                $group = $lang === 'all' ? $queries : array_filter($queries, fn (array $query) => $query['lang'] === $lang);
                foreach ($variants as $variant) {
                    foreach (['lexical', 'hybrid'] as $stage) {
                        $metrics[$set][$lang][$variant][$stage] = summarize($group, $variant, $stage);
                    }
                }
            }
        }

        return ['metrics' => $metrics, 'queries' => $all];
    } finally {
        DB::statement("DROP TABLE `{$table}`");
    }
}

[, $mode, $planPath, $inputPath] = $argv + [null, null, null, null];
verify(in_array($mode, ['freeze-dense', 'measure'], true) && $planPath !== null && $inputPath !== null,
    'Usage: php mysql_fulltext_benchmark.php freeze-dense|measure QUERY_PLAN.json DENSE_INPUT');

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

verify(config('database.default') === 'mysql'
    && config('database.connections.mysql.host') === 'mysql'
    && DB::selectOne('SELECT DATABASE() AS name')->name === 'library_stroll_44_dataset'
    && ! config('ai.rerank_enabled'), 'Expected isolated MySQL with ColBERT disabled.');
$author = 1;
$lexicalLimit = config('ai.search.lexical_limit');
$semanticLimit = config('ai.search.semantic_limit');
$k = config('ai.search.rrf_k');
verify($lexicalLimit === 50 && $semanticLimit === 50 && $k === 60, 'Unexpected candidate limits or RRF constant.');
$catalog = Artwork::publiclyVisible()->where('user_id', $author)->orderBy('id')
    ->select('id', 'title', 'description')->withCount('likes')->get()->map(fn (Artwork $artwork) => [
        'id' => (int) $artwork->id,
        'title' => $artwork->getRawOriginal('title'),
        'description' => $artwork->getRawOriginal('description'),
        'likes_count' => (int) $artwork->likes_count,
    ])->all();
$cohort = array_column($catalog, 'id');
verify($cohort === range(441001, 441100), 'Expected the saved public 100-work corpus only.');
$plan = decode($planPath);
verify(array_column($plan, 'id') === range(1, 32) && array_diff(array_column($plan, 'relevant'), $cohort) === [],
    'Unexpected query IDs or target works.');
foreach ([array_slice($plan, 0, 24), array_slice($plan, 24)] as $queries) {
    $languages = array_count_values(array_column($queries, 'lang'));
    verify($languages === ['ru' => count($queries) / 2, 'en' => count($queries) / 2], 'Unbalanced query languages.');
}

if ($mode === 'freeze-dense') {
    verify(config('ai.enabled') && config('ai.service_url') === 'http://ai-api:8001', 'Unexpected Dense API.');
    DB::statement('SET SESSION TRANSACTION READ ONLY');
    emit(freezeDense($plan, $inputPath, $cohort, $semanticLimit, $author));
    exit;
}

$dense = decode($inputPath);
verify($dense['model'] === 'qwen3-embedding:0.6b' && $dense['author_id'] === $author && $dense['limit'] === $semanticLimit,
    'Unexpected frozen Dense metadata.');
verifyDense($dense['queries'], $plan, $cohort, $semanticLimit);
$columns = DB::select("SELECT column_name AS name, column_type AS type, character_set_name AS charset, collation_name AS collation
    FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'artworks' AND column_name IN ('title', 'description')");
$charsets = array_unique(array_column($columns, 'charset'));
$collations = array_unique(array_column($columns, 'collation'));
$columnTypes = array_column($columns, 'type', 'name');
verify(count($columns) === 2 && count($charsets) === 1 && count($collations) === 1, 'Text columns need the same charset and collation.');
[$charset] = $charsets;
[$collation] = $collations;
verify(preg_match('/^[a-z0-9_]+$/iD', $charset) === 1 && preg_match('/^[a-z0-9_]+$/iD', $collation) === 1
    && preg_match('/^varchar\([0-9]+\)$/iD', $columnTypes['title']) === 1
    && preg_match('/^(?:tiny|medium|long)?text$/iD', $columnTypes['description']) === 1, 'Unexpected corpus text schema.');
$server = DB::selectOne('SELECT VERSION() AS version, @@innodb_ft_min_token_size AS min_token_size,
    @@innodb_ft_max_token_size AS max_token_size, @@innodb_ft_enable_stopword AS stopwords_enabled,
    @@innodb_ft_server_stopword_table AS server_stopword_table, @@innodb_ft_user_stopword_table AS user_stopword_table');
$rounds = 20;
$report = measure($plan, $dense, $catalog, $columnTypes, $charset, $collation, $author, $lexicalLimit, $k, $rounds);
emit([
    'settings' => [
        'server' => $server, 'charset' => $charset, 'collation' => $collation, 'corpus_size' => count($catalog),
        'cyrillic_title_count' => count(array_filter($catalog, fn ($row) => preg_match('/\p{Cyrillic}/u', $row['title']))),
        'cyrillic_description_count' => count(array_filter($catalog, fn ($row) => preg_match('/\p{Cyrillic}/u', $row['description'] ?? ''))),
        'rounds' => $rounds, 'lexical_limit' => $lexicalLimit, 'semantic_limit' => $semanticLimit, 'rrf_k' => $k,
        'plan_sha256' => hash_file('sha256', $planPath), 'dense_sha256' => hash_file('sha256', $inputPath),
    ],
    'titles' => array_column($catalog, 'title', 'id'),
    ...$report,
]);
