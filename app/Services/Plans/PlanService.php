<?php

namespace App\Services\Plans;

use App\Enums\ActivityLevel;
use App\Enums\ErrorCode;
use App\Enums\Goal;
use App\Enums\PlanStatus;
use App\Enums\Sex;
use App\Enums\WorkPosture;
use App\Exceptions\DomainException;
use App\Jobs\GeneratePlanJob;
use App\Models\MealPlan;
use App\Models\User;
use App\Services\Nutrition\DailyTargets;
use App\Services\Nutrition\NutritionCalculator;
use Illuminate\Support\Facades\DB;

/** Pedir, ativar e refazer planos (RN19–RN21). */
class PlanService
{
    public function __construct(private readonly NutritionCalculator $calculator) {}

    /**
     * RN19 — um plano gerando por usuário. `$force`: mudança de restrição (RN21) não espera o que está
     * gerando; o mais novo vence na ativação.
     */
    public function requestGeneration(User $user, bool $force = false): MealPlan
    {
        $plan = DB::transaction(function () use ($user, $force) {
            User::whereKey($user->id)->lockForUpdate()->first(); // serializa pedidos do mesmo usuário
            $profile = $user->profile()->firstOrFail();

            if (! $profile->isOnboarded()) {
                throw new DomainException(ErrorCode::OnboardingIncomplete, ['next_step' => $profile->nextStep()?->value]);
            }
            if (! $force) {
                $busy = $user->mealPlans()->whereIn('status', [PlanStatus::Pending, PlanStatus::Generating])->latest('id')->first();
                if ($busy !== null) {
                    throw new DomainException(ErrorCode::PlanAlreadyGenerating, ['plan_id' => $busy->id]);
                }
            }

            $targets = $this->targetsFor($user);

            return $user->mealPlans()->create([
                'status' => PlanStatus::Pending,
                'target_kcal' => $targets->kcal,
                'target_protein_g' => $targets->proteinG,
                'target_carbs_g' => $targets->carbsG,
                'target_fat_g' => $targets->fatG,
                'inputs' => [],
            ]);
        });

        GeneratePlanJob::dispatch($plan->id);

        return $plan;
    }

    /** RN13 com o perfil e o peso atual. */
    public function targetsFor(User $user): DailyTargets
    {
        $profile = $user->profile()->firstOrFail();

        return $this->calculator->dailyTargets(
            Goal::from((string) $profile->goal), Sex::from((string) $profile->sex), (int) $profile->age, (int) $profile->height_cm,
            (float) $user->currentWeightKg(), ActivityLevel::from((string) $profile->activity_level), WorkPosture::from((string) $profile->work_posture),
        );
    }

    /**
     * RN20 — o plano pronto vira o único ativo. Um plano mais antigo que termine depois de um mais novo
     * (ainda válido) não é ativado: vira `failed` `SUPERSEDED`, para uma restrição nova nunca se perder.
     */
    public function activate(MealPlan $plan): bool
    {
        return DB::transaction(function () use ($plan) {
            User::whereKey($plan->user_id)->lockForUpdate()->first();

            $newer = MealPlan::where('user_id', $plan->user_id)->where('id', '>', $plan->id)
                ->where('status', '!=', PlanStatus::Failed)->exists();
            if ($newer) {
                $plan->update(['status' => PlanStatus::Failed, 'failure_reason' => 'SUPERSEDED']);

                return false;
            }

            MealPlan::where('user_id', $plan->user_id)->where('is_active', true)->update(['is_active' => false, 'active_user_id' => null]);
            $plan->update(['status' => PlanStatus::Ready, 'is_active' => true, 'ready_at' => now()]);

            return true;
        });
    }
}
