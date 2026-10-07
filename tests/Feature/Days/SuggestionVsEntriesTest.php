<?php

use App\Models\MealEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
});

it('a rota de marcar como feita não existe mais', function () {
    $this->patchJson('/api/v1/days/today/meals/almoco', ['done' => true])->assertNotFound();
});

it('item sugerido já registrado não pode ser trocado (RN26, Review Focus 1)', function () {
    $item = itemDeHoje('carboidrato');
    $this->postJson("/api/v1/days/today/meals/{$item->dayMeal->slot}/entries", ['entries' => [['suggestion_item_id' => $item->id]]])->assertCreated();
    $opcao = $this->getJson("/api/v1/days/today/items/{$item->id}/substitutions")->json('data.options.0');

    $this->postJson("/api/v1/days/today/items/{$item->id}/swap", ['food_id' => $opcao['food_id']])
        ->assertStatus(409)->assertJsonPath('code', 'SUGGESTION_ALREADY_REGISTERED');
});

it('desfazer a troca de um item não solta os outros itens registrados da refeição (RN27, Review Focus 1)', function () {
    $carbo = itemDeHoje('carboidrato');
    $slot = $carbo->dayMeal->slot;
    $outro = $carbo->dayMeal->items()->where('id', '!=', $carbo->id)->first();
    $this->postJson("/api/v1/days/today/meals/{$slot}/entries", ['entries' => [['suggestion_item_id' => $outro->id]]])->assertCreated();

    trocarPelaPrimeiraOpcao($carbo->id);
    $this->postJson('/api/v1/days/today/undo')->assertOk();

    expect(MealEntry::sole()->suggestion_item_id)->toBe($outro->id);
    $refeicao = collect($this->getJson('/api/v1/days/today')->json('data.meals'))->firstWhere('slot', $slot);
    expect(collect($refeicao['items'])->firstWhere('id', $outro->id)['registered'])->toBeTrue();
});
