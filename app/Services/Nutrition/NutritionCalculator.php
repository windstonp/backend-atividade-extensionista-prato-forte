<?php

namespace App\Services\Nutrition;

use App\Enums\ActivityLevel;
use App\Enums\Goal;
use App\Enums\Sex;
use App\Enums\WorkPosture;

/** RN13 — metas diárias. Puro; usado pela prévia (`/plans/preview-targets`) e, no Plano 04, pela geração. */
final class NutritionCalculator
{
    private const MAX_FACTOR = 1.9;

    private const MIN_CARBS_G = 100;

    public function dailyTargets(Goal $goal, Sex $sex, int $age, int $heightCm, float $weightKg, ActivityLevel $activity, WorkPosture $posture): DailyTargets
    {
        $bmr = 10 * $weightKg + 6.25 * $heightCm - 5 * $age + $this->sexOffset($sex);
        $factor = min($activity->factor() + $posture->bonus(), self::MAX_FACTOR);
        $kcal = $this->roundTo(max($bmr * $factor * $this->goalAdjustment($goal), $this->floor($sex)), 50);

        $protein = $this->proteinPerKg($goal) * $weightKg;
        $minFat = 0.8 * $weightKg;
        $fat = max(0.25 * $kcal / 9, $minFat);
        $carbs = ($kcal - 4 * $protein - 9 * $fat) / 4;

        if ($carbs < self::MIN_CARBS_G) {
            // Carboidrato no mínimo; a gordura cede até o mínimo de 0,8 g/kg.
            $fat = max($minFat, ($kcal - 4 * $protein - 4 * self::MIN_CARBS_G) / 9);
            $carbs = max(self::MIN_CARBS_G, ($kcal - 4 * $protein - 9 * $fat) / 4);
        }

        return new DailyTargets($kcal, $this->roundTo($protein, 5), $this->roundTo($carbs, 5), $this->roundTo($fat, 5));
    }

    private function sexOffset(Sex $sex): int
    {
        return match ($sex) {
            Sex::Masculino => 5,
            Sex::Feminino => -161,
            Sex::NaoDizer => -78,
        };
    }

    private function goalAdjustment(Goal $goal): float
    {
        return match ($goal) {
            Goal::GanharMassa => 1.10,
            Goal::PerderGordura => 0.85,
            Goal::ManterPeso, Goal::MaisDisposicao => 1.0,
        };
    }

    private function floor(Sex $sex): int
    {
        return $sex === Sex::Masculino ? 1500 : 1200;
    }

    private function proteinPerKg(Goal $goal): float
    {
        return $goal === Goal::GanharMassa || $goal === Goal::PerderGordura ? 2.0 : 1.6;
    }

    private function roundTo(float $value, int $step): int
    {
        return (int) (round($value / $step) * $step);
    }
}
