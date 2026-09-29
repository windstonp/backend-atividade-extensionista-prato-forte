<?php

namespace App\Services\Profile;

use App\Enums\ErrorCode;
use App\Enums\Goal;
use App\Enums\OnboardingStep;
use App\Exceptions\DomainException;
use App\Models\Profile;
use App\Models\User;
use App\Services\Nutrition\GoalWeightResolver;
use Illuminate\Support\Facades\DB;

/** Conclusão do onboarding (RF07, RN08, RN10, RN34). O primeiro plano chega no Plano 04. */
class OnboardingService
{
    /** Campos que cada etapa precisa ter para concluir. `preferred_name` tem padrão (primeiro nome). */
    private const REQUIRED = [
        'objetivo' => ['goal'],
        'dados' => ['age', 'height_cm', 'start_weight_kg', 'sex'],
        'atividade' => ['activity_level', 'work_posture'],
        'preferencias' => [],
        'restricoes' => [],
        'rotina' => ['wake_time', 'training_time', 'sleep_time', 'lunch_place'],
    ];

    public function __construct(private readonly GoalWeightResolver $goalWeights) {}

    /** Idempotente: quem já concluiu não muda nada. */
    public function complete(User $user): void
    {
        $profile = $user->profile;
        if ($profile->isOnboarded()) {
            return;
        }

        $missing = $this->firstIncompleteStep($profile);
        if ($missing !== null) {
            throw new DomainException(ErrorCode::ValidationError, ['step' => $missing->value], 'Falta completar uma etapa.');
        }

        DB::transaction(function () use ($user, $profile) {
            $weight = (float) $profile->start_weight_kg;
            $user->weighIns()->updateOrCreate(['date' => today()->toDateString()], ['weight_kg' => $weight]); // RN34

            if ($profile->goal_weight_kg === null) {
                $suggested = $this->goalWeights->suggest(Goal::from((string) $profile->goal), $weight, (int) $profile->height_cm);
                $profile->goal_weight_kg = $suggested?->kg;
                $profile->goal_weight_source = $suggested?->source->value;
            }

            $profile->completed_steps = array_map(fn (OnboardingStep $step) => $step->value, OnboardingStep::cases());
            $profile->onboarding_completed_at = now();
            $profile->save();
        });
    }

    /** Primeira etapa não salva ou salva sem um campo obrigatório (RN08). */
    public function firstIncompleteStep(Profile $profile): ?OnboardingStep
    {
        foreach (self::REQUIRED as $step => $fields) {
            $empty = array_filter($fields, fn (string $field) => $profile->getAttribute($field) === null);
            if (! in_array($step, $profile->completed_steps, true) || $empty !== []) {
                return OnboardingStep::from($step);
            }
        }

        return null;
    }

    /**
     * Etapas que a prévia das metas usa e ainda não foram salvas.

     *

     * @return list<string>
     */
    public function missingForPreview(Profile $profile): array
    {
        return array_values(array_filter(
            ['objetivo', 'dados', 'atividade'],
            fn (string $step) => ! in_array($step, $profile->completed_steps, true),
        ));
    }
}
