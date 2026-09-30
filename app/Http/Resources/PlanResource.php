<?php

namespace App\Http\Resources;

use App\Enums\PlanStatus;
use App\Models\MealPlan;
use App\Models\PlanMeal;
use App\Models\PlanMealItem;
use App\Services\Nutrition\DayTotals;
use App\Services\Nutrition\MealSummary;
use App\Services\Nutrition\PortionFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plano (`GET /plans/{plan}`, `GET /plans/active`). Pronto: carregue `meals.items.food`.
 *
 * @mixin MealPlan
 */
class PlanResource extends JsonResource
{
    public const RELATIONS = ['meals.items.food', 'rating'];

    private bool $withItems = false;

    /** `GET /plans/active`: refeições com os itens. */
    public function withItems(): self
    {
        $this->withItems = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = ['id' => $this->id, 'status' => $this->status->value, 'is_active' => $this->is_active];

        if ($this->status === PlanStatus::Failed) {
            return $data + ['failure_reason' => $this->failure_reason];
        }
        if ($this->status !== PlanStatus::Ready) {
            return $data;
        }

        return $data + [
            'ready_at' => $this->ready_at?->toIso8601String(),
            'targets' => [
                'kcal' => $this->target_kcal, 'protein_g' => $this->target_protein_g,
                'carbs_g' => $this->target_carbs_g, 'fat_g' => $this->target_fat_g,
            ],
            'meals' => $this->meals->map(fn (PlanMeal $meal) => $this->meal($meal))->all(),
            'rating' => $this->resource->relationLoaded('rating') ? $this->resource->rating?->toPublic() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function meal(PlanMeal $meal): array
    {
        $items = $meal->items->map(fn (PlanMealItem $item) => [
            'food_id' => $item->food_id,
            'name' => $item->food->name,
            'grams' => $item->grams,
            'amount' => app(PortionFormatter::class)->forFood($item->food, $item->grams),
            'macros' => DayTotals::item($item->food, $item->grams),
        ]);

        $data = [
            'slot' => $meal->slot,
            'name' => $meal->name,
            'time' => substr((string) $meal->time, 0, 5),
            'calories' => DayTotals::sum($items->pluck('macros')->all())['calories'],
            'summary' => MealSummary::of($items->pluck('name')->all()),
        ];

        if ($this->withItems) {
            $data['items'] = $items->map(fn (array $item) => [
                'food_id' => $item['food_id'], 'name' => $item['name'], 'grams' => $item['grams'], 'amount' => $item['amount'],
                'calories' => $item['macros']['calories'],
                'macros' => ['protein' => $item['macros']['protein'], 'carbs' => $item['macros']['carbs'], 'fat' => $item['macros']['fat']],
            ])->all();
        }

        return $data;
    }
}
