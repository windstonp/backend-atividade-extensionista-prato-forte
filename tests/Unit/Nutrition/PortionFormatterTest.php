<?php

use App\Services\Nutrition\PortionFormatter;

it('escreve a porção em gramas e em medida caseira', function (float $grams, ?string $unit, ?string $plural, ?float $unitGrams, string $expected) {
    expect((new PortionFormatter)->format($grams, $unit, $plural, $unitGrams))->toBe($expected);
})->with([
    'plural' => [150, 'colher de sopa', 'colheres de sopa', 25, '150 g, mais ou menos 6 colheres de sopa'],
    'singular' => [60, 'unidade', 'unidades', 60, '60 g, mais ou menos 1 unidade'],
    'meia medida' => [90, 'unidade', 'unidades', 60, '90 g, mais ou menos 1,5 unidades'],
    'menos de uma medida: só gramas' => [20, 'unidade', 'unidades', 60, '20 g'],
    'sem medida caseira' => [100, null, null, null, '100 g'],
    'gramas quebradas' => [58.5, null, null, null, '58,5 g'],
]);

it('líquido sai em ml com a medida caseira', function () {
    expect((new PortionFormatter)->format(200, 'copo', 'copos', 200, 'ml'))->toBe('200 ml, mais ou menos 1 copo')
        ->and((new PortionFormatter)->format(150, null, null, null, 'ml'))->toBe('150 ml')
        ->and((new PortionFormatter)->format(150, null, null, null))->toBe('150 g');
});
