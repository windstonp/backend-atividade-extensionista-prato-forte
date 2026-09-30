<?php

namespace App\Services\Profile;

use App\Enums\ErrorCode;
use App\Enums\Goal;
use App\Enums\OnboardingStep;
use App\Exceptions\DomainException;
use App\Models\MealPlan;
use App\Models\Profile;
use App\Models\User;
use App\Services\Nutrition\GoalWeightResolver;
use App\Services\Plans\PlanService;
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

    public function __construct(private readonly GoalWeightResolver $goalWeights, private readonly PlanService $plans) {}

    /** Idempotente: quem já concluiu recebe o plano mais recente. */
    public function complete(User $user): ?MealPlan
    {
        $profile = $user->profile;
        if ($profile->isOnboarded()) {
            return $user->mealPlans()->latest('id')->first();
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

        // RF09 — o primeiro plano sai daqui (spec 02 §5).
        return $this->plans->requestGeneration($user);
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
