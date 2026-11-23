<?php

namespace App\Http\Controllers;

use App\Models\Artwork;
use App\Services\AiService\AssistantQueryNormalizer;
use App\Services\Search\HybridSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use stdClass;
use Throwable;

class AssistantSearchController extends Controller
{
    private const CANDIDATE_LIMIT = 20;

    public function __invoke(Request $request, HybridSearchService $search, AssistantQueryNormalizer $normalizer): JsonResponse
    {
        $input = $request->isJson() ? json_decode($request->getContent()) : null;
        $payload = $input instanceof stdClass && $request->query() === [] ? (array) $input : null;
        if (is_string($payload['query'] ?? null)) {
            $payload['query'] = trim($payload['query']);
        }

        $data = Validator::make(['payload' => $payload], [
            'payload' => ['required', 'array:query,limit'],
            'payload.query' => ['required', 'string', 'max:500'],
            'payload.limit' => ['sometimes', 'required', 'integer:strict', 'between:1,20'],
        ])->validate()['payload'];

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
            $candidates = $search->search($query, ['type' => 'image'], page: 1, perPage: self::CANDIDATE_LIMIT);
            $ids = $candidates['artworks']->take(self::CANDIDATE_LIMIT)->pluck('id')->unique()->values();
            $artworks = Artwork::publiclyVisible()
                ->useWritePdo()
                ->where('type', 'image')
                ->where('ai_indexing_enabled', true)
                ->whereKey($ids->all())
                ->with(['media' => fn ($query) => $query->useWritePdo()->where('collection_name', 'artworks')])
                ->get()
                ->keyBy('id');

            $gate = Gate::forUser($request->user());
            $results = [];
            foreach ($ids as $id) {
                $artwork = $artworks->get($id);
                if ($artwork === null || ! $gate->allows('view', $artwork)) {
                    continue;
                }

                $media = $artwork->getFirstMedia('artworks');
                if ($media === null || ! str_starts_with((string) $media->mime_type, 'image/')
                    || ! Storage::disk($media->disk)->fileExists($media->getPathRelativeToRoot())) {
                    continue;
                }

                $results[] = [
                    'artwork_id' => $artwork->id,
                    'title' => $artwork->title,
                    'url' => route('artworks.show', ['artwork' => $artwork->id], absolute: false),
                ];
            }
        } catch (Throwable $error) {
            Log::warning('Assistant search could not verify results.', ['exception_type' => $error::class]);

            return response()->json(['message' => 'Artwork access could not be verified.'], 503);
        }

        return response()->json([
            'status' => $results === [] ? 'no_results' : 'ok',
            'results' => array_slice($results, 0, $data['limit'] ?? 10),
        ]);
    }
}
