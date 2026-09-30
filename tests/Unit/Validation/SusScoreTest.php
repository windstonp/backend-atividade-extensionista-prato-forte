<?php

use App\Services\Validation\SusScore;

it('calcula o SUS: exemplo do CA05, neutro e extremos', function (array $respostas, float $sus) {
    expect(SusScore::of($respostas))->toBe($sus);
})->with([
    'CA05' => [[4, 2, 5, 1, 4, 2, 5, 1, 4, 2], 85.0],
    'tudo 3' => [[3, 3, 3, 3, 3, 3, 3, 3, 3, 3], 50.0],
    'melhor' => [[5, 1, 5, 1, 5, 1, 5, 1, 5, 1], 100.0],
    'pior' => [[1, 5, 1, 5, 1, 5, 1, 5, 1, 5], 0.0],
]);
