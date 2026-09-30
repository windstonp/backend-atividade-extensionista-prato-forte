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
use App\Models\DayMeal;
use App\Models\MealPlan;
use App\Models\User;
use App\Services\Days\DayMaterializer;
use App\Services\Foods\FoodFilter;
use App\Services\Nutrition\DailyTargets;
use App\Services\Nutrition\MealScheduler;
use App\Services\Nutrition\NutritionCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Pedir, ativar e refazer planos (RN19–RN21). */
class PlanService
{
    public function __construct(
        private readonly NutritionCalculator $calculator,
        private readonly MealScheduler $scheduler,
        private readonly DayMaterializer $days,
        private readonly FoodFilter $filter,
    ) {}

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
            if ($force) {
                // Os que ainda esperam na fila não chegam a chamar a IA: o novo lê o perfil mais recente.
                $user->mealPlans()->where('status', PlanStatus::Pending)
                    ->update(['status' => PlanStatus::Failed, 'failure_reason' => 'SUPERSEDED']);
            } else {
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

            if (! $this->respectsRestrictions($plan)) {
                // A restrição mudou enquanto o plano era montado (RN17): este não serve; outro sai na hora.
                $plan->update(['status' => PlanStatus::Failed, 'failure_reason' => 'RESTRICTIONS_CHANGED']);
                $this->requestGeneration($plan->user()->firstOrFail(), force: true);

                return false;
            }

            MealPlan::where('user_id', $plan->user_id)->where('is_active', true)->update(['is_active' => false, 'active_user_id' => null]);
            $plan->update(['status' => PlanStatus::Ready, 'is_active' => true, 'ready_at' => now()]);
            $this->refreshToday($plan);

            return true;
        });
    }

    private function respectsRestrictions(MealPlan $plan): bool
    {
        $allowed = $this->filter->allowedFor($plan->user()->firstOrFail());

        return $plan->meals()->with('items')->get()->flatMap->items->every(fn ($item) => $allowed->has($item->food_id));
    }

    /** RN14/RN21 (`times_updated`) — horários novos no plano ativo e nas refeições não feitas de hoje, sem IA. */
    public function retime(User $user): void
    {
        $plan = $user->activePlan()->with('meals')->first();
        if ($plan === null) {
            return;
        }
        $profile = $user->profile()->firstOrFail();
        $user->setRelation('profile', $profile);
        $times = $this->scheduler->schedule(
            substr((string) $profile->wake_time, 0, 5), substr((string) $profile->training_time, 0, 5),
            substr((string) $profile->sleep_time, 0, 5), $profile->training_days !== [],
        );
        $order = array_keys($times);

        DB::transaction(function () use ($user, $plan, $times, $order) {
            foreach ($plan->meals as $meal) {
                $meal->update(['time' => $times[$meal->slot], 'position' => (int) array_search($meal->slot, $order, true) + 1]);
            }
            $plan->unsetRelation('meals');

            $fresh = $this->days->build($user, $plan, CarbonImmutable::today(), save: false)->keyBy('slot');
            foreach ($user->dayMeals()->whereDate('date', CarbonImmutable::today())->get() as $meal) {
                $new = $fresh[$meal->slot];
                $meal->update($meal->isDone()
                    ? ['position' => $new->position]
                    : ['position' => $new->position, 'time' => $new->time, 'name' => $new->name, 'note' => $new->note]);
            }
        });
    }

    /** RN20 — hoje já aberto: não feitas vêm do plano novo; feitas ficam (e só mudam de posição). */
    private function refreshToday(MealPlan $plan): void
    {
        $user = $plan->user()->firstOrFail();
        $today = $user->dayMeals()->whereDate('date', CarbonImmutable::today())->get();
        if ($today->isEmpty()) {
            return; // hoje ainda não aberto: materializa do plano novo na primeira leitura
        }

        $pending = $today->reject(fn (DayMeal $meal) => $meal->isDone());
        DayMeal::whereKey($pending->modelKeys())->delete(); // itens e alterações caem em cascata
        $this->days->build($user, $plan, CarbonImmutable::today(), save: true, onlySlots: $pending->pluck('slot')->all());

        $positions = $plan->meals()->pluck('position', 'slot');
        foreach ($today->filter(fn (DayMeal $meal) => $meal->isDone()) as $meal) {
            $meal->update(['position' => $positions[$meal->slot]]);
        }
    }
}
