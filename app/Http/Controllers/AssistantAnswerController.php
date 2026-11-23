<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssistantArtworkRequest;
use App\Services\Search\AssistantRagAnswer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssistantAnswerController extends Controller
{
    public function __invoke(AssistantArtworkRequest $request, AssistantRagAnswer $rag): JsonResponse
    {
        $data = $request->validated('payload');

        try {
            return response()->json(AssistantRagAnswer::payload($rag->search($data['query'], $data['limit'] ?? 10, $request->user())));
        } catch (Throwable $error) {
            Log::warning('Assistant answer could not verify results.', ['exception_type' => $error::class]);

            return response()->json(['message' => 'Artwork access could not be verified.'], 503);
        }
    }
}
