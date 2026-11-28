<?php

use App\Http\Controllers\AssistantConversationController;
use App\Http\Controllers\AssistantTurnController;
use App\Http\Requests\AssistantArtworkRequest;
use App\Models\User;
use App\Services\AiService\AssistantQueryUnderstanding;
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
$mode = $argv[1] ?? '';
if (! in_array($mode, ['development', 'evaluation', 'paired'], true)) {
    throw new InvalidArgumentException('Specify development, evaluation, or paired');
}
$casesPath = __DIR__.'/assistant-query-cases.json';
$equivalencePath = __DIR__.'/assistant-query-equivalence.json';
$cases = json_decode(file_get_contents($casesPath), true, flags: JSON_THROW_ON_ERROR);
$freeze = json_decode(file_get_contents($output.'/dataset-freeze.json'), true, flags: JSON_THROW_ON_ERROR);
if (! hash_equals($freeze['cases_sha256'], hash_file('sha256', $casesPath))
    || ! hash_equals($freeze['equivalence_sha256'], hash_file('sha256', $equivalencePath))) {
    throw new RuntimeException('Frozen benchmark inputs changed');
}

$encode = static fn (array $data) => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
$append = static function ($file, array $data) use ($encode): void {
    fwrite($file, $encode($data));
    fflush($file);
};

if ($mode !== 'paired') {
    $understanding = $app->make(AssistantQueryUnderstanding::class);
    $warmup = $understanding->extract('Find a picture of a brown rabbit.');
    if ($warmup['validation'] !== 'valid' && $warmup['fallback_reason'] !== 'invalid_result') {
        throw new RuntimeException('Query understanding was not reachable');
    }
    $file = fopen($output.'/extraction-'.$mode.'.ndjson', 'wb');
    foreach ($cases[$mode] as [$id, $language, $query]) {
        $started = hrtime(true);
        $result = $understanding->extract($query);
        $append($file, ['id' => $id, 'language' => $language, 'query' => $query, 'ms' => AssistantSearchDiagnostics::ms($started), ...$result]);
        fwrite(STDERR, $id.' '.$result['validation']."\n");
    }
    fclose($file);
    exit;
}

$user = User::query()->where('is_blocked', false)->orderBy('id')->firstOrFail();
Auth::setUser($user);
if (! AssistantSearchDiagnostics::allowed($user) || ! config('ai.enabled') || ! config('ai.assistant_context_enabled')
    || config('ai.rerank_enabled') || config('ai.colbert_sync.enabled') || config('ai.colbert_sync.auto_enabled')
    || DB::getDriverName() !== 'mysql' || DB::connection()->getPdo() !== DB::connection()->getReadPdo()) {
    throw new RuntimeException('Unexpected benchmark configuration');
}

// Only this connection sees the temporary history tables. Other data are read by the regular pipeline.
foreach (['assistant_conversations', 'assistant_turns'] as $table) {
    $definition = array_values((array) DB::selectOne("SHOW CREATE TABLE {$table}"))[1];
    $temporary = preg_replace('/\ACREATE TABLE /', 'CREATE TEMPORARY TABLE ', $definition);
    $temporary = preg_replace('/^ *CONSTRAINT [^\r\n]*\R/m', '', $temporary);
    DB::statement(preg_replace('/,\R\)/', "\n)", $temporary));
    $active = array_values((array) DB::selectOne("SHOW CREATE TABLE {$table}"))[1];
    if (! str_starts_with($active, 'CREATE TEMPORARY TABLE')) {
        throw new RuntimeException('Could not isolate history');
    }
}
if (DB::selectOne("SHOW COLUMNS FROM assistant_turns LIKE 'reply_code'") === null) {
    DB::statement('ALTER TABLE assistant_turns ADD COLUMN reply_code TEXT NULL');
}
$history = $app->make(AssistantConversationHistory::class);
$context = $app->make(AssistantRagContext::class);
$previous = $app->make(AssistantArtworkSearch::class)->visible(collect([284, 286, 76]), $user, withMetadata: true);
$references = $previous->map(function ($work) use ($context) {
    $snapshot = $context->snapshot($work);
    $ref = $work->id === 76 ? 'f2' : 'f3';
    if (! $snapshot['vision'] || ! isset($snapshot['facts'][$ref])) {
        throw new RuntimeException('An experimental prior citation is missing');
    }

    return AssistantSavedSources::reference($work, $snapshot, $ref);
})->all();
if (count($references) !== 3) {
    throw new RuntimeException('Three prior citations are required for this benchmark');
}

$run = static function (string $query, string $turn, bool $enabled) use ($app, $user, $history, $references): array {
    config(['ai.assistant_intent_routing_enabled' => true, 'ai.assistant_query_understanding_enabled' => $enabled]);
    $conversation = $user->assistantConversations()->create()->id;
    if ($turn === 'after_search') {
        $history->appendTurn($user, $conversation, 'Найди изображения птиц у воды.', 'ok', 10, $references);
    }
    $request = AssistantArtworkRequest::create('/assistant/conversations/'.$conversation.'/turns', 'POST', server: [
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_ASSISTANT_DEBUG' => '1',
    ], content: json_encode(['query' => $query, 'limit' => 10], JSON_THROW_ON_ERROR));
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

$firstIds = ['E01', 'E07', 'E15', 'E19', 'E25', 'E35', 'E43', 'E51'];
$laterIds = ['E02', 'E08', 'E18', 'E24', 'E32', 'E38', 'E44', 'E50'];
foreach ([false, true] as $enabled) {
    $sample = $run('Найди фотографию книги.', 'first', $enabled);
    if ($sample['status_code'] !== 201 || ! ($sample['diagnostics']['execution']['retrieval_started'] ?? false)
        || ($enabled && ! isset($sample['diagnostics']['query_understanding']))) {
        throw new RuntimeException('Could not warm up both experiment modes');
    }
}
$files = ['A' => fopen($output.'/paired-A.ndjson', 'wb'), 'B' => fopen($output.'/paired-B.ndjson', 'wb')];
$guards = ['D23', 'D24', 'E55', 'E56', 'E57', 'E58'];
$guardFile = fopen($output.'/routing-probes.ndjson', 'wb');
$index = 0;
foreach ([...$cases['development'], ...$cases['evaluation']] as [$id, $language, $query]) {
    if (in_array($id, $guards, true)) {
        $append($guardFile, ['id' => $id, 'query' => $query, ...$run($query, 'first', true)]);
    }
    $turn = in_array($id, $firstIds, true) ? 'first' : (in_array($id, $laterIds, true) ? 'after_search' : null);
    if ($turn === null) {
        continue;
    }
    $order = $index++ % 2 === 0 ? ['A', 'B'] : ['B', 'A'];
    foreach ($order as $position => $variant) {
        $result = $run($query, $turn, $variant === 'B');
        $append($files[$variant], ['id' => $id, 'query' => $query, 'turn' => $turn, 'pair_order' => $position + 1, ...$result]);
        if ($id === 'E19' && $variant === 'B' && $result['status_code'] === 201) {
            $request = Request::create('/assistant/conversations/'.$result['conversation_id'].'/export?debug=1');
            $request->setUserResolver(fn () => $user);
            $export = $app->call([$app->make(AssistantConversationController::class), 'export'],
                ['request' => $request, 'conversation' => $result['conversation_id']]);
            ob_start();
            $export->sendContent();
            file_put_contents($output.'/conversation-export.json', ob_get_clean());
        }
    }
    fwrite(STDERR, $id."\n");
}
foreach ([...array_values($files), $guardFile] as $file) {
    fclose($file);
}
