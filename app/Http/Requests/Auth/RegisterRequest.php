<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesEmail;
use App\Http\Requests\Concerns\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    use NormalizesEmail;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => PasswordRules::rules(),
            'terms_accepted' => ['accepted'],
            'terms_version' => ['required', 'string', Rule::in([config('prato.terms_version')])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.*' => 'Escreva seu nome.',
            'email.unique' => 'Esse e-mail já tem conta.',
            'email.*' => 'Confira o e-mail.',
            'terms_accepted.accepted' => 'Para continuar, aceite o termo.',
            'terms_version.*' => 'Atualize a página e aceite o termo de novo.',
            ...PasswordRules::messages(),
        ];
    }
}
