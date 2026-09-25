<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePasswordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => PasswordRules::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.*' => 'A senha atual não confere.',
            ...PasswordRules::messages(),
        ];
    }
}
