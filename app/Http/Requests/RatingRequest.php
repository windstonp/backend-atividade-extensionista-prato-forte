<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** `PUT/DELETE /ratings` (spec 07 §5). */
class RatingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $alvo = [
            'rateable_type' => ['required', 'in:nutri_message,meal_plan'],
            'rateable_id' => ['required', 'integer'],
        ];

        return $this->isMethod('DELETE') ? $alvo : $alvo + [
            'value' => ['required', 'in:up,down'],
            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }
}
