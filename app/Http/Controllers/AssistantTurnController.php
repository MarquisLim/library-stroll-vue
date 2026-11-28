<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssistantArtworkRequest;
use App\Services\AiService\AssistantFollowupResolver;
use App\Services\Search\AssistantConversationHistory;
use App\Services\Search\AssistantArtworkSearch;
use App\Services\Search\AssistantRagAnswer;
use App\Services\Search\AssistantSavedSources;
use App\Services\Search\AssistantSearchDiagnostics;
use App\Services\Search\AssistantTurnContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class AssistantTurnController extends Controller
{
    public function __invoke(
        AssistantArtworkRequest $request,
        string $conversation,
        AssistantConversationHistory $history,
        AssistantTurnContext $context,
        AssistantFollowupResolver $resolver,
        AssistantRagAnswer $rag,
        AssistantSavedSources $sources,
        AssistantArtworkSearch $artworks,
    ): JsonResponse {
        $user = $request->user();
        $data = $request->validated('payload');
        $query = $data['query'];
        $limit = $data['limit'] ?? 10;
        $trace = AssistantSearchDiagnostics::begin($request, $query);

        try {
            $started = $trace ? hrtime(true) : 0;
            $recent = $history->recentForTurn($user, $conversation);
            $trace?->stage('recent_history', $started, $recent->count());
            $depends = $context->dependsOnHistory($query);
            $plan = null;
            $allowed = [];
            if ($recent->isNotEmpty() && config('ai.assistant_context_enabled')) {
                $started = $trace ? hrtime(true) : 0;
                [$payload, $allowed] = $context->prepare($query, $recent, $user);
                $trace?->stage('resolver_context', $started, count($payload['facts']), ['previous_queries' => count($payload['previous_queries'])]);
                if ($payload['facts'] !== [] || $payload['previous_queries'] !== []) {
                    $started = $trace ? hrtime(true) : 0;
                    $plan = $resolver->resolve($payload);
                    $trace?->stage('resolver', $started, $plan === null ? null : count($plan['source_refs']));
                    if ($plan === null) {
                        $trace?->error('resolver', 'local_dialogue_rules');
                    }
                }
            }

            $action = $plan['action'] ?? null;
            $canSearch = ! $depends && ($action === 'new_search'
                || ($action === null && ($recent->isEmpty() || $context->independent($query))));
            if ($trace) {
                $trace->data['dialogue'] = ['resolver_action' => $action, 'depends_on_history' => $depends,
                    'action' => $action === 'use_previous' ? 'use_previous' : ($canSearch ? 'new_search' : 'clarify')];
            }
            if ($action === 'use_previous') {
                $selected = array_intersect_key($allowed, array_fill_keys($plan['source_refs'], true));
                $result = $rag->previous($query, $limit, $user, array_merge(...array_values($selected)));
            } elseif ($canSearch) {
                $result = $rag->search($query, $limit, $user);
            } else {
                $result = ['status' => 'clarification_required', 'answer' => [], 'sources' => [], 'references' => [], 'scope' => []];
            }

            $diagnostics = $trace?->finish($user, $result['status'], $result['sources'], $artworks);
            $turn = $history->appendTurn($user, $conversation, $query, $result['status'], $limit, $result['references'],
                expectedSequence: $recent->first()?->sequence ?? 0, verify: fn () => $sources->confirm($result, $user),
                diagnostics: $diagnostics,
                fallbackSourceIds: $result['status'] === 'ok' ? null : array_column($result['sources'], 'artwork_id'));

            return response()->json(['turn_id' => $turn->id, 'sequence' => $turn->sequence, ...AssistantRagAnswer::payload($result),
                ...($trace ? ['diagnostics' => $diagnostics] : [])], 201);
        } catch (HttpExceptionInterface|ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            Log::warning('Assistant turn could not be completed.', ['exception_type' => $error::class]);

            return response()->json(['message' => 'Assistant turn could not be completed safely.'], 503);
        }
    }
}
