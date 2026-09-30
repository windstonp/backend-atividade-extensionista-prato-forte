<?php

namespace App\Services\Plans;

use App\Enums\PlanEffect;
use App\Models\User;
use App\Services\Foods\FoodFilter;

/** RN21 — o que uma mudança do perfil faz com o plano ativo. */
class ProfileChangeEffects
{
    public function __construct(private readonly PlanService $plans) {}

    /**
     * Foto do que importa para o plano, para comparar antes e depois.
     *
     * @return array{restrictions: list<mixed>, plan: list<mixed>, times: list<mixed>}
     */
    public function snapshot(User $user): array
    {
        $user->unsetRelation('latestWeighIn');
        $profile = $user->profile()->firstOrFail();
        $user->setRelation('profile', $profile);
        $sorted = function (array $values): array {
            sort($values);

            return $values;
        };

        return [
            'restrictions' => [
                $sorted($user->restrictions()->pluck('restrictions.id')->all()),
                $sorted(array_map(FoodFilter::normalize(...), $profile->other_restrictions)),
            ],
            'plan' => $profile->isOnboarded() ? [
                (array) $this->plans->targetsFor($user),
                $profile->goal,
                $profile->goal_weight_kg === null ? null : (float) $profile->goal_weight_kg,
                $profile->lunch_place,
                $sorted($user->pantryItems()->pluck('pantry_items.id')->all()),
                $sorted($user->dislikedFoods()->pluck('foods.id')->all()),
            ] : [],
            'times' => [
                substr((string) $profile->wake_time, 0, 5), substr((string) $profile->training_time, 0, 5),
                substr((string) $profile->sleep_time, 0, 5), $sorted($profile->training_days),
            ],
        ];
    }

    /**
     * @param  array{restrictions: list<mixed>, plan: list<mixed>, times: list<mixed>}  $before
     * @param  array{restrictions: list<mixed>, plan: list<mixed>, times: list<mixed>}  $after
     * @return array{effect: PlanEffect, plan_id: int|null}
     */
    public function apply(User $user, array $before, array $after): array
    {
        if (! $user->activePlan()->exists()) {
            return ['effect' => PlanEffect::None, 'plan_id' => null]; // durante o onboarding ou sem plano: nada a refazer
        }

        if ($before['restrictions'] !== $after['restrictions']) {
            // Segurança: restrição nova nunca espera (RN17, RN21).
            return ['effect' => PlanEffect::RegenerationStarted, 'plan_id' => $this->plans->requestGeneration($user, force: true)->id];
        }

        $timesChanged = $before['times'] !== $after['times'];
        if ($timesChanged) {
            $this->plans->retime($user);
        }

        if ($before['plan'] !== $after['plan']) {
            return ['effect' => PlanEffect::RegenerationSuggested, 'plan_id' => null];
        }

        return ['effect' => $timesChanged ? PlanEffect::TimesUpdated : PlanEffect::None, 'plan_id' => null];
    }
}
