<?php

use App\Services\Nutrition\MealSummary;

it('junta os nomes com vírgula e "e", minúsculos depois do primeiro (RN15)', function (array $nomes, string $esperado) {
    expect(MealSummary::of($nomes))->toBe($esperado);
})->with([
    [['Ovos mexidos', 'Pão francês', 'Mamão', 'Café sem açúcar'], 'Ovos mexidos, pão francês, mamão e café sem açúcar'],
    [['Frango grelhado', 'Arroz integral'], 'Frango grelhado e arroz integral'],
    [['Banana'], 'Banana'],
    [[], ''],
]);
