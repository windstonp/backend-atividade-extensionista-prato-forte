<?php

namespace App\Services\Nutrition;

use App\Enums\Goal;
use App\Enums\GoalWeightSource;

/** RN10 (meta de peso) e RN11 (troca de objetivo). Puro: sem banco, sem relógio. */
final class GoalWeightResolver
{
    public const OUT_OF_RANGE = 'GOAL_WEIGHT_OUT_OF_HEALTHY_RANGE';

    public const RESET = 'GOAL_WEIGHT_RESET';

    private const BMI_MIN = 18.5;

    private const BMI_MAX = 24.9;

    /**
     * Faixa de IMC 18,5–24,9 para a altura, em kg com 1 casa.

     *

     * @return array{min: float, max: float}
     */
    public function healthyRange(int $heightCm): array
    {
        $squared = ($heightCm / 100) ** 2;

        return ['min' => round(self::BMI_MIN * $squared, 1), 'max' => round(self::BMI_MAX * $squared, 1)];
    }

    public function isOutOfRange(float $goalKg, int $heightCm): bool
    {
        ['min' => $min, 'max' => $max] = $this->healthyRange($heightCm);

        return $goalKg < $min || $goalKg > $max;
    }

    /** Meta quando o usuário não informou: `auto` (manter), sugerida (ganhar/perder) ou nenhuma (disposição). */
    public function suggest(Goal $goal, float $weightKg, int $heightCm): ?GoalWeight
    {
        return match ($goal) {
            Goal::ManterPeso => new GoalWeight($weightKg, GoalWeightSource::Auto),
            Goal::MaisDisposicao => null,
            Goal::GanharMassa, Goal::PerderGordura => new GoalWeight($this->suggested($goal, $weightKg, $heightCm), GoalWeightSource::Suggested),
        };
    }

    /** A meta combina com o objetivo? (direção de RN10; `null` só serve para disposição). */
    public function fits(Goal $goal, float $weightKg, ?float $goalKg): bool
    {
        return match ($goal) {
            Goal::GanharMassa => $goalKg !== null && $goalKg > $weightKg,
            Goal::PerderGordura => $goalKg !== null && $goalKg < $weightKg,
            Goal::ManterPeso => $goalKg !== null && abs($goalKg - $weightKg) < 0.05,
            Goal::MaisDisposicao => $goalKg === null,
        };
    }

    /** ±5% arredondado a 0,5 kg; limitado à faixa saudável quando o peso atual está nela e o limite não inverte a direção. */
    private function suggested(Goal $goal, float $weightKg, int $heightCm): float
    {
        $kg = $this->toHalf($weightKg * ($goal === Goal::GanharMassa ? 1.05 : 0.95));

        ['min' => $min, 'max' => $max] = $this->healthyRange($heightCm);
        if ($weightKg < $min || $weightKg > $max) {
            return $kg;
        }

        $clamped = min(max($kg, ceil($min * 2) / 2), floor($max * 2) / 2);

        return $this->fits($goal, $weightKg, $clamped) ? $clamped : $kg;
    }

    private function toHalf(float $kg): float
    {
        return round($kg * 2) / 2;
    }
}
