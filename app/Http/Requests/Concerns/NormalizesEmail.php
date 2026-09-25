<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Str;

/** RN01 — valida e busca o e-mail já normalizado (minúsculo, sem espaços). */
trait NormalizesEmail
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }
}
