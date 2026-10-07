<?php

namespace App\Http\Resources;

use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Models\Food;
use App\Models\MealEntry;
use App\Models\User;
use App\Services\Days\DayView;
use App\Services\Foods\FoodFilter;
use App\Services\Nutrition\DayTotals;
use App\Services\Nutrition\MealGoalStatus;
use App\Services\Nutrition\MealSummary;
use App\Services\Nutrition\PortionFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * O dia (spec 03 §5 + spec 09 §5): `items` = sugestão, `calories`/`macros` = meta da refeição,
 * `done` = tem registro, `entries`/`consumed`/`status`/`goal_met` = o que foi comido.
 *
 * @property DayView $resource
 */
class DayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $view = $this->resource;
        /** @var User $user */
        $user = $request->user();
        $isToday = $view->date->isToday();
        $next = $isToday ? $view->meals->first(fn (DayMeal $meal) => ! $this->entriesOf($meal)->isNotEmpty()) : null;

        // Alimentos do catálogo usados nos registros: rótulos de restrição para avisar (RN51).
        $foodIds = $view->meals->flatMap(fn (DayMeal $meal) => $this->entriesOf($meal))->pluck('food_id')->filter()->unique()->values();
        $conflicts = $foodIds->isEmpty() ? [] : app(FoodFilter::class)->conflictsFor($user, Food::whereIn('id', $foodIds)->get()->keyBy('id'));

        $meals = $view->meals->map(fn (DayMeal $meal) => $this->meal($meal, $next !== null && $meal->slot === $next->slot, $conflicts));
        $planned = DayTotals::sum($meals->pluck('target')->all());
        $consumed = DayTotals::sum($meals->pluck('consumed')->all());

        return [
            'date' => $view->date->toDateString(),
            'is_today' => $isToday,
            'editable' => $isToday || $view->date->isYesterday(), // RN23 (D13): registro hoje e ontem
            'materialized' => $view->materialized,
            'is_training_day' => $view->isTrainingDay,
            'targets' => $view->plan ? [
                'kcal' => $view->plan->target_kcal, 'protein_g' => $view->plan->target_protein_g,
                'carbs_g' => $view->plan->target_carbs_g, 'fat_g' => $view->plan->target_fat_g,
            ] : null,
            'totals' => [
                'planned' => $planned,
                'consumed' => $consumed,
                'remaining' => DayTotals::remaining($planned, $consumed),
            ],
            'meals' => $meals->map(fn (array $meal) => array_diff_key($meal, ['target' => true]))->all(),
            'last_change' => $view->lastChange ? [
                'id' => $view->lastChange->id,
                'text' => $view->lastChange->description,
                'undo_until' => $view->lastChange->created_at->addMinutes(15)->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * Prévias (ontem sem gravar, futuro) não têm registros carregados.
     *
     * @return Collection<int, MealEntry>
     */
    private function entriesOf(DayMeal $meal): Collection
    {
        return $meal->exists && $meal->relationLoaded('entries') ? $meal->entries : collect();
    }

    /**
     * @param  array<int, list<string>>  $conflicts
     * @return array<string, mixed>
     */
    private function meal(DayMeal $meal, bool $isNext, array $conflicts): array
    {
        $entries = $this->entriesOf($meal);
        $registered = $entries->pluck('suggestion_item_id')->filter()->all();
        $target = $meal->target();
        $consumed = DayTotals::sum($entries->map(fn (MealEntry $entry) => $entry->macros())->values()->all());
        $situation = $entries->isEmpty() ? null : MealGoalStatus::of($target, $consumed);

        return [
            'id' => $meal->id,
            'slot' => $meal->slot,
            'name' => $meal->name,
            'time' => substr((string) $meal->time, 0, 5),
            'note' => $meal->note,
            'position' => $meal->position,
            'done' => $entries->isNotEmpty(), // RN46
            'is_next' => $isNext,
            'summary' => MealSummary::of($meal->items->map(fn (DayMealItem $item) => $item->food->name)->all()),
            'calories' => $target['calories'], // meta da refeição (RN48)
            'macros' => ['protein' => $target['protein'], 'carbs' => $target['carbs'], 'fat' => $target['fat']],
            'consumed' => $consumed,
            'status' => $situation['status'] ?? null,
            'goal_met' => $situation['goal_met'] ?? false,
            'items' => $meal->items->map(fn (DayMealItem $item) => $this->item($item, in_array($item->id, $registered, true)))->values()->all(),
            'entries' => $entries->map(fn (MealEntry $entry) => $this->entry($entry, $conflicts))->values()->all(),
            'target' => $target,
        ];
    }

    /** @return array<string, mixed> */
    private function item(DayMealItem $item, bool $registered): array
    {
        $totals = DayTotals::item($item->food, $item->grams);

        return [
            'id' => $item->id,
            'food_id' => $item->food_id,
            'name' => $item->food->name,
            'grams' => $item->grams,
            'measure' => $item->food->measure,
            'amount' => app(PortionFormatter::class)->forFood($item->food, $item->grams),
            'calories' => $totals['calories'],
            'macros' => ['protein' => $totals['protein'], 'carbs' => $totals['carbs'], 'fat' => $totals['fat']],
            'source' => $item->source->value,
            'replaced_from' => $item->replacedFood?->name,
            'registered' => $registered,
        ];
    }

    /**
     * @param  array<int, list<string>>  $conflicts
     * @return array<string, mixed>
     */
    private function entry(MealEntry $entry, array $conflicts): array
    {
        $food = $entry->food_id !== null ? $entry->food : null;

        return [
            'id' => $entry->id,
            'food_id' => $entry->food_id,
            'custom_food_id' => $entry->custom_food_id,
            'suggestion_item_id' => $entry->suggestion_item_id,
            'name' => $entry->name,
            'amount' => $entry->amount,
            'measure' => $entry->measure,
            'amount_text' => app(PortionFormatter::class)->format($entry->amount, $food?->unit_label, $food?->unit_label_plural, $food?->unit_grams, $entry->measure),
            'calories' => $entry->calories,
            'macros' => ['protein' => $entry->protein, 'carbs' => $entry->carbs, 'fat' => $entry->fat],
            'conflicts' => $entry->food_id !== null ? ($conflicts[$entry->food_id] ?? []) : [],
        ];
    }
}
