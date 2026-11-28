<?php

use App\Http\Controllers\AssistantConversationController;
use App\Http\Controllers\AssistantTurnController;
use App\Http\Requests\AssistantArtworkRequest;
use App\Models\User;
use App\Services\AiService\AssistantRagContext;
use App\Services\Search\AssistantArtworkSearch;
use App\Services\Search\AssistantConversationHistory;
use App\Services\Search\AssistantSavedSources;
use App\Services\Search\AssistantSearchDiagnostics;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$output = '/research';
$probe = in_array('--probe', $argv, true);
$datasetPath = __DIR__.'/assistant-intent-cases.json';
$dataset = json_decode(file_get_contents($datasetPath), true, flags: JSON_THROW_ON_ERROR);
$freeze = json_decode(file_get_contents($output.'/dataset-freeze.json'), true, flags: JSON_THROW_ON_ERROR);
if (! hash_equals($freeze['sha256'], hash_file('sha256', $datasetPath)) || count($dataset['cases']) !== $freeze['cases']) {
    throw new RuntimeException('Frozen dataset differs from the benchmark input');
}
$user = User::query()->where('is_blocked', false)->orderBy('id')->firstOrFail();
if (! AssistantSearchDiagnostics::allowed($user) || ! config('ai.assistant_context_enabled') || ! config('ai.enabled')
    || config('ai.rerank_enabled') || config('ai.colbert_sync.enabled') || config('ai.colbert_sync.auto_enabled')) {
    throw new RuntimeException('Unexpected assistant or retrieval configuration');
}
Auth::setUser($user);

// Keep conversation writes in private tables on the benchmark's single MySQL connection.
if (DB::getDriverName() !== 'mysql' || DB::connection()->getPdo() !== DB::connection()->getReadPdo()) {
    throw new RuntimeException('The benchmark requires a single MySQL connection');
}
foreach (['assistant_conversations', 'assistant_turns'] as $table) {
    $definition = array_values((array) DB::selectOne("SHOW CREATE TABLE {$table}"))[1];
    $temporary = preg_replace('/\ACREATE TABLE /', 'CREATE TEMPORARY TABLE ', $definition);
    $temporary = preg_replace('/^ *CONSTRAINT [^\r\n]*\R/m', '', $temporary);
    DB::statement(preg_replace('/,\R\)/', "\n)", $temporary));
    $definition = array_values((array) DB::selectOne("SHOW CREATE TABLE {$table}"))[1];
    if (! str_starts_with($definition, 'CREATE TEMPORARY TABLE')) {
        throw new RuntimeException('Could not isolate the assistant history table');
    }
}
if (DB::selectOne("SHOW COLUMNS FROM assistant_turns LIKE 'reply_code'") === null) {
    DB::statement('ALTER TABLE assistant_turns ADD COLUMN reply_code TEXT NULL');
}

$history = $app->make(AssistantConversationHistory::class);
$context = $app->make(AssistantRagContext::class);
$priorQuery = 'Найди изображения птиц у воды.';
$prior = $app->make(AssistantArtworkSearch::class)->visible(collect([284, 286, 76]), $user, withMetadata: true);
$references = $prior->map(function ($work) use ($context) {
    $snapshot = $context->snapshot($work);
    $ref = $work->id === 76 ? 'f2' : 'f3';
    if (! $snapshot['vision'] || ! isset($snapshot['facts'][$ref])) {
        throw new RuntimeException('Previous citation unavailable');
    }

    return AssistantSavedSources::reference($work, $snapshot, $ref);
})->all();
if (count($references) !== 3) {
    throw new RuntimeException('Could not seed the same three previous citations for both modes');
}

$write = static function (string $name, array $value) use ($output): void {
    file_put_contents($output.'/'.$name.'.json', json_encode($value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
};
$run = static function (array $case, string $mode) use ($app, $user, $history, $priorQuery, $references): array {
    config(['ai.assistant_intent_routing_enabled' => $mode === 'router']);
    $conversation = $user->assistantConversations()->create()->id;
    if ($case['turn'] === 'after_search') {
        $history->appendTurn($user, $conversation, $priorQuery, 'ok', 10, $references);
    }
    $request = AssistantArtworkRequest::create('/assistant/conversations/'.$conversation.'/turns', 'POST', server: [
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_ASSISTANT_DEBUG' => '1',
    ], content: json_encode(['query' => $case['query'], 'limit' => 10], JSON_THROW_ON_ERROR));
    $route = clone $app['router']->getRoutes()->getByName('assistant.conversations.turns.store');
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);
    $app->instance('request', $request);
    $request->setContainer($app)->setRedirector($app['redirect'])->setUserResolver(fn () => $user);
    $started = hrtime(true);
    $request->validateResolved();
    $response = $app->call([$app->make(AssistantTurnController::class), '__invoke'], ['request' => $request, 'conversation' => $conversation]);
    $elapsed = AssistantSearchDiagnostics::ms($started);
    $data = $response->getData(true);
    foreach ($data['answer'] ?? [] as $index => $fact) {
        $data['answer'][$index]['text'] = $fact['fact_ref'] === 'f7' ? '[атрибуция скрыта]'
            : AssistantSearchDiagnostics::publicText($fact['text'], 500);
    }
    foreach ($data['sources'] ?? [] as $index => $source) {
        $data['sources'][$index]['title'] = AssistantSearchDiagnostics::publicText($source['title'], 500);
    }

    return ['conversation_id' => $conversation, 'response_ms' => $elapsed, 'status_code' => $response->getStatusCode(), ...$data];
};

$warmup = ['query' => 'Найди изображение горного озера.', 'turn' => 'first'];
foreach (['legacy', 'router'] as $mode) {
    $sample = $run($warmup, $mode);
    $trace = $sample['diagnostics'] ?? [];
    if ($sample['status_code'] !== 201 || ! ($trace['execution']['retrieval_started'] ?? false)
        || ($mode === 'router' && ($trace['intent']['method'] ?? null) !== 'llm')) {
        fwrite(STDERR, json_encode(['mode' => $mode, 'status_code' => $sample['status_code'],
            'errors' => $trace['errors'] ?? null, 'intent' => $trace['intent'] ?? null], JSON_THROW_ON_ERROR)."\n");
        exit(1);
    }
}

if ($probe) {
    $sample = $run(['query' => 'Good morning!', 'turn' => 'after_search'], 'router');
    $write('smoke', $sample);
    exit($sample['status'] === 'informational' ? 0 : 1);
}

$files = [];
foreach (['legacy', 'router'] as $mode) {
    $files[$mode] = fopen($output.'/controls-'.$mode.'.ndjson', 'wb');
}
$write('run-manifest', ['dataset_sha256' => $freeze['sha256'], 'started_at' => now()->toISOString(),
    'seed_query' => $priorQuery, 'seed_citations' => $references, 'cases' => count($dataset['cases']), 'limit' => 10]);

foreach ($dataset['cases'] as $index => $case) {
    $order = $index % 2 === 0 ? ['legacy', 'router'] : ['router', 'legacy'];
    foreach ($order as $position => $mode) {
        $result = $run($case, $mode);
        fwrite($files[$mode], json_encode(['case' => $case, 'pair_order' => $position + 1, ...$result],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        fflush($files[$mode]);
        if ($case['id'] === 'G02' && $result['status_code'] === 201) {
            $request = Request::create('/assistant/conversations/'.$result['conversation_id'].'/export?debug=1');
            $request->setUserResolver(fn () => $user);
            $export = $app->call([$app->make(AssistantConversationController::class), 'export'],
                ['request' => $request, 'conversation' => $result['conversation_id']]);
            ob_start();
            $export->sendContent();
            file_put_contents($output.'/conversation-export-'.$mode.'.json', ob_get_clean());
        }
    }
    fwrite(STDERR, ($index + 1).'/'.count($dataset['cases']).' '.$case['id']."\n");
}
foreach ($files as $file) {
    fclose($file);
}
