<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssistantArtworkRequest;
use App\Models\Artwork;
use App\Services\AiService\AssistantAnswerComposer;
use App\Services\AiService\AssistantRagContext;
use App\Services\Search\AssistantArtworkSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssistantAnswerController extends Controller
{
    public function __invoke(
        AssistantArtworkRequest $request,
        AssistantArtworkSearch $search,
        AssistantRagContext $context,
        AssistantAnswerComposer $composer,
    ): JsonResponse {
        $data = $request->validated('payload');
        $user = $request->user();
        $query = $data['query'];
        $limit = $data['limit'] ?? 10;

        try {
            $candidates = $search->retrieve($query, $user, withMetadata: true);
            [$facts, $snapshots] = $context->prepare($query, $candidates->take($limit));
            $initial = $this->withReferences($candidates);

            if ($facts === []) {
                return $this->respond('insufficient_evidence', [], $initial->take($limit));
            }

            $plan = $composer->compose($query, $facts);
            $current = $search->visible($candidates->pluck('id'), $user, withMetadata: true)->keyBy('id');
            $sources = $initial->map(fn (Artwork $artwork) => $current->get($artwork->id))->filter();
            if (($plan['action'] ?? null) !== 'compose') {
                return $this->respond($plan['action'] ?? 'search_fallback', [], $sources->take($limit));
            }

            $answer = [];
            $checked = [];
            foreach ($plan['selections'] as ['source_ref' => $source, 'fact_ref' => $ref]) {
                $artwork = $sources->get($source);
                if ($artwork === null || ($checked[$source] ??= $context->snapshot($artwork)) !== $snapshots[$source]) {
                    return $this->respond('search_fallback', [], $sources->take($limit));
                }
                $answer[] = [
                    'source_ref' => $source,
                    'fact_ref' => $ref,
                    'text' => $context->render($ref, $checked[$source]['facts'][$ref]['value']),
                ];
            }

            return $this->respond('ok', $answer, $sources->only(array_column($answer, 'source_ref')));
        } catch (Throwable $error) {
            Log::warning('Assistant answer could not verify results.', ['exception_type' => $error::class]);

            return response()->json(['message' => 'Artwork access could not be verified.'], 503);
        }
    }

    private function withReferences(Collection $artworks): Collection
    {
        return $artworks->mapWithKeys(fn (Artwork $artwork, int $index) => ['s'.($index + 1) => $artwork]);
    }

    private function respond(string $status, array $answer, Collection $sources): JsonResponse
    {
        return response()->json([
            'status' => $sources->isEmpty() ? 'no_results' : $status,
            'answer' => $answer,
            'sources' => $sources->map(fn (Artwork $artwork, string $ref) => [
                'source_ref' => $ref,
                ...AssistantArtworkSearch::card($artwork),
            ])->values()->all(),
        ]);
    }
}
