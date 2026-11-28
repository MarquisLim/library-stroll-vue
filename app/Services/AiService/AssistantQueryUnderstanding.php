<?php

namespace App\Services\AiService;

use App\Services\Search\AssistantSearchDiagnostics;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AssistantQueryUnderstanding
{
    public function extract(string $query): array
    {
        $trace = AssistantSearchDiagnostics::current();
        $key = config('ai.api_key');
        $result = ['method' => 'llm', 'validation' => 'not_available', 'structure' => null, 'would_clarify' => null,
            'fallback_reason' => 'service_key_missing', 'llm_calls' => 0, 'tokens' => []];
        if (! is_string($key) || trim($key) === '') {
            $trace?->error('query_understanding', 'service_key_missing');

            return $result;
        }

        $timeout = max(0.25, min(35.0, (float) config('ai.assistant_llm_timeout')));
        $result['llm_calls'] = null;
        try {
            $request = Http::baseUrl(config('ai.service_url'))->acceptJson()
                ->withHeaders(['X-AI-Service-Key' => $key])->withoutRedirecting()
                ->connectTimeout(min(1.0, $timeout))->timeout($timeout);
            if (defined('CURLOPT_QUICK_EXIT')) {
                $request->withOptions(['curl' => [CURLOPT_QUICK_EXIT => true]]);
            }
            $response = $request->withHeader('X-Assistant-Debug', '1')
                ->post('/api/v1/assistant/understand-query', ['query' => $query]);
        } catch (Throwable $error) {
            $trace?->error('query_understanding', 'transport_failure');
            Log::warning('Assistant query understanding unavailable.', ['exception_type' => $error::class]);

            return [...$result, 'fallback_reason' => 'transport_failure'];
        }

        $usage = AssistantSearchDiagnostics::usage($response, ['Model-Calls', 'Prompt-Tokens', 'Output-Tokens']);
        $calls = $usage['model_calls'] ?? null;
        unset($usage['model_calls']);
        $result['llm_calls'] = $calls === 0 || $calls === 1 ? $calls : ($response->ok() ? 1 : null);
        $result['tokens'] = $usage;
        $structure = $response->ok() ? AssistantQueryStructure::decode($response->body(), $query) : null;
        if ($structure !== null) {
            $trace?->model('query_understanding', $response->header('X-Assistant-Model'));

            return [...$result, 'validation' => 'valid', 'structure' => $structure,
                'would_clarify' => AssistantQueryStructure::wouldClarify($structure), 'fallback_reason' => null];
        }

        $reason = match ([$response->status(), $response->ok() ? null : $response->json('detail')]) {
            [200, null], [502, 'assistant_query_understanding_invalid_result'] => 'invalid_result',
            [422, 'assistant_query_understanding_context_too_large'] => 'context_budget',
            [504, 'assistant_query_understanding_timeout'] => 'timeout',
            [502, 'assistant_query_understanding_unavailable'] => 'service_unavailable',
            [404, 'assistant_query_understanding_disabled'] => 'service_disabled',
            default => 'service_rejected',
        };
        $trace?->error('query_understanding', $reason, $response->status());

        return [...$result, 'validation' => $reason === 'invalid_result' ? 'rejected' : 'not_available', 'fallback_reason' => $reason];
    }
}
