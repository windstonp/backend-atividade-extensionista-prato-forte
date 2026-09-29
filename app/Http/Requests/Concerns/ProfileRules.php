<?php

namespace App\Http\Requests\Concerns;

/** Listas do perfil, iguais na etapa do onboarding e em Preferências (spec 02 §6). */
final class ProfileRules
{
    /** @return array<string, list<string>> */
    public static function pantry(): array
    {
        return [
            'pantry_items' => ['present', 'array'],
            'pantry_items.*' => ['string', 'distinct', 'exists:pantry_items,slug'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function restrictions(): array
    {
        return [
            'restrictions' => ['present', 'array'],
            'restrictions.*' => ['string', 'distinct', 'exists:restrictions,slug'],
            'other_restrictions' => ['present', 'array', 'max:10'],
            'other_restrictions.*' => ['string', 'min:2', 'max:60', 'distinct:ignore_case'],
        ];
    }

    /**
     * A ordem importa: a primeira chave que casar vence.

     *

     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'pantry_items.*' => 'Confira os itens da cozinha.',
            'restrictions.*' => 'Confira as restrições.',
            'other_restrictions.max' => 'Use no máximo 10 itens.',
            'other_restrictions.*.distinct' => 'Esse item já está na lista.',
            'other_restrictions.*' => 'Cada item precisa ter de 2 a 60 letras.',
            'disliked_food_ids.*' => 'Confira os alimentos que você prefere não ver.',
        ];
    }
}
