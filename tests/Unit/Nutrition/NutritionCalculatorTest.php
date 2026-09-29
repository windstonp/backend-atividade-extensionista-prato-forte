<?php

use App\Enums\ActivityLevel;
use App\Enums\Goal;
use App\Enums\Sex;
use App\Enums\WorkPosture;
use App\Services\Nutrition\NutritionCalculator;

it('calcula as metas diárias (RN13)', function (Goal $goal, Sex $sex, int $age, int $height, float $weight, ActivityLevel $activity, WorkPosture $posture, array $expected) {
    $targets = (new NutritionCalculator)->dailyTargets($goal, $sex, $age, $height, $weight, $activity, $posture);

    expect([$targets->kcal, $targets->proteinG, $targets->carbsG, $targets->fatG])->toBe($expected);
})->with([
    // TMB 1.313 × 1,55 × 1,10 = 2.238,7 → 2.250; P 116,8 → 115; G 62,5 → 65; C 305,1 → 305
    'Camila do mock (ganhar)' => [Goal::GanharMassa, Sex::Feminino, 27, 164, 58.4, ActivityLevel::Moderado, WorkPosture::Sentada, [2250, 115, 305, 65]],
    // TMB 1.862,5 × 1,425 × 0,85 = 2.255,9 → 2.250
    'perder, em pé' => [Goal::PerderGordura, Sex::Masculino, 35, 178, 92.0, ActivityLevel::Leve, WorkPosture::EmPe, [2250, 185, 215, 75]],
    // TMB 1.459,5 × 1,825 = 2.663,6 → 2.650; −78 para "não dizer"
    'manter, peso pesado' => [Goal::ManterPeso, Sex::NaoDizer, 45, 170, 70.0, ActivityLevel::Intenso, WorkPosture::PesoPesado, [2650, 110, 385, 75]],
    // 926,5 × 1,2 = 1.111,8 → piso 1.200
    'piso feminino' => [Goal::MaisDisposicao, Sex::Feminino, 60, 150, 45.0, ActivityLevel::Parado, WorkPosture::Sentada, [1200, 70, 145, 35]],
    'piso no déficit' => [Goal::PerderGordura, Sex::Feminino, 70, 150, 40.0, ActivityLevel::Parado, WorkPosture::Sentada, [1200, 80, 145, 35]],
    // fator 1,725 + 0,1 = 1,825 (abaixo do teto 1,9)
    'ganhar, intenso' => [Goal::GanharMassa, Sex::Masculino, 20, 185, 70.0, ActivityLevel::Intenso, WorkPosture::PesoPesado, [3550, 140, 525, 100]],
    // carboidrato não cabe: fica em 100 g e a gordura no mínimo de 0,8 g/kg
    'carboidrato mínimo' => [Goal::PerderGordura, Sex::Masculino, 30, 160, 150.0, ActivityLevel::Parado, WorkPosture::Sentada, [2400, 300, 100, 120]],
]);
