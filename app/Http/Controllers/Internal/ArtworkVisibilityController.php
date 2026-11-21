<?php

namespace App\Http\Controllers\Internal;

use App\Models\Artwork;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ArtworkVisibilityController
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->server('AI_VISIBILITY_INTERNAL') === '1', 404);

        $expected = (string) config('ai.api_key');
        abort_unless(trim($expected) !== '' && hash_equals($expected, (string) $request->header('X-AI-Service-Key')), 401);
        abort_unless($request->isJson(), 415);
        abort_if(strlen($request->getContent()) > 8192, 413);

        $validated = Validator::make($request->json()->all(), [
            'artwork_ids' => ['required', 'array', 'list', 'min:1', 'max:101'],
            'artwork_ids.*' => ['integer:strict', 'min:1'],
        ])->validate();

        // The existing Artwork scope also excludes blocked works and blocked authors.
        $visibleIds = Artwork::publiclyVisible()->useWritePdo()
            ->where('ai_indexing_enabled', true)
            ->whereKey($validated['artwork_ids'])
            ->pluck('id');

        return response()->json(['visible_ids' => $visibleIds])->header('Cache-Control', 'no-store');
    }
}
