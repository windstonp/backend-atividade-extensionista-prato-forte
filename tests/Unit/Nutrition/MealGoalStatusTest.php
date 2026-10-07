<?php

use App\Services\Nutrition\MealGoalStatus;

$meta = ['calories' => 450, 'protein' => 25.0, 'carbs' => 60.0, 'fat' => 12.0];
$com = fn (int $kcal, float $proteina = 25.0, float $gordura = 10.0) => ['calories' => $kcal, 'protein' => $proteina, 'carbs' => 50.0, 'fat' => $gordura];

it('calorias nas bordas de 90% e 110%', function (int $kcal, string $status, bool $batida) use ($meta, $com) {
    $r = MealGoalStatus::of($meta, $com($kcal));
    expect($r['status']['calories'])->toBe($status)->and($r['goal_met'])->toBe($batida);
})->with([
    [404, 'below', false],
    [405, 'ok', true],
    [495, 'ok', true],
    [496, 'above', true],   // passar das calorias não desfaz a meta (RN48, D13)
    [560, 'above', true],
]);

it('proteína abaixo de 90% impede a meta; acima não', function () use ($meta, $com) {
    expect(MealGoalStatus::of($meta, $com(450, 22.4))['status']['protein'])->toBe('below')
        ->and(MealGoalStatus::of($meta, $com(450, 22.4))['goal_met'])->toBeFalse()
        ->and(MealGoalStatus::of($meta, $com(450, 22.5))['status']['protein'])->toBe('ok')
        ->and(MealGoalStatus::of($meta, $com(450, 40.0))['status']['protein'])->toBe('ok');
});

it('gordura acima de 110% vira só aviso', function () use ($meta, $com) {
    $r = MealGoalStatus::of($meta, $com(450, 25.0, 13.3));
    expect($r['status']['fat'])->toBe('above')->and($r['goal_met'])->toBeTrue();
    expect(MealGoalStatus::of($meta, $com(450, 25.0, 13.2))['status']['fat'])->toBe('ok');
});

it('meta sem proteína ou sem gordura não acusa nada', function () {
    $r = MealGoalStatus::of(['calories' => 50, 'protein' => 0.0, 'carbs' => 10.0, 'fat' => 0.0], ['calories' => 50, 'protein' => 0.0, 'carbs' => 10.0, 'fat' => 0.0]);
    expect($r['status'])->toBe(['calories' => 'ok', 'protein' => 'ok', 'fat' => 'ok'])->and($r['goal_met'])->toBeTrue();
});
