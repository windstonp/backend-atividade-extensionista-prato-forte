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
     * Macros de uma porção a partir de valores por 100 (g ou ml): kcal inteira, macros com 1 casa.
     *
     * @return Macros
     */
    public static function portion(float $kcal, float $protein, float $carbs, float $fat, float $amount): array
    {
        $factor = $amount / 100;

        return [
            'calories' => (int) round($kcal * $factor),
            'protein' => round($protein * $factor, 1),
            'carbs' => round($carbs * $factor, 1),
            'fat' => round($fat * $factor, 1),
        ];
    }

    /**
     * Macros de um item do catálogo (por 100 g ou 100 ml — RN47).
     *
     * @return Macros
     */
    public static function item(Food $food, float $grams): array
    {
        return self::portion($food->kcal_per_100g, $food->protein_per_100g, $food->carbs_per_100g, $food->fat_per_100g, $grams);
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
