<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddArtworkToCollectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
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
