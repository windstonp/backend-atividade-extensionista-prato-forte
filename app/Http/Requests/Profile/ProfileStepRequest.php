<?php

namespace App\Http\Requests\Profile;

use App\Enums\ActivityLevel;
use App\Enums\Goal;
use App\Enums\LunchPlace;
use App\Enums\Sex;
use App\Enums\WorkPosture;
use App\Http\Requests\Concerns\ProfileRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Uma etapa do onboarding (spec 02 §6); a etapa vem da rota. */
class ProfileStepRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->route('step')) {
            'objetivo' => ['goal' => ['required', Rule::enum(Goal::class)]],
            'dados' => [
                'preferred_name' => ['required', 'string', 'max:40'],
                'age' => ['required', 'integer', 'between:18,100'],
                'height_cm' => ['required', 'integer', 'between:120,230'],
                'weight_kg' => ['required', 'numeric', 'decimal:0,1', 'between:30,250'],
                'sex' => ['required', Rule::enum(Sex::class)],
                'goal_weight_kg' => $this->goalWeightRules(),
            ],
            'atividade' => [
                'activity_level' => ['required', Rule::enum(ActivityLevel::class)],
                'work_posture' => ['required', Rule::enum(WorkPosture::class)],
            ],
            'preferencias' => ProfileRules::pantry(),
            'restricoes' => ProfileRules::restrictions(),
            'rotina' => [
                'wake_time' => ['required', 'date_format:H:i'],
                'training_time' => ['required', 'date_format:H:i'],
                'sleep_time' => ['required', 'date_format:H:i'],
                'training_days' => ['present', 'array', 'max:7'],
                'training_days.*' => ['integer', 'between:0,6', 'distinct'],
                'lunch_place' => ['required', Rule::enum(LunchPlace::class)],
            ],
            default => [],
        };
    }

    /**
     * RN12 — treino dentro da janela acordado, e janela de pelo menos 12 h.

     *

     * @return list<callable>
     */
    public function after(): array
    {
        if ($this->route('step') !== 'rotina') {
            return [];
        }

        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['wake_time', 'training_time', 'sleep_time'])) {
                return;
            }

            $wake = $this->minutes('wake_time');
            $sleep = $this->minutes('sleep_time');
            $training = $this->minutes('training_time');

            if ($sleep <= $wake) {
                $sleep += 24 * 60; // dorme depois da meia-noite
            }
            if ($sleep - $wake < 12 * 60) {
                $validator->errors()->add('sleep_time', 'Seu dia acordado precisa ter pelo menos 12 horas.');

                return;
            }
            if ($training < $wake) {
                $training += 24 * 60;
            }
            if ($training >= $sleep) {
                $validator->errors()->add('training_time', 'O treino precisa estar entre a hora que você acorda e a que dorme.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'goal.*' => 'Escolha um objetivo.',
            'preferred_name.*' => 'Diga como podemos te chamar (até 40 letras).',
            'age.*' => 'Use uma idade entre 18 e 100 anos.',
            'height_cm.*' => 'Use a altura em centímetros, entre 120 e 230.',
            'weight_kg.*' => 'Use um peso entre 30 e 250 kg, com até uma casa decimal.',
            'sex.*' => 'Escolha uma opção.',
            'goal_weight_kg.gt' => 'Para ganhar massa, a meta precisa ser maior que o peso de hoje.',
            'goal_weight_kg.lt' => 'Para perder gordura, a meta precisa ser menor que o peso de hoje.',
            'goal_weight_kg.prohibited' => 'Esse objetivo não usa meta de peso.',
            'goal_weight_kg.*' => 'Use uma meta entre 30 e 250 kg.',
            'activity_level.*' => 'Escolha quantas vezes você treina.',
            'work_posture.*' => 'Escolha como é seu trabalho.',
            'wake_time.*' => 'Use o formato 06:20.',
            'training_time.*' => 'Use o formato 06:20.',
            'sleep_time.*' => 'Use o formato 06:20.',
            'training_days.*' => 'Confira os dias de treino.',
            'lunch_place.*' => 'Escolha onde você almoça.',
            ...ProfileRules::messages(),
        ];
    }

    /**
     * RN10: só ganhar/perder aceitam meta, na direção do objetivo já salvo.

     *

     * @return list<string>
     */
    private function goalWeightRules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $goal = Goal::tryFrom((string) $user->profile->goal);

        if ($goal === null || ! $goal->asksGoalWeight()) {
            return ['prohibited'];
        }

        return ['nullable', 'numeric', 'decimal:0,1', 'between:30,250', $goal === Goal::GanharMassa ? 'gt:weight_kg' : 'lt:weight_kg'];
    }

    private function minutes(string $field): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', (string) $this->input($field)));

        return $hours * 60 + $minutes;
    }
}
