<?php

use App\Services\Plans\PlanValidator;

require_once __DIR__.'/cardapio.php';

// cardapioDeTeste(): 1.249 kcal e 109,85 g de proteína
function problemas(array $meals, int $kcal = 1250, int $proteina = 110): string
{
    return implode("\n", (new PlanValidator)->validate($meals, alimentosDeTeste(), $kcal, $proteina));
}

it('aceita o cardápio que cumpre RN18', function () {
    expect((new PlanValidator)->validate(cardapioDeTeste(), alimentosDeTeste(), 1250, 110))->toBe([]);
});

it('aponta cada regra quebrada (RN18)', function (Closure $estraga, string $trecho, int $kcal, int $proteina) {
    expect(problemas($estraga(cardapioDeTeste()), $kcal, $proteina))->toContain($trecho);
})->with([
    'faltando refeição' => [fn ($m) => array_slice($m, 0, 4), 'exatamente 5 refeições', 1250, 110],
    'refeição repetida' => [fn ($m) => [...array_slice($m, 1), $m[4]], 'exatamente 5 refeições', 1250, 110],
    'refeição vazia' => [function ($m) {
        $m[0]['items'] = [];

        return $m;
    }, 'de 1 a 6 itens', 1250, 110],
    'refeição com 7 itens' => [function ($m) {
        $m[0]['items'] = array_fill(0, 7, ['food_id' => 3, 'grams' => 10.0]);

        return $m;
    }, 'de 1 a 6 itens', 1250, 110],
    'alimento fora da lista' => [function ($m) {
        $m[0]['items'][0]['food_id'] = 99;

        return $m;
    }, 'não está na lista permitida', 1250, 110],
    'porção de 4 g' => [function ($m) {
        $m[0]['items'][0]['grams'] = 4.0;

        return $m;
    }, 'entre 5 e 600 g', 1250, 110],
    'porção de 601 g' => [function ($m) {
        $m[0]['items'][0]['grams'] = 601.0;

        return $m;
    }, 'entre 5 e 600 g', 1250, 110],
    'kcal longe da meta' => [fn ($m) => $m, 'meta é 2000 kcal', 2000, 110],
    'proteína baixa' => [fn ($m) => $m, 'pelo menos 135', 1250, 150],
]);
