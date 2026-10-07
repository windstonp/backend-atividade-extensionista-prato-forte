<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** RN34 — peso 30–250 kg com uma casa; data entre hoje − 30 e hoje. */
class WeighInRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'weight_kg' => ['bail', 'required', 'numeric', 'decimal:0,1', 'between:30,250'],
            'date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.today()->subDays(30)->toDateString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['weight_kg' => 'peso', 'date' => 'data'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'weight_kg.between' => 'O peso precisa ficar entre 30 e 250 kg.',
            'weight_kg.decimal' => 'Use no máximo uma casa decimal.',
            'date.before_or_equal' => 'A pesagem não pode ser de um dia que ainda não chegou.',
            'date.after_or_equal' => 'Só dá para registrar pesagens dos últimos 30 dias.',
        ];
    }
}
