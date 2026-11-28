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
$phase = $argv[1] ?? '';
if (! in_array($phase, ['online', 'offline'], true)) {
    throw new InvalidArgumentException('Specify online or offline');
}
DB::statement('SET SESSION TRANSACTION READ ONLY');
config(['ai.enabled' => true, 'ai.assistant_lexical_metadata_enabled' => true,
    'ai.assistant_colbert_url' => 'http://library-stroll-colbert-541d:8001']);
$measured = json_decode(file_get_contents('/research/runs-evaluation.json'), true, flags: JSON_THROW_ON_ERROR);
$depth = $measured['depths'][0];
config(['ai.assistant_colbert_depth' => $depth]);
$search = $app->make(AssistantArtworkSearch::class);
$rag = $app->make(AssistantRagContext::class);
$user = User::where('is_blocked', false)->orderBy('id')->firstOrFail();
$probes = collect($measured['rows'])->whereIn('id', ['R03', 'R06', 'E04', 'E10']);
$rows = [];
foreach ($probes as $probe) {
    foreach ([false, true] as $enabled) {
        config(['ai.assistant_colbert_post_rrf_enabled' => $enabled]);
        $query = $probe['query'];
        $request = Request::create('/assistant/search', 'POST', server: ['HTTP_X_ASSISTANT_DEBUG' => '1']);
        $app->instance('request', $request);
        $request->setUserResolver(fn () => $user);
        $trace = AssistantSearchDiagnostics::begin($request, $query) ?? throw new RuntimeException('Diagnostics disabled');
        $start = hrtime(true);
        $works = $search->retrieve($query, $user, withMetadata: true);
        $ms = AssistantSearchDiagnostics::ms($start);
        $ids = $works->pluck('id')->all();
        $mode = $enabled && $phase === 'online' ? 'F'.$depth : 'C';
        if ($ids !== array_slice($probe['runs'][$mode][0]['ids'], 0, 20)) {
            throw new RuntimeException("Live ranking differs from frozen $mode");
        }
        [, $snapshots] = $rag->prepare($query, $works->take(5));
        $first = $works->mapWithKeys(fn ($work, $i) => ['s'.($i + 1) => $work->id]);
        $sources = $first->only(array_keys($snapshots))->values()->all();
        $diagnostics = $trace->finish($user, 'ok', [], $search);
        $colbert = $diagnostics['stages']['colbert'];
        $expectedReasons = $enabled && $phase === 'offline' ? ['service_unavailable', 'timeout'] : [null];
        if (($enabled && ($colbert === null || ! in_array($colbert['fallback_reason'] ?? null, $expectedReasons, true)))
            || (! $enabled && $colbert !== null)) {
            throw new RuntimeException('Unexpected ColBERT stage outcome: '.($colbert['fallback_reason'] ?? 'none'));
        }
        $rows[] = ['id' => $probe['id'], 'mode' => $mode, 'flag' => $enabled, 'retrieve_ms' => $ms,
            'ids' => $ids, 'rag_snapshot_ids' => $sources, 'colbert' => $colbert];
    }
}
file_put_contents('/research/live-'.$phase.'.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
