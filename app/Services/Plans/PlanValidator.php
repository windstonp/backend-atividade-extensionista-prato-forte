<?php

namespace App\Services\Plans;

use App\Enums\MealSlot;
use App\Models\Food;
use Illuminate\Support\Collection;

/** RN18 — aceita ou recusa o cardápio da IA. As mensagens vão de volta para ela. Puro. */
final class PlanValidator
{
    /**
     * @param  list<array{slot: string, items: list<array{food_id: int, grams: float}>}>  $meals
     * @param  Collection<int, Food>  $allowed  RN16 (chave = id)
     * @return list<string>
     */
    public function validate(array $meals, Collection $allowed, int $targetKcal, int $targetProteinG): array
    {
        $errors = [];

        $slots = array_column($meals, 'slot');
        $expected = array_map(fn (MealSlot $slot) => $slot->value, MealSlot::cases());
        sort($slots);
        sort($expected);
        if ($slots !== $expected) {
            $errors[] = 'Monte exatamente 5 refeições, uma para cada slot: cafe, lanche, almoco, pre-treino, jantar.';
        }

        $kcal = 0.0;
        $protein = 0.0;
        foreach ($meals as $meal) {
            $count = count($meal['items']);
            if ($count < 1 || $count > 6) {
                $errors[] = "A refeição {$meal['slot']} precisa ter de 1 a 6 itens.";
            }
            foreach ($meal['items'] as $item) {
                $food = $allowed->get($item['food_id']);
                if ($food === null) {
                    $errors[] = "O alimento {$item['food_id']} não está na lista permitida.";

                    continue;
                }
                if ($item['grams'] < 5 || $item['grams'] > 600) {
                    $errors[] = "O item {$item['food_id']} em {$meal['slot']} precisa ter entre 5 e 600 g.";
                }
                $kcal += $food->kcal_per_100g * $item['grams'] / 100;
                $protein += $food->protein_per_100g * $item['grams'] / 100;
            }
        }

        if (abs($kcal - $targetKcal) > 0.10 * $targetKcal) {
            $errors[] = sprintf('O total do dia ficou em %d kcal; a meta é %d kcal (±10%%).', round($kcal), $targetKcal);
        }
        if ($protein < 0.9 * $targetProteinG) {
            $errors[] = sprintf('A proteína do dia ficou em %d g; precisa de pelo menos %d g.', round($protein), ceil(0.9 * $targetProteinG));
        }

        return $errors;
    }
}
