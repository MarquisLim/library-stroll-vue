<?php

namespace App\Http\Controllers;

use App\Models\AssistantConversation;
use App\Models\AssistantTurn;
use App\Services\Search\AssistantConversationHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AssistantConversationController extends Controller
{
    public function __construct(private AssistantConversationHistory $history) {}

    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'conversation' => $this->conversationData($this->history->create($request->user())),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        [$page, $size] = $this->pagination($request);
        $conversations = $request->user()->assistantConversations()->useWritePdo()
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->simplePaginate($size, ['id', 'created_at', 'updated_at'], 'page', $page);

        return response()->json([
            'conversations' => $conversations->getCollection()->map($this->conversationData(...))->all(),
            'pagination' => $this->paginationData($conversations),
        ]);
    }

    public function show(Request $request, string $conversation): JsonResponse
    {
        $owned = $this->history->findOwned($request->user(), $conversation);
        [$page, $size] = $this->pagination($request);
        $turns = $owned->turns()->useWritePdo()->orderByDesc('sequence')->simplePaginate($size, [
            'id', 'sequence', 'user_query', 'result_status', 'requested_limit', 'created_at', 'updated_at',
        ], 'page', $page);

        return response()->json([
            'conversation' => $this->conversationData($owned),
            'turns' => $turns->getCollection()->map(fn (AssistantTurn $turn) => [
                'id' => $turn->id,
                'sequence' => $turn->sequence,
                'user_query' => $turn->user_query,
                'result_status' => $turn->result_status,
                'requested_limit' => $turn->requested_limit,
                'created_at' => $turn->created_at->toISOString(),
                'updated_at' => $turn->updated_at->toISOString(),
            ])->all(),
            'pagination' => $this->paginationData($turns),
        ]);
    }

    public function destroy(Request $request, string $conversation): Response
    {
        $deleted = $request->user()->assistantConversations()->whereKey($conversation)->delete();
        abort_unless($deleted === 1, 404);

        return response()->noContent();
    }

    private function conversationData(AssistantConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'created_at' => $conversation->created_at->toISOString(),
            'updated_at' => $conversation->updated_at->toISOString(),
        ];
    }

    /** @throws ValidationException */
    private function pagination(Request $request): array
    {
        $maxSize = config('ai.assistant_history.page_size');
        $data = validator($request->query(), [
            'page' => ['sometimes', 'required', 'integer', 'between:1,1000'],
            'per_page' => ['sometimes', 'required', 'integer', 'between:1,'.$maxSize],
        ])->validate();

        return [(int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? $maxSize)];
    }

    private function paginationData(Paginator $page): array
    {
        return ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'has_more' => $page->hasMorePages()];
    }
}
