<?php

use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
});

it('desfaz a troca: a refeição volta como era, sem selo "Trocado" (CA09)', function () {
    $item = itemDeHoje('carboidrato');
    $slot = $item->dayMeal->slot;
    $antes = itensDaRefeicao($slot);
    trocarPelaPrimeiraOpcao($item->id);
    expect(itensDaRefeicao($slot))->not->toBe($antes);

    $this->postJson('/api/v1/days/today/undo')->assertOk()->assertJsonPath('data.last_change', null);

    expect(itensDaRefeicao($slot))->toBe($antes);
});

it('cada desfazer volta exatamente um passo (Review Focus 5)', function () {
    $carbo = itemDeHoje('carboidrato');
    $proteina = itemDeHoje('proteina');
    $slots = array_unique([$carbo->dayMeal->slot, $proteina->dayMeal->slot]);
    $estado = fn () => array_map(fn (string $slot) => itensDaRefeicao($slot), $slots);

    $original = $estado();
    trocarPelaPrimeiraOpcao($carbo->id);
    $depoisDoCarbo = $estado();
    trocarPelaPrimeiraOpcao($proteina->id);

    $this->postJson('/api/v1/days/today/undo')->assertOk();
    expect($estado())->toBe($depoisDoCarbo);

    $this->postJson('/api/v1/days/today/undo')->assertOk();
    expect($estado())->toBe($original);

    $this->postJson('/api/v1/days/today/undo')->assertStatus(409)->assertJsonPath('code', 'NOTHING_TO_UNDO');
});

it('depois de 15 minutos não desfaz mais (CA10, RN27)', function () {
    trocarPelaPrimeiraOpcao(itemDeHoje('carboidrato')->id);
    $this->travel(16)->minutes();

    $this->getJson('/api/v1/days/today')->assertJsonPath('data.last_change', null);
    $this->postJson('/api/v1/days/today/undo')->assertStatus(409)->assertJsonPath('code', 'NOTHING_TO_UNDO');
});

it('sem troca, nada para desfazer; e só hoje', function () {
    $this->postJson('/api/v1/days/today/undo')->assertStatus(409)->assertJsonPath('code', 'NOTHING_TO_UNDO');
    $this->postJson('/api/v1/days/2026-09-27/undo')->assertStatus(409)->assertJsonPath('code', 'DAY_NOT_EDITABLE');
});
