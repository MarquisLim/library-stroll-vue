<?php

namespace App\Services\AiService;

use App\Services\Search\AssistantSearchDiagnostics;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use stdClass;
use Throwable;

class AssistantAnswerComposer
{
    private const MAX_SELECTIONS = 5;

    public function compose(string $query, array $facts, string $strategy = AssistantRagContext::LEGACY): ?array
    {
        $trace = AssistantSearchDiagnostics::current();
        $key = config('ai.api_key');
        if (! is_string($key) || trim($key) === '') {
            if ($trace) {
                $trace->data['rag']['transport_attempted'] = false;
            }
            $trace?->error('composer', 'service_key_missing');
            return null;
        }

        $timeout = max(0.25, min(35.0, (float) config('ai.assistant_llm_timeout')));
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
                $trace->data['rag']['transport_attempted'] = true;
            }

            $payload = ['query' => $query, 'facts' => $facts];
            if ($strategy === AssistantRagContext::VISUAL) {
                $payload['packing'] = $strategy;
            }
            $trace?->modelRequest('composer');
            $response = $request->post('/api/v1/assistant/compose', $payload);
            $trace?->observe('composer', $response);
            $trace?->composerUsage($response);
            $plan = $response->ok() ? json_decode($response->body()) : null;
        } catch (Throwable $error) {
            $trace?->error('composer', 'transport_failure');
            Log::warning('Assistant answer composition unavailable.', ['exception_type' => $error::class]);

            return null;
        }

        if (! $plan instanceof stdClass || count((array) $plan) !== 2 || ! is_array($plan->selections ?? null)) {
            return null;
        }

        if (($plan->action ?? null) === 'insufficient_evidence' && $plan->selections === []) {
            return ['action' => 'insufficient_evidence', 'selections' => []];
        }

        if (($plan->action ?? null) !== 'compose' || $plan->selections === [] || count($plan->selections) > self::MAX_SELECTIONS) {
            return null;
        }

        $allowed = [];
        foreach ($facts as $fact) {
            $allowed[$fact['source_ref']][$fact['fact_ref']] = true;
        }
        $selections = [];
        foreach ($plan->selections as $selection) {
            if (! $selection instanceof stdClass || count((array) $selection) !== 2
                || ! is_string($selection->source_ref ?? null) || ! is_string($selection->fact_ref ?? null)
                || ! isset($allowed[$selection->source_ref][$selection->fact_ref])) {
                return null;
            }

            unset($allowed[$selection->source_ref][$selection->fact_ref]);
            $selections[] = (array) $selection;
        }

        return ['action' => 'compose', 'selections' => $selections];
    }
}
