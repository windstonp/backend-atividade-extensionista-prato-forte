<?php

namespace App\Services\Nutrition;

use App\Models\Food;

/**
 * RN24 — macros de itens, refeições e do dia. Puro (só lê os valores do alimento).
 *
 * @phpstan-type Macros array{calories: int, protein: float, carbs: float, fat: float}
 */
final class DayTotals
{
    /**
     * Macros de uma porção: kcal inteira, macros com 1 casa.
     *
     * @return Macros
     */
    public static function item(Food $food, float $grams): array
    {
        $factor = $grams / 100;

        return [
            'calories' => (int) round($food->kcal_per_100g * $factor),
            'protein' => round($food->protein_per_100g * $factor, 1),
            'carbs' => round($food->carbs_per_100g * $factor, 1),
            'fat' => round($food->fat_per_100g * $factor, 1),
        ];
    }

    /**
     * @param  list<Macros>  $parts
     * @return Macros
     */
    public static function sum(array $parts): array
    {
        $total = ['calories' => 0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0];
        foreach ($parts as $part) {
            $total['calories'] += $part['calories'];
            $total['protein'] += $part['protein'];
            $total['carbs'] += $part['carbs'];
            $total['fat'] += $part['fat'];
        }

        return self::rounded($total);
    }

    /**
     * Restante = max(0, planejado − consumido).
     *
     * @param  Macros  $planned
     * @param  Macros  $consumed
     * @return Macros
     */
    public static function remaining(array $planned, array $consumed): array
    {
        return self::rounded([
            'calories' => max(0, $planned['calories'] - $consumed['calories']),
            'protein' => max(0.0, $planned['protein'] - $consumed['protein']),
            'carbs' => max(0.0, $planned['carbs'] - $consumed['carbs']),
            'fat' => max(0.0, $planned['fat'] - $consumed['fat']),
        ]);
    }

    /**
     * @param  array{calories: int, protein: float, carbs: float, fat: float}  $macros
     * @return Macros
     */
    private static function rounded(array $macros): array
    {
        return [
            'calories' => $macros['calories'],
            'protein' => round($macros['protein'], 1),
            'carbs' => round($macros['carbs'], 1),
            'fat' => round($macros['fat'], 1),
        ];
    }
}
