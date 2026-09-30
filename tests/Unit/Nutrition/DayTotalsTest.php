<?php

use App\Models\Food;
use App\Services\Nutrition\DayTotals;

function food(float $kcal, float $protein, float $carbs, float $fat): Food
{
    return new Food(['kcal_per_100g' => $kcal, 'protein_per_100g' => $protein, 'carbs_per_100g' => $carbs, 'fat_per_100g' => $fat]);
}

it('calcula o item pela porção: kcal inteira, macros com 1 casa (RN24)', function () {
    expect(DayTotals::item(food(159, 32.0, 0.0, 2.5), 120))->toBe(['calories' => 191, 'protein' => 38.4, 'carbs' => 0.0, 'fat' => 3.0])
        ->and(DayTotals::item(food(128, 2.5, 28.1, 0.2), 100))->toBe(['calories' => 128, 'protein' => 2.5, 'carbs' => 28.1, 'fat' => 0.2]);
});

it('soma partes e calcula o restante sem ficar negativo', function () {
    $planejado = DayTotals::sum([
        ['calories' => 191, 'protein' => 38.4, 'carbs' => 0.0, 'fat' => 3.0],
        ['calories' => 128, 'protein' => 2.5, 'carbs' => 28.1, 'fat' => 0.2],
    ]);
    $consumido = ['calories' => 400, 'protein' => 10.0, 'carbs' => 30.0, 'fat' => 1.0];

    expect($planejado)->toBe(['calories' => 319, 'protein' => 40.9, 'carbs' => 28.1, 'fat' => 3.2])
        ->and(DayTotals::remaining($planejado, $consumido))->toBe(['calories' => 0, 'protein' => 30.9, 'carbs' => 0.0, 'fat' => 2.2])
        ->and(DayTotals::sum([]))->toBe(['calories' => 0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0]);
});
