<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** `POST /usability-responses` (spec 07 §5). */
class UsabilityResponseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sus_answers' => ['required', 'array', 'size:10'],
            'sus_answers.*' => ['required', 'integer', 'between:1,5'],
            'usefulness' => ['required', 'integer', 'between:1,5'],
            'liked' => ['nullable', 'string', 'max:1000'],
            'disliked' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
