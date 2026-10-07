<?php

namespace App\Services\Days;

use App\Enums\ErrorCode;
use App\Enums\ItemSource;
use App\Enums\MealSlot;
use App\Exceptions\DomainException;
use App\Models\DayMeal;
use App\Models\DayMealChange;
use App\Models\DayMealItem;
use App\Models\MealPlan;
use App\Models\PlanMeal;
use App\Models\User;
use App\Services\Foods\FoodFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** RN22 (hoje, futuro, passado) e RN15 (nome e nota de cada dia). */
class DayMaterializer
{
    public function __construct(private readonly FoodFilter $filter) {}

    public function view(User $user, CarbonImmutable $date): DayView
    {
        $meals = $this->meals($user, $date);
        $first = $meals->first();
        $plan = $first !== null ? $first->plan : $user->activePlan()->first();

        return new DayView(
            $date,
            $meals,
            $plan,
            $meals->isNotEmpty() && $meals->first()->exists,
            $this->isTrainingDay($user, $date),
            $date->isToday() ? DayMealChange::undoableFor($user, $date)->first() : null,
        );
    }

    /**
     * Refeições da data, em ordem de horário, com `items.food` e `items.replacedFood`.
     *
     * @return Collection<int, DayMeal>
     */
    public function meals(User $user, CarbonImmutable $date): Collection
    {
        $stored = $this->stored($user, $date);
        $yesterday = CarbonImmutable::today()->subDay();
        if ($stored->isNotEmpty() || $date->lt($yesterday)) {
            return $stored; // antes de ontem: o que foi gravado (ou nada)
        }

        $plan = $user->activePlan()->with('meals.items.food')->first() ?? throw $this->noActivePlan($user);

        if (! $date->isToday() && ! $date->isYesterday()) {
            return $this->build($user, $plan, $date, save: false); // futuro: prévia, sem gravar (RN22)
        }
        // Hoje e ontem (editável, D13) são gravados na primeira leitura: a sugestão precisa de ids para o "+".

        try {
            DB::transaction(fn () => $this->build($user, $plan, $date, save: true));
        } catch (UniqueConstraintViolationException) {
            // Outra aba materializou ao mesmo tempo: fica o que ela gravou.
        }

        return $this->stored($user, $date);
    }

    /**
     * Refeições do dia a partir do plano (RN15: "Lanche da tarde" sem treino; nota do jantar pós-treino).
     *
     * @param  list<string>|null  $onlySlots
     * @return Collection<int, DayMeal>
     */
    public function build(User $user, MealPlan $plan, CarbonImmutable $date, bool $save, ?array $onlySlots = null): Collection
    {
        $plan->loadMissing('meals.items.food');
        $allowed = $this->filter->allowedFor($user); // RN17: o plano pode ser de antes de uma restrição nova
        $training = $this->isTrainingDay($user, $date);
        $trainingTime = substr((string) $user->profile->training_time, 0, 5);

        return $plan->meals
            ->filter(fn (PlanMeal $meal) => $onlySlots === null || in_array($meal->slot, $onlySlots, true))
            ->map(function (PlanMeal $planMeal) use ($user, $plan, $date, $save, $training, $trainingTime, $allowed) {
                $meal = new DayMeal([
                    'user_id' => $user->id,
                    'date' => $date,
                    'meal_plan_id' => $plan->id,
                    'slot' => $planMeal->slot,
                    'name' => $planMeal->slot === MealSlot::PreTreino->value && ! $training ? 'Lanche da tarde' : $planMeal->name,
                    'time' => substr((string) $planMeal->time, 0, 5),
                    'note' => $this->note($planMeal, $training, $trainingTime),
                    'position' => $planMeal->position,
                ]);
                $meal->setRelation('plan', $plan);

                $items = $planMeal->items->filter(fn ($planItem) => $allowed->has($planItem->food_id))->map(fn ($planItem) => (new DayMealItem([
                    'food_id' => $planItem->food_id,
                    'grams' => $planItem->grams,
                    'source' => ItemSource::Plan,
                    'position' => $planItem->position,
                ]))->setRelation('food', $planItem->food)->setRelation('replacedFood', null));

                if ($save) {
                    $meal->save();
                    $meal->items()->saveMany($items);
                }

                return $meal->setRelation('items', $items->values());
            })
            ->values();
    }

    /**
     * Refeições onde se vai escrever (hoje ou ontem): `meals()` já as grava na primeira leitura.
     *
     * @return Collection<int, DayMeal>
     */
    public function mealsForWriting(User $user, CarbonImmutable $date): Collection
    {
        return $this->meals($user, $date);
    }

    /** RN17/RN21 — restrição nova: sai na hora das refeições de hoje ainda não feitas, sem esperar o plano novo. */
    public function dropForbiddenToday(User $user): void
    {
        $allowed = $this->filter->allowedFor($user);

        DayMealItem::query()
            ->whereHas('dayMeal', fn ($query) => $query->where('user_id', $user->id)->whereDate('date', CarbonImmutable::today())->whereNull('done_at'))
            ->whereNotIn('food_id', $allowed->keys())
            ->delete();
    }

    public function isTrainingDay(User $user, CarbonImmutable $date): bool
    {
        return in_array($date->dayOfWeek, $user->profile->training_days, true);
    }

    /** @return Collection<int, DayMeal> */
    private function stored(User $user, CarbonImmutable $date): Collection
    {
        return $user->dayMeals()
            ->whereDate('date', $date)
            ->with(['items.food', 'items.replacedFood', 'plan', 'entries.food'])
            ->orderBy('position')
            ->get();
    }

    /** "Depois do treino das 19h" no jantar que vem depois do treino, em dia de treino. */
    private function note(PlanMeal $meal, bool $training, string $trainingTime): ?string
    {
        if (! $training || $meal->slot !== MealSlot::Jantar->value) {
            return null;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $trainingTime));
        [$mealHours, $mealMinutes] = array_map('intval', explode(':', substr((string) $meal->time, 0, 5)));
        $after = ((($mealHours * 60 + $mealMinutes) - ($hours * 60 + $minutes)) + 1440) % 1440;

        return $after > 0 && $after < 12 * 60
            ? 'Depois do treino das '.$hours.'h'.($minutes > 0 ? sprintf('%02d', $minutes) : '')
            : null;
    }

    private function noActivePlan(User $user): DomainException
    {
        $latest = $user->mealPlans()->latest('id')->first();

        return new DomainException(ErrorCode::NoActivePlan, ['plan_status' => $latest?->status->value, 'plan_id' => $latest?->id]);
    }
}
