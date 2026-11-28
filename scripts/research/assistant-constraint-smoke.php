<?php

use App\Models\User;
use App\Services\AiService\AssistantRagContext;
use App\Services\Search\AssistantArtworkSearch;
use App\Services\Search\AssistantSearchDiagnostics;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
    $read = fn ($name) => json_decode(file_get_contents('/research/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
    $lock = $read('ranking-lock');
    $runs = $read('runs-evaluation');
    $search = $app->make(AssistantArtworkSearch::class);
    $context = $app->make(AssistantRagContext::class);
    $user = User::where('is_blocked', false)->orderBy('id')->firstOrFail();
    config(['ai.enabled' => true, 'ai.assistant_constraint_depth' => $lock['depth'], 'ai.assistant_constraint_strength' => $lock['strength'],
        'ai.assistant_colbert_depth' => 20, 'ai.assistant_colbert_url' => 'http://library-stroll-constraints-colbert:8001']);
    $results = [];
    foreach (collect($runs['rows'])->whereIn('id', ['R04', 'R09', 'E02', 'E03']) as $row) {
        $query = $row['query'];
        foreach (['E', 'F', 'G'] as $mode) {
            config(['ai.assistant_constraint_reranking_enabled' => true, 'ai.assistant_lexical_metadata_enabled' => $mode !== 'E',
                'ai.assistant_colbert_post_rrf_enabled' => $mode === 'G']);
            $request = Request::create('/assistant/search', 'POST', server: ['HTTP_X_ASSISTANT_DEBUG' => '1']);
            $app->instance('request', $request);
            $request->setUserResolver(fn () => $user);
            $trace = AssistantSearchDiagnostics::begin($request, $query) ?? throw new RuntimeException('Diagnostics are disabled');
            $start = hrtime(true);
            $works = $search->retrieve($query, $user, withMetadata: true, structure: $row['extraction']['structure']);
            $ms = AssistantSearchDiagnostics::ms($start);
            $ids = $works->pluck('id')->all();
            $expected = $row['trials']['e2e'][$lock['depth'].'/'.$lock['strength']][$mode][0];
            if ($ids !== array_slice($expected['ids'], 0, 20)
                || ($trace->data['stages']['constraints']['fallback_reason'] ?? null) !== $expected['fallback']) {
                throw new RuntimeException('Integrated order or fallback differed for '.$row['id'].' '.$mode);
            }
            [, $snapshots] = $context->prepare($query, $works);
            $first = $works->mapWithKeys(fn ($work, $i) => ['s'.($i + 1) => $work->id]);
            $results[] = ['id' => $row['id'], 'mode' => $mode, 'ids' => $ids, 'ms' => $ms,
                'fallback' => $expected['fallback'], 'rag_snapshot_ids' => $first->only(array_keys($snapshots))->values()->all()];
        }
    }
    file_put_contents('/research/live-integration.json', json_encode($results, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
