<?php

namespace App\Http\Controllers;

use App\Models\AssistantConversation;
use App\Models\AssistantTurn;
use App\Services\Search\AssistantConversationHistory;
use App\Services\Search\AssistantSavedSources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
        $conversations = $request->user()->assistantConversations()->useWritePdo()->select(['id', 'created_at', 'updated_at'])
            ->addSelect(['first_query' => AssistantTurn::query()->select('user_query')
                ->whereColumn('conversation_id', 'assistant_conversations.id')->orderBy('sequence')->limit(1)])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->simplePaginate($size, ['id', 'created_at', 'updated_at'], 'page', $page);

        return response()->json([
            'conversations' => $conversations->getCollection()->map($this->conversationData(...))->all(),
            'pagination' => $this->paginationData($conversations),
        ]);
    }

    public function show(Request $request, string $conversation, AssistantSavedSources $sources): JsonResponse
    {
        try {
            $owned = $this->history->findOwned($request->user(), $conversation);
            [$page, $size] = $this->pagination($request);
            $turns = $owned->turns()->useWritePdo()->orderByDesc('sequence')->simplePaginate($size, [
                'id', 'sequence', 'user_query', 'result_status', 'requested_limit', 'source_references', 'created_at', 'updated_at',
            ], 'page', $page);
            $resolved = $sources->resolve($turns->getCollection()->flatMap(fn (AssistantTurn $turn) => $turn->citations())->all(), $request->user());

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
                    ...$sources->restore($turn->citations(), $resolved),
                ])->all(),
                'pagination' => $this->paginationData($turns),
            ]);
        } catch (HttpExceptionInterface|ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            Log::warning('Assistant history could not be read.', ['exception_type' => $error::class]);

            return response()->json(['message' => 'Assistant history could not be verified.'], 503);
        }
    }

    public function destroy(Request $request, string $conversation): Response
    {
        $deleted = $request->user()->assistantConversations()->whereKey($conversation)->delete();
        abort_unless($deleted === 1, 404);

        return response()->noContent();
    }

    private function conversationData(AssistantConversation $conversation): array
    {
        $data = [
            'id' => $conversation->id,
            'created_at' => $conversation->created_at->toISOString(),
            'updated_at' => $conversation->updated_at->toISOString(),
        ];
        if (array_key_exists('first_query', $conversation->getAttributes())) {
            $encrypted = $conversation->getRawOriginal('first_query');
            $label = $encrypted ? Str::limit(trim(preg_replace('/\s+/u', ' ', Crypt::decryptString($encrypted)) ?? ''), 60) : 'Новый диалог';
            $data['label'] = $label ?: 'Новый диалог';
        }

        return $data;
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
