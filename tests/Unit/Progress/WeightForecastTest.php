<?php

use App\Services\Progress\WeightForecast;
use Carbon\CarbonImmutable;

/** As 6 pesagens do mock: 56,8 → 58,4 em 5 semanas. */
function pesagensDoMock(): array
{
    return array_map(fn ($p) => ['date' => $p[0], 'weight_kg' => $p[1]], [
        ['2026-08-11', 56.8], ['2026-08-18', 57.0], ['2026-08-25', 57.5], ['2026-09-01', 57.6], ['2026-09-08', 58.0], ['2026-09-15', 58.4],
    ]);
}

beforeEach(fn () => $this->hoje = CarbonImmutable::parse('2026-09-15'));

it('regressão linear sobre as pesagens do mock chega na meta de 62 kg em 05/12 (CA03)', function () {
    // inclinação 0,04531 kg/dia, intercepto 56,757 ⇒ 115,7 dias depois de 11/08.
    expect((new WeightForecast)->estimate(pesagensDoMock(), 62.0, $this->hoje))
        ->toBe(['date' => '2026-12-05', 'label' => 'início de dezembro']);
});

it('sem previsão: sem meta, menos de 3 pesagens ou menos de 14 dias', function (array $pontos, ?float $meta) {
    expect((new WeightForecast)->estimate($pontos, $meta, $this->hoje))->toBeNull();
})->with([
    'sem meta (mais disposição)' => [pesagensDoMock(), null],
    'duas pesagens' => [array_slice(pesagensDoMock(), 0, 2), 62.0],
    'três em 13 dias' => [[['date' => '2026-09-02', 'weight_kg' => 57.0], ['date' => '2026-09-09', 'weight_kg' => 57.5], ['date' => '2026-09-15', 'weight_kg' => 58.0]], 62.0],
]);

it('pesagens se afastando da meta: sem previsão (CA04)', function () {
    expect((new WeightForecast)->estimate(pesagensDoMock(), 50.0, $this->hoje))->toBeNull();
});

it('já passou da meta: sem previsão', function () {
    expect((new WeightForecast)->estimate(pesagensDoMock(), 58.0, $this->hoje))->toBeNull();
});

it('perdendo peso, a reta descendo também prevê', function () {
    $pontos = array_map(fn ($p) => ['date' => $p['date'], 'weight_kg' => round(130 - $p['weight_kg'], 1)], pesagensDoMock()); // 73,2 → 71,6
    expect((new WeightForecast)->estimate($pontos, 68.0, $this->hoje)['date'] ?? null)->toBe('2026-12-05');
});

it('mais de 52 semanas: sem previsão', function () {
    $lento = [['date' => '2026-08-01', 'weight_kg' => 57.0], ['date' => '2026-08-20', 'weight_kg' => 57.1], ['date' => '2026-09-15', 'weight_kg' => 57.2]];
    expect((new WeightForecast)->estimate($lento, 62.0, $this->hoje))->toBeNull();
});

it('rótulo: início, meados e fim do mês; o ano aparece só quando o mês se repetiria', function (string $data, string $rotulo) {
    expect(WeightForecast::label(CarbonImmutable::parse($data), CarbonImmutable::parse('2026-09-15')))->toBe($rotulo);
})->with([
    ['2026-12-10', 'início de dezembro'],
    ['2026-12-11', 'meados de dezembro'],
    ['2026-12-20', 'meados de dezembro'],
    ['2027-01-21', 'fim de janeiro'],
    ['2027-09-02', 'início de setembro de 2027'],
]);
