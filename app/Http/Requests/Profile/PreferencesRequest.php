<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\Concerns\ProfileRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Preferências e restrições (RF17): as quatro listas, sempre inteiras. */
class PreferencesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ProfileRules::restrictions(),
            ...ProfileRules::pantry(),
            'disliked_food_ids' => ['present', 'array'],
            'disliked_food_ids.*' => ['integer', 'distinct', Rule::exists('foods', 'id')->where('common_dislike', true)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ProfileRules::messages();
    }
}
