<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use stdClass;

class AssistantArtworkRequest extends FormRequest
{
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
            'payload.query' => ['required', 'string', 'max:500'],
            'payload.limit' => ['sometimes', 'required', 'integer:strict', 'between:1,20'],
        ];
    }
}
