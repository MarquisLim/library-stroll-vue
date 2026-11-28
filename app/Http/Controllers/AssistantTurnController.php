<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssistantArtworkRequest;
use App\Models\User;
use App\Services\AiService\AssistantFollowupResolver;
use App\Services\Search\AssistantConversationHistory;
use App\Services\Search\AssistantArtworkSearch;
use App\Services\Search\AssistantInformationalReply;
use App\Services\Search\AssistantIntent;
use App\Services\Search\AssistantIntentRouter;
use App\Services\Search\AssistantRagAnswer;
use App\Services\Search\AssistantSavedSources;
use App\Services\Search\AssistantSearchDiagnostics;
use App\Services\Search\AssistantTurnContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
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
        AssistantIntentRouter $router,
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
            $classification = null;
            if (config('ai.assistant_intent_routing_enabled')) {
                $started = $trace ? hrtime(true) : 0;
                $classification = $router->route($query);
                $trace?->stage('intent', $started, $classification['llm_calls']);
            }
            $intent = $classification['intent'] ?? null;
            $depends = $intent?->usesContext() ?? $context->dependsOnHistory($query);
            $plan = null;
            $allowed = [];
            $contextReason = null;
            if ($intent === null || $depends) {
                [$plan, $allowed, $contextReason] = $this->resolveContext($query, $recent, $user, $context, $resolver,
                    requireSources: $intent !== null, trace: $trace);
            }

            $resolverAction = $plan['action'] ?? null;
            $action = match ($intent) {
                null => $this->legacyAction($resolverAction, $depends, $recent, $query, $context),
                AssistantIntent::SearchMedia => 'new_search',
                AssistantIntent::Followup, AssistantIntent::MediaQuestion => $resolverAction === 'use_previous' ? 'use_previous' : 'clarify',
                AssistantIntent::Clarify => 'clarify',
                default => 'informational',
            };
            if ($trace) {
                $trace->data['dialogue'] = ['resolver_action' => $resolverAction, 'depends_on_history' => $depends, 'action' => $action];
                if ($classification !== null) {
                    $trace->intent($classification, $action, $depends ? $contextReason : null);
                }
            }
            $replyCode = $action === 'informational' ? AssistantInformationalReply::code($intent, $query) : null;
            $selected = $action === 'use_previous' ? array_intersect_key($allowed, array_fill_keys($plan['source_refs'], true)) : [];
            $result = match ($action) {
                'use_previous' => $rag->previous($query, $limit, $user, array_merge(...array_values($selected))),
                'new_search' => $rag->search($query, $limit, $user),
                default => ['status' => $replyCode === null ? 'clarification_required' : 'informational',
                    'answer' => [], 'sources' => [], 'references' => [], 'scope' => []],
            };

            $diagnostics = $trace?->finish($user, $result['status'], $result['sources'], $artworks);
            $turn = $history->appendTurn($user, $conversation, $query, $result['status'], $limit, $result['references'],
                expectedSequence: $recent->first()?->sequence ?? 0, verify: fn () => $sources->confirm($result, $user),
                diagnostics: $diagnostics,
                fallbackSourceIds: $result['status'] === 'ok' || $replyCode !== null ? null : array_column($result['sources'], 'artwork_id'),
                replyCode: $replyCode);

            return response()->json(['turn_id' => $turn->id, 'sequence' => $turn->sequence, ...AssistantRagAnswer::payload($result),
                ...$turn->replyPayload(),
                ...($trace ? ['diagnostics' => $diagnostics] : [])], 201);
        } catch (HttpExceptionInterface|ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            Log::warning('Assistant turn could not be completed.', ['exception_type' => $error::class]);

            return response()->json(['message' => 'Assistant turn could not be completed safely.'], 503);
        }
    }

    private function resolveContext(
        string $query, Collection $recent, User $user, AssistantTurnContext $context,
        AssistantFollowupResolver $resolver, bool $requireSources, ?AssistantSearchDiagnostics $trace,
    ): array {
        if ($recent->isEmpty()) {
            return [null, [], 'no_history'];
        }
        if (! config('ai.assistant_context_enabled')) {
            return [null, [], 'context_disabled'];
        }
        $started = $trace ? hrtime(true) : 0;
        [$payload, $allowed] = $context->prepare($query, $recent, $user);
        $trace?->stage('resolver_context', $started, count($payload['facts']), ['previous_queries' => count($payload['previous_queries'])]);
        if ($payload['facts'] === [] && ($requireSources || $payload['previous_queries'] === [])) {
            return [null, [], 'no_verified_sources'];
        }
        $started = $trace ? hrtime(true) : 0;
        $plan = $resolver->resolve($payload);
        $trace?->stage('resolver', $started, $plan === null ? null : count($plan['source_refs']));
        if ($plan === null) {
            $trace?->error('resolver', 'local_dialogue_rules');
        }
        $reason = match ($plan['action'] ?? null) {
            'use_previous' => null,
            null => 'resolver_unavailable',
            default => 'unresolved_reference',
        };

        return [$plan, $allowed, $reason];
    }

    private function legacyAction(?string $action, bool $depends, Collection $recent, string $query, AssistantTurnContext $context): string
    {
        if ($action === 'use_previous') {
            return 'use_previous';
        }
        $canSearch = ! $depends && ($action === 'new_search'
            || ($action === null && ($recent->isEmpty() || $context->independent($query))));

        return $canSearch ? 'new_search' : 'clarify';
    }
}
