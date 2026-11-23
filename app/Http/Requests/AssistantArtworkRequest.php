<?php

namespace App\Http\Requests;

use App\Services\Search\AssistantConversationHistory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\FormRequest;
use stdClass;

class AssistantArtworkRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->routeIs('assistant.conversations.turns.store')) {
            try {
                app(AssistantConversationHistory::class)->findOwned($this->user(), $this->route('conversation'));
            } catch (QueryException) {
                abort(503, 'Assistant turn could not be completed safely.');
            }
        }

        return true;
    }

    public function validationData(): array
    {
        $input = $this->isJson() ? json_decode($this->getContent()) : null;
        $payload = $input instanceof stdClass && $this->query() === [] ? (array) $input : null;
        if (is_string($payload['query'] ?? null)) {
            $payload['query'] = trim($payload['query']);
        }

        return ['payload' => $payload];
    }

    public function rules(): array
    {
        return [
            'payload' => ['required', 'array:query,limit'],
            'payload.query' => ['required', 'string', 'max:'.($this->routeIs('assistant.conversations.turns.store')
                ? config('ai.assistant_history.max_query_chars') : 500)],
            'payload.limit' => ['sometimes', 'required', 'integer:strict', 'between:1,20'],
        ];
    }
}
