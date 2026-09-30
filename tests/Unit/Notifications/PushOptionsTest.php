<?php

use App\Models\User;
use App\Notifications\MealReminder;
use App\Notifications\NutriTip;
use App\Notifications\WeeklySummary;

it('aviso velho não chega depois da hora: lembrete vale 15 min, resumo e dica valem 1 dia', function ($aviso, int $ttl, string $urgencia) {
    $opcoes = $aviso->toWebPush(new User, $aviso)->getOptions();

    expect($opcoes['TTL'] ?? null)->toBe($ttl)->and($opcoes['urgency'] ?? null)->toBe($urgencia);
})->with([
    'lembrete' => [new MealReminder('almoco', 'Almoço', '12:30', 'Arroz e feijão'), 900, 'high'],
    'resumo' => [new WeeklySummary('1 de 7 dias completos'), 86400, 'normal'],
    'dica' => [new NutriTip('Segue assim!', '/evolucao'), 86400, 'normal'],
]);
