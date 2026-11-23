<?php

namespace App\Services\Search;

use App\Models\AssistantConversation;
use App\Models\AssistantTurn;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssistantConversationHistory
{
    public function create(User $owner): AssistantConversation
    {
        return DB::transaction(function () use ($owner) {
            // Serialize quota checks and inserts for this owner.
            $owner = User::query()->lockForUpdate()->findOrFail($owner->getKey());
            $conversations = $owner->assistantConversations();
            abort_if($conversations->count() >= config('ai.assistant_history.max_conversations'), 409, 'Conversation limit reached.');

            return $conversations->create();
        }, attempts: 3);
    }

    public function findOwned(User $owner, string $id): AssistantConversation
    {
        return $owner->assistantConversations()->useWritePdo()->find($id) ?? abort(404);
    }

    /** @return Collection<int, AssistantTurn> */
    public function recentForTurn(User $owner, string $id): Collection
    {
        return DB::transaction(function () use ($owner, $id) {
            $conversation = $owner->assistantConversations()->lockForUpdate()->find($id) ?? abort(404);
            $turns = $conversation->turns();
            $this->checkTurnLimit($turns->count());

            return $turns->orderByDesc('sequence')->limit(4)->get();
        }, attempts: 3);
    }

    /**
     * $verify rechecks the response from the database without calling inference or search.
     *
     * @param list<array{artwork_id: int, fact_ref: string, revision_hash: string, fact_sha256: string}> $references
     */
    public function appendTurn(
        User $owner,
        string $id,
        string $query,
        string $status,
        int $requestedLimit,
        array $references,
        ?int $expectedSequence = null,
        ?Closure $verify = null,
    ): AssistantTurn {
        abort_unless(config('ai.assistant_enabled') && config('ai.assistant_history_enabled'), 404);

        return DB::transaction(function () use ($owner, $id, $query, $status, $requestedLimit, $references, $expectedSequence, $verify) {
            $conversation = $owner->assistantConversations()->lockForUpdate()->find($id) ?? abort(404);
            $data = $this->validatedTurn($query, $status, $requestedLimit, $references);
            $turns = $conversation->turns();
            $last = (int) (clone $turns)->orderByDesc('sequence')->lockForUpdate()->value('sequence');
            abort_if($expectedSequence !== null && $last !== $expectedSequence, 409, 'Conversation has changed. Reload it before retrying.');
            $verify?->__invoke();
            $this->checkTurnLimit($turns->count());

            $turn = $turns->create(['sequence' => $last + 1, ...$data]);
            $conversation->touch();

            return $turn;
        }, attempts: 3);
    }

    private function checkTurnLimit(int $count): void
    {
        abort_if($count >= config('ai.assistant_history.max_turns'), 409, 'Conversation turn limit reached.');
    }

    private function validatedTurn(string $query, string $status, int $requestedLimit, array $references): array
    {
        $data = Validator::make([
            'user_query' => trim($query),
            'result_status' => $status,
            'requested_limit' => $requestedLimit,
            'source_references' => $references,
        ], [
            'user_query' => ['required', 'string', 'max:'.config('ai.assistant_history.max_query_chars')],
            'result_status' => ['required', Rule::in(AssistantTurn::RESULT_STATUSES)],
            'requested_limit' => ['required', 'integer:strict', 'between:1,20'],
            'source_references' => ['present', 'array', 'list', 'max:5'],
            'source_references.*' => ['required', 'array:artwork_id,fact_ref,revision_hash,fact_sha256'],
            'source_references.*.artwork_id' => ['required', 'integer:strict', 'min:1'],
            'source_references.*.fact_ref' => ['required', 'string', 'regex:/\Af[1-7]\z/'],
            'source_references.*.revision_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'source_references.*.fact_sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ])->validate();

        if (strlen(json_encode($data['source_references'], JSON_THROW_ON_ERROR)) > config('ai.assistant_history.max_reference_bytes')) {
            throw ValidationException::withMessages(['source_references' => 'Saved source references exceed the storage limit.']);
        }

        return $data;
    }
}
