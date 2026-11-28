<?php

namespace App\Services\AiService;

use App\Services\Search\AssistantIntent;
use App\Services\Search\AssistantSearchDiagnostics;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssistantIntentClassifier
{
    public function classify(string $query): array
    {
        $trace = AssistantSearchDiagnostics::current();
        $key = config('ai.api_key');
        $result = ['intent' => null, 'fallback_reason' => 'service_key_missing', 'requests' => 0, 'llm_calls' => 0, 'tokens' => []];
        if (! is_string($key) || trim($key) === '') {
            $trace?->error('intent', 'service_key_missing');

            return $result;
        }

        $timeout = max(0.25, min(35.0, (float) config('ai.assistant_llm_timeout')));
        $result['requests'] = 1;
        $result['llm_calls'] = null;
        try {
            $request = Http::baseUrl(config('ai.service_url'))->acceptJson()
                ->withHeaders(['X-AI-Service-Key' => $key])->withoutRedirecting()
                ->connectTimeout(min(1.0, $timeout))->timeout($timeout);
            if (defined('CURLOPT_QUICK_EXIT')) {
                $request->withOptions(['curl' => [CURLOPT_QUICK_EXIT => true]]);
            }
            if ($trace) {
                $request->withHeader('X-Assistant-Debug', '1');
            }
            $response = $request->post('/api/v1/assistant/classify-intent', ['query' => $query]);
        } catch (Throwable $error) {
            $trace?->error('intent', 'transport_failure');
            Log::warning('Assistant intent classification unavailable.', ['exception_type' => $error::class]);

            return [...$result, 'fallback_reason' => 'transport_failure'];
        }

        $usage = AssistantSearchDiagnostics::usage($response, ['Model-Calls', 'Prompt-Tokens', 'Output-Tokens']);
        $calls = $usage['model_calls'] ?? null;
        unset($usage['model_calls']);
        $result['llm_calls'] = $calls === 0 || $calls === 1 ? $calls : ($response->ok() ? 1 : null);
        $result['tokens'] = $usage;

        if (! $response->ok()) {
            $reason = match ([$response->status(), $response->json('detail')]) {
                [504, 'assistant_intent_timeout'] => 'timeout',
                [502, 'assistant_intent_invalid_result'] => 'invalid_result',
                [502, 'assistant_intent_unavailable'] => 'service_unavailable',
                [422, 'assistant_intent_context_too_large'] => 'context_budget',
                [404, 'assistant_intent_disabled'] => 'service_disabled',
                default => 'service_rejected',
            };
            $trace?->error('intent', $reason, $response->status());

            return [...$result, 'fallback_reason' => $reason];
        }

        $trace?->model('intent', $response->header('X-Assistant-Model'));
        $plan = $response->json();
        $intent = is_array($plan) && array_keys($plan) === ['intent'] && is_string($plan['intent'])
            ? AssistantIntent::tryFrom($plan['intent']) : null;
        if ($intent === null) {
            $trace?->error('intent', 'invalid_result', $response->status());
        }

        return [...$result, 'intent' => $intent, 'fallback_reason' => $intent === null ? 'invalid_result' : null];
    }
}
