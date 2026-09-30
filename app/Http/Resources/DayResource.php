<?php

namespace App\Http\Resources;

use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Services\Days\DayView;
use App\Services\Nutrition\DayTotals;
use App\Services\Nutrition\MealSummary;
use App\Services\Nutrition\PortionFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O dia (spec 03 §5, `GET /days/{date}` e todas as escritas do dia).
 *
 * @property DayView $resource
 */
class DayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $view = $this->resource;
        $isToday = $view->date->isToday();
        $next = $isToday ? $view->meals->first(fn (DayMeal $meal) => ! $meal->isDone()) : null;

        $meals = $view->meals->map(fn (DayMeal $meal) => $this->meal($meal, $next !== null && $meal->slot === $next->slot));
        $planned = DayTotals::sum($meals->pluck('totals')->all());
        $consumed = DayTotals::sum($meals->filter(fn (array $meal) => $meal['done'])->pluck('totals')->all());

        return [
            'date' => $view->date->toDateString(),
            'is_today' => $isToday,
            'editable' => $isToday,
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
            'meals' => $meals->map(fn (array $meal) => array_diff_key($meal, ['totals' => true]))->all(),
            'last_change' => $view->lastChange ? [
                'id' => $view->lastChange->id,
                'text' => $view->lastChange->description,
                'undo_until' => $view->lastChange->created_at->addMinutes(15)->toIso8601String(),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function meal(DayMeal $meal, bool $isNext): array
    {
        $items = $meal->items->map(fn (DayMealItem $item) => $this->item($item));
        $totals = DayTotals::sum($items->pluck('totals')->all());

        return [
            'id' => $meal->id,
            'slot' => $meal->slot,
            'name' => $meal->name,
            'time' => substr((string) $meal->time, 0, 5),
            'note' => $meal->note,
            'position' => $meal->position,
            'done' => $meal->isDone(),
            'is_next' => $isNext,
            'summary' => MealSummary::of($items->pluck('name')->all()),
            'calories' => $totals['calories'],
            'macros' => ['protein' => $totals['protein'], 'carbs' => $totals['carbs'], 'fat' => $totals['fat']],
            'items' => $items->map(fn (array $item) => array_diff_key($item, ['totals' => true]))->all(),
            'totals' => $totals,
        ];
    }

    /** @return array<string, mixed> */
    private function item(DayMealItem $item): array
    {
        $totals = DayTotals::item($item->food, $item->grams);

        return [
            'id' => $item->id,
            'food_id' => $item->food_id,
            'name' => $item->food->name,
            'grams' => $item->grams,
            'amount' => app(PortionFormatter::class)->forFood($item->food, $item->grams),
            'calories' => $totals['calories'],
            'macros' => ['protein' => $totals['protein'], 'carbs' => $totals['carbs'], 'fat' => $totals['fat']],
            'source' => $item->source->value,
            'replaced_from' => $item->replacedFood?->name,
            'totals' => $totals,
        ];
    }
}
