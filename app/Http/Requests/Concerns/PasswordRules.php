<?php

namespace App\Http\Requests\Concerns;

/**
 * RN02 — 8 a 72 caracteres (limite do bcrypt), ao menos uma letra (qualquer alfabeto) e um número.
 * Regras explícitas em vez de Password::defaults() para controlar a mensagem exata da spec.
 */
final class PasswordRules
{
    /** @return list<string> */
    public static function rules(): array
    {
        return ['required', 'string', 'min:8', 'max:72', 'regex:/\pL/u', 'regex:/\d/', 'confirmed'];
    }

    /** @return array<string, string> */
    public static function messages(string $field = 'password'): array
    {
        // Específicas antes do curinga: o Laravel usa a primeira chave que casa.
        return [
            "{$field}.confirmed" => 'As senhas não conferem.',
            "{$field}.max" => 'Use no máximo 72 caracteres.',
            "{$field}.*" => 'Use 8 ou mais caracteres, com letra e número.',
        ];
    }
}
