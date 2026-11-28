<?php

namespace App\Services\AiService;

use App\Services\Search\AssistantSearchDiagnostics;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AssistantQueryNormalizer
{
    private const REQUEST_PREFIX = '/\A(?:(?:пожалуйста,? )?(?:найди(?:те)?|покажи(?:те)?|подбери(?:те)?) (?:мне )?(?:изображения|картинки) |(?:please )?(?:find|show|look for) (?:me )?(?:images|pictures) of )/iu';

    /**
     * @return array{action: 'search', search_query: string, clarification_code: null}|array{action: 'clarify', search_query: null, clarification_code: 'ambiguous_subject'|'too_broad'}|null
     */
    public function normalize(string $query): ?array
    {
        $trace = AssistantSearchDiagnostics::current();
        $key = config('ai.api_key');
        if (! is_string($key) || trim($key) === '') {
            $trace?->error('normalizer', 'service_key_missing');
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
            }

            $response = $request->post('/api/v1/assistant/normalize', ['query' => $query]);
            $trace?->observe('normalizer', $response);
            $result = $response->ok() ? $response->json() : null;
        } catch (Throwable $error) {
            $trace?->error('normalizer', 'transport_failure');
            Log::warning('Assistant query normalization unavailable.', ['exception_type' => $error::class]);

            return null;
        }

        if (! is_array($result) || count($result) !== 3
            || ! array_key_exists('search_query', $result) || ! array_key_exists('clarification_code', $result)) {
            return null;
        }

        if (($result['action'] ?? null) === 'clarify' && $result['search_query'] === null
            && in_array($result['clarification_code'], ['ambiguous_subject', 'too_broad'], true)) {
            return $result;
        }

        $candidate = $result['search_query'];
        if (($result['action'] ?? null) !== 'search' || $result['clarification_code'] !== null
            || ! is_string($candidate) || mb_strlen($candidate) > 500) {
            return null;
        }

        // Only accept whitespace normalization or removing known framing without editing the subject.
        $candidate = Str::squish($candidate);
        $original = Str::squish($query);
        if ($candidate === '' || ($candidate !== $original && $candidate !== preg_replace(self::REQUEST_PREFIX, '', $original))) {
            return null;
        }

        $result['search_query'] = $candidate;

        return $result;
    }
}
