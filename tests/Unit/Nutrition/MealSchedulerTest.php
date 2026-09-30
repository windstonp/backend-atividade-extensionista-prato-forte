<?php

use App\Services\Nutrition\MealScheduler;

it('calcula os horários das refeições (RN14)', function (string $wake, string $training, string $sleep, bool $days, array $expected) {
    expect((new MealScheduler)->schedule($wake, $training, $sleep, $days))->toBe($expected);
})->with([
    'mock: acorda 06:20, treina 19:00 (CA04)' => ['06:20', '19:00', '23:00', true,
        ['cafe' => '07:00', 'lanche' => '10:00', 'almoco' => '12:30', 'pre-treino' => '17:30', 'jantar' => '20:30']],
    'treino cedo: pré-treino ao acordar e café depois do treino' => ['05:30', '06:30', '22:00', true,
        ['pre-treino' => '05:40', 'cafe' => '07:45', 'lanche' => '10:30', 'almoco' => '12:30', 'jantar' => '19:30']],
    'sem dias de treino: pré-treino às 16:00' => ['06:20', '19:00', '23:00', false,
        ['cafe' => '07:00', 'lanche' => '10:00', 'almoco' => '12:30', 'pre-treino' => '16:00', 'jantar' => '20:30']],
    'pré-treino perto do café e colisões empurradas de 30 em 30 min' => ['09:00', '12:00', '23:30', true,
        ['cafe' => '09:40', 'pre-treino' => '11:15', 'lanche' => '13:00', 'almoco' => '14:30', 'jantar' => '21:00']],
    'dorme depois da meia-noite' => ['10:00', '23:00', '01:30', true,
        ['cafe' => '10:40', 'lanche' => '12:30', 'almoco' => '14:00', 'pre-treino' => '21:30', 'jantar' => '00:30']],
    'arredonda a 5 min' => ['06:23', '18:10', '22:40', true,
        ['cafe' => '07:05', 'lanche' => '10:00', 'almoco' => '12:30', 'pre-treino' => '16:40', 'jantar' => '19:40']],
]);
