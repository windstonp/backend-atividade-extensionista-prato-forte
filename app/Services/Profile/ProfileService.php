<?php

namespace App\Services\Profile;

use App\Enums\Goal;
use App\Enums\GoalWeightSource;
use App\Enums\OnboardingStep;
use App\Enums\PlanEffect;
use App\Models\PantryItem;
use App\Models\Profile;
use App\Models\Restriction;
use App\Models\User;
use App\Services\Nutrition\GoalWeight;
use App\Services\Nutrition\GoalWeightResolver;
use App\Services\Plans\ProfileChangeEffects;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Etapas do onboarding e edição do perfil (RN08, RN10, RN11, RN34). RN21 chega no Plano 04. */
class ProfileService
{
    public function __construct(
        private readonly GoalWeightResolver $goalWeights,
        private readonly ProfileChangeEffects $effects,
    ) {}

    /**
     * Salva uma etapa e a marca como feita; devolve os avisos da resposta (`meta.warnings`).
     *
     * @param  array<string, mixed>  $data  validado por ProfileStepRequest
     * @return array{warnings: list<string>, effect: PlanEffect, plan_id: int|null}
     */
    public function updateStep(User $user, OnboardingStep $step, array $data): array
    {
        $before = $this->effects->snapshot($user);

        $warnings = DB::transaction(function () use ($user, $step, $data) {
            $profile = $user->profile;

            $warnings = match ($step) {
                OnboardingStep::Objetivo => $this->saveGoal($user, $profile, Goal::from((string) $data['goal'])),
                OnboardingStep::Dados => $this->saveBody($user, $profile, $data),
                OnboardingStep::Atividade, OnboardingStep::Rotina => $this->fill($profile, $data),
                OnboardingStep::Preferencias => $this->syncPantry($user, $data['pantry_items']),
                OnboardingStep::Restricoes => $this->saveRestrictions($user, $profile, $data),
                OnboardingStep::Resumo => throw new LogicException('O resumo não é uma etapa salva.'),
            };

            $profile->completed_steps = array_values(array_unique([...$profile->completed_steps, $step->value]));
            $profile->save();

            return $warnings;
        });

        return ['warnings' => $warnings, ...$this->effects->apply($user, $before, $this->effects->snapshot($user))];
    }

    /**
     * RF17 — substitui as quatro listas de uma vez.
     *
     * @param  array<string, mixed>  $data  validado por PreferencesRequest
     * @return array{effect: PlanEffect, plan_id: int|null}
     */
    public function updatePreferences(User $user, array $data): array
    {
        $before = $this->effects->snapshot($user);

        DB::transaction(function () use ($user, $data) {
            $this->saveRestrictions($user, $user->profile, $data);
            $user->profile->save();
            $this->syncPantry($user, $data['pantry_items']);
            $user->dislikedFoods()->sync($data['disliked_food_ids']);
        });

        return $this->effects->apply($user, $before, $this->effects->snapshot($user));
    }

    /**
     * RN11: objetivo novo que não combina com a meta → meta refeita (ou apagada no onboarding) e aviso.

     *

     * @return list<string>
     */
    private function saveGoal(User $user, Profile $profile, Goal $goal): array
    {
        $profile->goal = $goal->value;

        $weight = $user->currentWeightKg();
        $previous = $profile->goal_weight_kg === null ? null : (float) $profile->goal_weight_kg;
        if ($weight === null || $profile->height_cm === null || $this->goalWeights->fits($goal, $weight, $previous)) {
            return [];
        }

        $this->setGoalWeight($profile, $this->defaultGoalWeight($profile, $goal, $weight));
        $current = $profile->goal_weight_kg === null ? null : (float) $profile->goal_weight_kg;

        return $previous !== null && $current !== $previous ? [GoalWeightResolver::RESET] : [];
    }

    /**
     * Etapa `dados`.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function saveBody(User $user, Profile $profile, array $data): array
    {
        $profile->fill(Arr::only($data, ['preferred_name', 'age', 'height_cm', 'sex']));
        $weight = (float) $data['weight_kg'];

        if ($profile->isOnboarded()) {
            // RN34: depois do onboarding, "peso de hoje" é a pesagem do dia; o peso inicial não muda.
            // Peso igual ao atual = a pessoa só mexeu em outro campo: não inventa uma pesagem.
            $current = $user->currentWeightKg();
            if ($current === null || abs($current - $weight) >= 0.05) {
                $user->weighIns()->updateOrCreate(['date' => today()->toDateString()], ['weight_kg' => $weight]);
                $user->unsetRelation('latestWeighIn');
            }
        } else {
            $profile->start_weight_kg = $weight;
        }

        $informed = $data['goal_weight_kg'] ?? null;
        if ($informed !== null) {
            $this->setGoalWeight($profile, new GoalWeight((float) $informed, GoalWeightSource::User));

            return $this->goalWeights->isOutOfRange((float) $informed, (int) $profile->height_cm) ? [GoalWeightResolver::OUT_OF_RANGE] : [];
        }

        $goal = Goal::tryFrom((string) $profile->goal);
        $previous = $profile->goal_weight_kg === null ? null : (float) $profile->goal_weight_kg;
        if ($goal !== null && $profile->isOnboarded() && $profile->goal_weight_source === 'suggested' && $this->goalWeights->fits($goal, $weight, $previous)) {
            return []; // meta sugerida que ainda combina fica: senão ela andaria junto com o peso
        }
        $this->setGoalWeight($profile, $goal === null ? null : $this->defaultGoalWeight($profile, $goal, $weight));

        return [];
    }

    /** Durante o onboarding a meta vazia espera a conclusão (RN10); depois, vira a sugerida na hora. */
    private function defaultGoalWeight(Profile $profile, Goal $goal, float $weight): ?GoalWeight
    {
        return $profile->isOnboarded() ? $this->goalWeights->suggest($goal, $weight, (int) $profile->height_cm) : null;
    }

    private function setGoalWeight(Profile $profile, ?GoalWeight $goalWeight): void
    {
        $profile->goal_weight_kg = $goalWeight?->kg;
        $profile->goal_weight_source = $goalWeight?->source->value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function fill(Profile $profile, array $data): array
    {
        $profile->fill($data);

        return [];
    }

    /**
     * @param  list<string>  $slugs
     * @return list<string>
     */
    private function syncPantry(User $user, array $slugs): array
    {
        $user->pantryItems()->sync(PantryItem::whereIn('slug', $slugs)->pluck('id'));

        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function saveRestrictions(User $user, Profile $profile, array $data): array
    {
        $user->restrictions()->sync(Restriction::whereIn('slug', $data['restrictions'])->pluck('id'));
        $profile->other_restrictions = array_values(array_map(fn (string $item) => trim($item), $data['other_restrictions']));

        return [];
    }
}
