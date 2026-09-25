<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class DeleteAccountRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'current_password:web']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['password.*' => 'A senha não confere.'];
    }
}
