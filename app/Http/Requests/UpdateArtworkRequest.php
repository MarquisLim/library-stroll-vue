<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateArtworkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_adult' => ['sometimes', 'boolean'],
            'has_ai' => ['sometimes', 'boolean'],
            'is_private' => ['sometimes', 'boolean'],
            'allow_download' => ['sometimes', 'boolean'],
            'allow_comments' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'array', 'max:30'],
            'tags.*' => ['string', 'max:100'],
            'collections' => ['sometimes', 'array'],
            'collections.*' => [
                'integer',
                'distinct',
                Rule::exists('collections', 'id')
                    ->where('user_id', $this->user()->id),
            ],
        ];
    }
}
