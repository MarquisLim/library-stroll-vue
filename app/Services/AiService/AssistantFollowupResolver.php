<?php

namespace App\Services\AiService;

use App\Services\Search\AssistantSearchDiagnostics;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

class AssistantFollowupResolver
{
    private const CLARIFY = ['action' => 'clarify', 'source_refs' => []];

    public function resolve(array $payload): ?array
    {
        $trace = AssistantSearchDiagnostics::current();
        $key = config('ai.api_key');
        if (! is_string($key) || trim($key) === '') {
            $trace?->error('resolver', 'service_key_missing');
            return null;
        }

        $timeout = max(0.25, min(35.0, (float) config('ai.assistant_llm_timeout')));
        $started = hrtime(true);
        try {
            $request = Http::baseUrl(config('ai.service_url'))
                ->acceptJson()
                ->withHeaders(['X-AI-Service-Key' => $key])
                ->withoutRedirecting()
                ->connectTimeout(min(1.0, $timeout))
                ->timeout($timeout);
            if (defined('CURLOPT_QUICK_EXIT')) {
                $request->withOptions(['curl' => [CURLOPT_QUICK_EXIT => true]]);
            }
            if ($trace) {
                $request->withHeader('X-Assistant-Debug', '1');
            }

            $trace?->modelRequest('resolver');
            $response = $request->post('/api/v1/assistant/resolve-followup', $payload);
            $trace?->observe('resolver', $response);
            if (! $response->ok()) {
                return in_array($response->status(), [503, 504], true) ? null : self::CLARIFY;
            }
            $plan = json_decode($response->body());
        } catch (Throwable $error) {
            $trace?->error('resolver', 'transport_failure');
            Log::warning('Assistant follow-up resolution unavailable.', ['exception_type' => $error::class]);

            return null;
        } finally {
            Log::debug('Assistant follow-up resolution completed.', ['elapsed_ms' => (hrtime(true) - $started) / 1e6]);
        }

        $action = $plan->action ?? null;
        $sources = $plan->source_refs ?? null;
        if (! $plan instanceof stdClass || count((array) $plan) !== 2 || ! is_array($sources)
            || ! in_array($action, ['new_search', 'use_previous', 'clarify'], true)) {
            return self::CLARIFY;
        }
        if ($action !== 'use_previous') {
            return $sources === [] ? ['action' => $action, 'source_refs' => []] : self::CLARIFY;
        }
        if ($sources === [] || count($sources) > 5) {
            return self::CLARIFY;
        }

        $allowed = array_fill_keys(array_column($payload['facts'], 'source_ref'), true);
        foreach ($sources as $source) {
            if (! is_string($source) || ! isset($allowed[$source])) {
                return self::CLARIFY;
            }
            unset($allowed[$source]);
        }

        return ['action' => $action, 'source_refs' => $sources];
    }
}
