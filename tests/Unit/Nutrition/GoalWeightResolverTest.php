<?php

use App\Enums\Goal;
use App\Enums\GoalWeightSource;
use App\Services\Nutrition\GoalWeightResolver;

beforeEach(fn () => $this->resolver = new GoalWeightResolver);

it('calcula a faixa saudável pelo IMC 18,5–24,9, com 1 casa', function (int $height, float $min, float $max) {
    expect($this->resolver->healthyRange($height))->toBe(['min' => $min, 'max' => $max]);
})->with([
    '1,64 m (exemplo da spec)' => [164, 49.8, 67.0],
    '1,20 m' => [120, 26.6, 35.9],
    '1,78 m' => [178, 58.6, 78.9],
    '2,30 m' => [230, 97.9, 131.7],
]);

it('avisa quando a meta sai da faixa (sem bloquear)', function (float $goal, bool $out) {
    expect($this->resolver->isOutOfRange($goal, 164))->toBe($out);
})->with([[49.7, true], [49.8, false], [67.0, false], [75.0, true]]);

it('sugere a meta quando o usuário não informa (RN10)', function (Goal $goal, float $weight, int $height, ?float $kg, ?GoalWeightSource $source) {
    $result = $this->resolver->suggest($goal, $weight, $height);

    expect($result?->kg)->toBe($kg)->and($result?->source)->toBe($source);
})->with([
    'ganhar: 58,4 × 1,05 = 61,32 → 61,5 (CA04)' => [Goal::GanharMassa, 58.4, 164, 61.5, GoalWeightSource::Suggested],
    'perder: 58,4 × 0,95 = 55,48 → 55,5' => [Goal::PerderGordura, 58.4, 164, 55.5, GoalWeightSource::Suggested],
    'ganhar perto do teto: limita a 67,0' => [Goal::GanharMassa, 66.0, 164, 67.0, GoalWeightSource::Suggested],
    'perder perto do piso: limita a 50,0' => [Goal::PerderGordura, 50.5, 164, 50.0, GoalWeightSource::Suggested],
    'ganhar já no teto: não limita (a meta não pode ser ≤ peso)' => [Goal::GanharMassa, 67.0, 164, 70.5, GoalWeightSource::Suggested],
    'perder fora da faixa: não limita' => [Goal::PerderGordura, 92.0, 178, 87.5, GoalWeightSource::Suggested],
    'manter: meta = peso' => [Goal::ManterPeso, 70.0, 170, 70.0, GoalWeightSource::Auto],
    'disposição: sem meta' => [Goal::MaisDisposicao, 70.0, 170, null, null],
]);

it('diz se a meta combina com o objetivo (RN10, RN11)', function (Goal $goal, ?float $goalKg, bool $fits) {
    expect($this->resolver->fits($goal, 58.4, $goalKg))->toBe($fits);
})->with([
    'ganhar acima' => [Goal::GanharMassa, 62.0, true],
    'ganhar abaixo' => [Goal::GanharMassa, 55.0, false],
    'ganhar sem meta' => [Goal::GanharMassa, null, false],
    'perder abaixo' => [Goal::PerderGordura, 55.0, true],
    'perder acima' => [Goal::PerderGordura, 62.0, false],
    'manter igual' => [Goal::ManterPeso, 58.4, true],
    'manter diferente' => [Goal::ManterPeso, 60.0, false],
    'disposição sem meta' => [Goal::MaisDisposicao, null, true],
    'disposição com meta' => [Goal::MaisDisposicao, 60.0, false],
]);
