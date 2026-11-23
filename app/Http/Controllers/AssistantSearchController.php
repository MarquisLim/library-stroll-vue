<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssistantArtworkRequest;
use App\Services\AiService\AssistantQueryNormalizer;
use App\Services\Search\AssistantArtworkSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssistantSearchController extends Controller
{
    public function __invoke(
        AssistantArtworkRequest $request,
        AssistantArtworkSearch $search,
        AssistantQueryNormalizer $normalizer,
    ): JsonResponse {
        $data = $request->validated('payload');

        $normalization = config('ai.assistant_llm_enabled') ? $normalizer->normalize($data['query']) : null;
        if (($normalization['action'] ?? null) === 'clarify') {
            return response()->json([
                'status' => 'clarification_required',
                'clarification_code' => $normalization['clarification_code'],
                'results' => [],
            ]);
        }

        try {
            $query = $normalization['search_query'] ?? $data['query'];
            $results = $search->retrieve($query, $request->user())
                ->take($data['limit'] ?? 10)
                ->map(AssistantArtworkSearch::card(...))
                ->all();
        } catch (Throwable $error) {
            Log::warning('Assistant search could not verify results.', ['exception_type' => $error::class]);

            return response()->json(['message' => 'Artwork access could not be verified.'], 503);
        }

        return response()->json([
            'status' => $results === [] ? 'no_results' : 'ok',
            'results' => $results,
        ]);
    }
}
