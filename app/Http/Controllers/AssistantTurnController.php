<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssistantArtworkRequest;
use App\Services\AiService\AssistantFollowupResolver;
use App\Services\Search\AssistantConversationHistory;
use App\Services\Search\AssistantRagAnswer;
use App\Services\Search\AssistantSavedSources;
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
    ): JsonResponse {
        $user = $request->user();
        $data = $request->validated('payload');
        $query = $data['query'];
        $limit = $data['limit'] ?? 10;

        try {
            $recent = $history->recentForTurn($user, $conversation);
            $depends = $context->dependsOnHistory($query);
            $plan = null;
            $allowed = [];
            if ($recent->isNotEmpty() && config('ai.assistant_context_enabled')) {
                [$payload, $allowed] = $context->prepare($query, $recent, $user);
                if ($payload['facts'] !== [] || $payload['previous_queries'] !== []) {
                    $plan = $resolver->resolve($payload);
                }
            }

            $action = $plan['action'] ?? null;
            $canSearch = ! $depends && ($action === 'new_search'
                || ($action === null && ($recent->isEmpty() || $context->independent($query))));
            if ($action === 'use_previous') {
                $selected = array_intersect_key($allowed, array_fill_keys($plan['source_refs'], true));
                $result = $rag->previous($query, $limit, $user, array_merge(...array_values($selected)));
            } elseif ($canSearch) {
                $result = $rag->search($query, $limit, $user);
            } else {
                $result = ['status' => 'clarification_required', 'answer' => [], 'sources' => [], 'references' => [], 'scope' => []];
            }

            $turn = $history->appendTurn($user, $conversation, $query, $result['status'], $limit, $result['references'],
                expectedSequence: $recent->first()?->sequence ?? 0, verify: fn () => $sources->confirm($result, $user));

            return response()->json(['turn_id' => $turn->id, 'sequence' => $turn->sequence, ...AssistantRagAnswer::payload($result)], 201);
        } catch (HttpExceptionInterface|ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            Log::warning('Assistant turn could not be completed.', ['exception_type' => $error::class]);

            return response()->json(['message' => 'Assistant turn could not be completed safely.'], 503);
        }
    }
}
