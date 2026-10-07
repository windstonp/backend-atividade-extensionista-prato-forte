<?php

use App\Services\Plans\PortionAdjuster;

require_once __DIR__.'/cardapio.php';

it('escala todas as porções quando o desvio é de até 25% (RN18)', function () {
    // cardápio: 1.249 kcal; meta 1.500 → desvio de 16,7%
    $ajustado = (new PortionAdjuster)->adjust(cardapioDeTeste(), alimentosDeTeste(), 1500);

    expect(abs(kcalDoCardapio($ajustado) - 1500) / 1500)->toBeLessThan(0.02)
        ->and($ajustado[2]['items'][0]['grams'])->toBe(240.0)
        ->and(collect($ajustado)->flatMap(fn ($m) => $m['items'])->every(fn ($i) => fmod($i['grams'], 5) == 0))->toBeTrue();
});

it('não mexe quando o desvio passa de 25% ou já está na meta', function (int $meta) {
    expect((new PortionAdjuster)->adjust(cardapioDeTeste(), alimentosDeTeste(), $meta))->toBe(cardapioDeTeste());
})->with([2000, 1249]);

it('ao escalar, não passa nenhuma porção de 2,5× a de costume', function () {
    $alimentos = alimentosDeTeste();
    $alimentos[1]->typical_portion_g = 80; // arroz de teste: até 200 g

    $ajustado = (new PortionAdjuster)->adjust(cardapioDeTeste(), $alimentos, 1500);

    expect($ajustado[2]['items'][0]['grams'])->toBe(200.0) // ficaria 240 g
        ->and($ajustado[0]['items'][0]['grams'])->toBe(120.0); // os outros escalam normalmente
});
