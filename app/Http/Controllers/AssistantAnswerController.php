<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssistantArtworkRequest;
use App\Services\Search\AssistantRagAnswer;
use App\Services\Search\AssistantArtworkSearch;
use App\Services\Search\AssistantSearchDiagnostics;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssistantAnswerController extends Controller
{
    public function __invoke(AssistantArtworkRequest $request, AssistantRagAnswer $rag, AssistantArtworkSearch $search): JsonResponse
    {
        $data = $request->validated('payload');
        $trace = AssistantSearchDiagnostics::begin($request, $data['query']);
        if ($trace) {
            $trace->data['dialogue'] = ['action' => 'new_search'];
        }

        try {
            $result = $rag->search($data['query'], $data['limit'] ?? 10, $request->user());

            return response()->json([...AssistantRagAnswer::payload($result),
                ...($trace ? ['diagnostics' => $trace->finish($request->user(), $result['status'], $result['sources'], $search)] : [])]);
        } catch (Throwable $error) {
            Log::warning('Assistant answer could not verify results.', ['exception_type' => $error::class]);

            return response()->json(['message' => 'Artwork access could not be verified.'], 503);
        }
    }
}
