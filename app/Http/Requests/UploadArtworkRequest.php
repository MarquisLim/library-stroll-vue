<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadArtworkRequest extends FormRequest
{
    public function authorize() { return auth()->check(); }

    public function rules(): array
    {
        return [
            'file'    => 'required|file|max:20480|mimes:jpg,jpeg,png,gif,mp4,mov,avi,webm',
            'draftId' => 'nullable|integer|exists:artworks,id,user_id,'.auth()->id(),
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Неверный формат файла',
            'file.max'   => 'Максимум 20 МБ.',
        ];
    }
}
