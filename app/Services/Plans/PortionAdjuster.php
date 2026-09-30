<?php

namespace App\Services\Plans;

use App\Models\Food;
use Illuminate\Support\Collection;

/** RN18 — escala uniforme das porções do dia para bater a meta de kcal. Puro. */
final class PortionAdjuster
{
    private const MAX_DEVIATION = 0.25;

    /**
     * Só escala quando o desvio é de até 25% (mais que isso é erro da IA, não arredondamento).
     *
     * @param  list<array{slot: string, items: list<array{food_id: int, grams: float}>}>  $meals
     * @param  Collection<int, Food>  $foods
     * @return list<array{slot: string, items: list<array{food_id: int, grams: float}>}>
     */
    public function adjust(array $meals, Collection $foods, int $targetKcal): array
    {
        $total = 0.0;
        foreach ($meals as $meal) {
            foreach ($meal['items'] as $item) {
                $food = $foods->get($item['food_id']);
                $total += $food ? $food->kcal_per_100g * $item['grams'] / 100 : 0;
            }
        }
        if ($total <= 0 || $targetKcal <= 0) {
            return $meals;
        }

        $deviation = abs($total - $targetKcal) / $targetKcal;
        if ($deviation < 0.005 || $deviation > self::MAX_DEVIATION) {
            return $meals;
        }

        $factor = $targetKcal / $total;

        return array_map(fn (array $meal) => [
            'slot' => $meal['slot'],
            'items' => array_map(fn (array $item) => [
                'food_id' => $item['food_id'],
                'grams' => max(5.0, round($item['grams'] * $factor / 5) * 5),
            ], $meal['items']),
        ], $meals);
    }
}
