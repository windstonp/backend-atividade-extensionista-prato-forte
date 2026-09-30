<?php

use App\Services\Progress\AdherenceCalculator;
use Carbon\CarbonImmutable;

/** O padrão de 28 dias do mock, do mais antigo (hoje − 27) até hoje. */
const PADRAO_DO_MOCK = [
    'completo', 'completo', 'parcial', 'completo', 'completo', 'vazio', 'completo',
    'completo', 'parcial', 'completo', 'completo', 'completo', 'completo', 'vazio',
    'completo', 'completo', 'completo', 'parcial', 'completo', 'completo', 'completo',
    'completo', 'completo', 'vazio', 'completo', 'completo', 'completo', 'hoje',
];

beforeEach(fn () => $this->hoje = CarbonImmutable::parse('2026-09-21'));

/** Dias materializados que produzem o padrão (vazio = não materializado ou nada feito). */
function diasDoPadrao(CarbonImmutable $hoje): array
{
    $dias = [];
    foreach (PADRAO_DO_MOCK as $i => $status) {
        $data = $hoje->subDays(27 - $i)->toDateString();
        $dias[$data] = match ($status) {
            'completo' => ['total' => 5, 'done' => 5],
            'parcial' => ['total' => 5, 'done' => 2],
            'vazio' => $i % 2 === 0 ? ['total' => 5, 'done' => 0] : null,
            'hoje' => ['total' => 5, 'done' => 3],
        };
    }

    return array_filter($dias);
}

it('padrão do mock: status por dia, 21 dias completos e sequência de 3 terminando ontem (CA05)', function () {
    $resultado = (new AdherenceCalculator)->compute(diasDoPadrao($this->hoje), $this->hoje);

    expect(array_column($resultado['days'], 'status'))->toBe(PADRAO_DO_MOCK)
        ->and($resultado['days'][0]['date'])->toBe('2026-08-25')
        ->and($resultado['days'][27]['date'])->toBe('2026-09-21')
        ->and($resultado['complete_days'])->toBe(21)
        ->and($resultado['streak'])->toBe(3);
});

it('hoje completo não entra na sequência; ontem vazio zera', function () {
    $dias = [$this->hoje->toDateString() => ['total' => 5, 'done' => 5], $this->hoje->subDays(2)->toDateString() => ['total' => 5, 'done' => 5]];

    $resultado = (new AdherenceCalculator)->compute($dias, $this->hoje);

    expect($resultado['streak'])->toBe(0)->and($resultado['days'][27]['status'])->toBe('hoje')->and($resultado['complete_days'])->toBe(1);
});

it('nada materializado: 27 vazios e hoje', function () {
    $resultado = (new AdherenceCalculator)->compute([], $this->hoje);

    expect(array_count_values(array_column($resultado['days'], 'status')))->toBe(['vazio' => 27, 'hoje' => 1])
        ->and($resultado['complete_days'])->toBe(0)->and($resultado['streak'])->toBe(0);
});

it('sequência longa: 27 dias completos seguidos', function () {
    $dias = [];
    for ($i = 1; $i <= 27; $i++) {
        $dias[$this->hoje->subDays($i)->toDateString()] = ['total' => 5, 'done' => 5];
    }

    expect((new AdherenceCalculator)->compute($dias, $this->hoje)['streak'])->toBe(27);
});
