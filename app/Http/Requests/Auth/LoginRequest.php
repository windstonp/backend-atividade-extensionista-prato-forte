<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    use NormalizesEmail;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.*' => 'Confira o e-mail.',
            'password.*' => 'Digite sua senha.',
        ];
    }

    /** @return array{email: string, password: string} */
    public function credentials(): array
    {
        return ['email' => $this->string('email')->value(), 'password' => $this->string('password')->value()];
    }
}
