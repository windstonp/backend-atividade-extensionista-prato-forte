<?php

namespace App\Services\Foods;

use App\Models\Food;
use App\Services\Nutrition\DayTotals;
use Illuminate\Support\Collection;

/** RN25 — opções de troca de um item. Puro sobre as coleções recebidas. */
final class SubstitutionFinder
{
    private const MAX_OPTIONS = 4;

    private const MAX_CALORIE_DIFF = 0.35;

    /**
     * Mesmo grupo, porção equivalente pelo macro principal (0,5–2× a porção típica, a 5 g),
     * ±35% das kcal; cozinha primeiro, depois menor diferença de kcal; até 4.
     *
     * @param  Collection<int, Food>  $allowed  alimentos permitidos (FoodFilter)
     * @param  list<int>  $pantryFoodIds
     * @return list<SubstitutionOption>
     */
    public function find(Food $original, float $grams, Collection $allowed, array $pantryFoodIds): array
    {
        $options = [];
        foreach ($allowed as $candidate) {
            $option = $this->optionFor($original, $grams, $candidate, $pantryFoodIds);
            if ($option !== null) {
                $options[] = $option;
            }
        }

        usort($options, fn (SubstitutionOption $a, SubstitutionOption $b) => [! $a->inPantry, abs($a->calorieDelta), $a->food->name]
            <=> [! $b->inPantry, abs($b->calorieDelta), $b->food->name]);

        return array_slice($options, 0, self::MAX_OPTIONS);
    }

    /**
     * A porção equivalente de um candidato (RN25), ou nada se não servir como troca.
     *
     * @param  list<int>  $pantryFoodIds
     */
    public function optionFor(Food $original, float $grams, Food $candidate, array $pantryFoodIds): ?SubstitutionOption
    {
        if ($candidate->id === $original->id || $candidate->group !== $original->group) {
            return null;
        }
        $before = DayTotals::item($original, $grams);
        $macro = $this->mainMacro($original->group);
        $target = $macro === 'calories' ? (float) $before['calories'] : $before[$macro];
        $per100 = $this->per100($candidate, $macro);
        if ($per100 <= 0) {
            return null;
        }

        $portion = min(max($target / $per100 * 100, 0.5 * $candidate->typical_portion_g), 2 * $candidate->typical_portion_g);
        $portion = max(5.0, min(600.0, round($portion / 5) * 5));
        $after = DayTotals::item($candidate, $portion);
        $delta = $after['calories'] - $before['calories'];
        if (abs($delta) > self::MAX_CALORIE_DIFF * $before['calories']) {
            return null;
        }

        return new SubstitutionOption($candidate, (float) $portion, $after, $delta, in_array($candidate->id, $pantryFoodIds, true));
    }

    /** Carboidrato troca por carboidrato; proteínas, laticínios e leguminosas por proteína; gorduras por gordura; o resto por kcal. */
    private function mainMacro(string $group): string
    {
        return match ($group) {
            'carboidrato' => 'carbs',
            'proteina', 'laticinio', 'leguminosa' => 'protein',
            'gordura' => 'fat',
            default => 'calories',
        };
    }

    private function per100(Food $food, string $macro): float
    {
        return match ($macro) {
            'carbs' => $food->carbs_per_100g,
            'protein' => $food->protein_per_100g,
            'fat' => $food->fat_per_100g,
            default => $food->kcal_per_100g,
        };
    }
}
