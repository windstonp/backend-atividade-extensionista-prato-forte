<?php

use App\Models\Food;
use App\Models\Restriction;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    $this->user->restrictions()->attach(Restriction::where('slug', 'castanhas')->sole());
    $this->user->profile->update(['other_restrictions' => ['camarão']]);
    planoPronto($this->user);
});

it('lista até 4 opções do mesmo grupo, com a garantia de todas as restrições (RN25, CA08)', function () {
    $item = itemDeHoje('carboidrato');

    $resposta = $this->getJson("/api/v1/days/today/items/{$item->id}/substitutions")
        ->assertOk()
        ->assertJsonPath('data.item.id', $item->id)
        ->assertJsonPath('data.item.name', $item->food->name)
        ->assertJsonPath('data.guarantee.restrictions', ['Amendoim e castanhas', 'camarão'])
        ->assertJsonStructure(['data' => ['item' => ['amount', 'calories', 'macros'], 'options' => [['food_id', 'name', 'grams', 'amount', 'calories', 'macros', 'calorie_delta', 'note', 'in_pantry']]]]);

    $opcoes = $resposta->json('data.options');
    expect(count($opcoes))->toBeGreaterThan(0)->toBeLessThanOrEqual(4)
        ->and(Food::whereIn('id', array_column($opcoes, 'food_id'))->pluck('group')->unique()->all())->toBe(['carboidrato']);
});

it('item de outra pessoa responde 404 (CA12, RN43)', function () {
    $daCamila = itemDeHoje('carboidrato');

    $outra = login(User::factory()->onboarded()->create());
    planoPronto($outra);

    $this->getJson("/api/v1/days/today/items/{$daCamila->id}/substitutions")->assertNotFound();
    $this->postJson("/api/v1/days/today/items/{$daCamila->id}/swap", ['food_id' => 1])->assertNotFound();
});

it('não oferece trocas fora de hoje (RN23)', function () {
    $item = itemDeHoje('carboidrato');

    $this->getJson("/api/v1/days/2026-09-29/items/{$item->id}/substitutions")->assertStatus(409)->assertJsonPath('code', 'DAY_NOT_EDITABLE');
});
