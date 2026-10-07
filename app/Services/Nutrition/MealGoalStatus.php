<?php

namespace App\Services\Nutrition;

/**
 * RN48 — situação da refeição: calorias em [90%, 110%] da meta, proteína ≥ 90%, gordura ≤ 110%.
 * Meta batida = calorias ≥ 90% e proteína ≥ 90%; passar das calorias ou da gordura não desfaz (D13). Puro.
 */
final class MealGoalStatus
{
    /**
     * @param  array{calories: int, protein: float, carbs: float, fat: float}  $target
     * @param  array{calories: int, protein: float, carbs: float, fat: float}  $consumed
     * @return array{status: array{calories: string, protein: string, fat: string}, goal_met: bool}
     */
    public static function of(array $target, array $consumed): array
    {
        // Comparação em décimos para não depender de ponto flutuante nas bordas (405/450 é exatamente 90%).
        $calories = match (true) {
            $consumed['calories'] * 10 < $target['calories'] * 9 => 'below',
            $consumed['calories'] * 10 > $target['calories'] * 11 => 'above',
            default => 'ok',
        };
        $protein = round($consumed['protein'] * 10, 1) >= round($target['protein'] * 9, 1) ? 'ok' : 'below';
        $fat = round($consumed['fat'] * 10, 1) <= round($target['fat'] * 11, 1) ? 'ok' : 'above';

        return [
            'status' => ['calories' => $calories, 'protein' => $protein, 'fat' => $fat],
            'goal_met' => $calories !== 'below' && $protein === 'ok',
        ];
    }
}
