<?php

use App\Models\CustomFood;
use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Models\Food;
use App\Models\MealEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
    $this->dia = $this->getJson('/api/v1/days/today')->json('data');
    $this->almoco = collect($this->dia['meals'])->firstWhere('slot', 'almoco');
});

it('"+" registra o item sugerido com as gramas sugeridas e marca a refeição como feita (CA31)', function () {
    $item = $this->almoco['items'][0];

    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [['suggestion_item_id' => $item['id']]]])
        ->assertCreated()
        ->assertJsonPath('data.meals.2.done', true)
        ->assertJsonPath('data.meals.2.entries.0.suggestion_item_id', $item['id'])
        ->assertJsonPath('data.meals.2.entries.0.amount', $item['grams'])
        ->assertJsonPath('data.meals.2.entries.0.calories', $item['calories'])
        ->assertJsonPath('data.meals.2.items.0.registered', true)
        ->assertJsonPath('data.totals.consumed.calories', $item['calories']);

    expect(DayMeal::where('slot', 'almoco')->sole()->done_at)->not->toBeNull();
});

it('"Adicionar os n" registra todos os itens numa requisição (CA32)', function () {
    $ids = array_map(fn ($i) => ['suggestion_item_id' => $i['id']], $this->almoco['items']);

    $dia = $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => $ids])->assertCreated()->json('data');

    $almoco = collect($dia['meals'])->firstWhere('slot', 'almoco');
    expect(count($almoco['entries']))->toBe(count($ids))
        ->and($almoco['consumed']['calories'])->toBe($almoco['calories']);
});

it('registra do catálogo em ml com retrato dos números (CA33)', function () {
    $leite = Food::where('slug', 'leite-integral')->sole();

    $this->postJson('/api/v1/days/today/meals/cafe/entries', ['entries' => [['food_id' => $leite->id, 'amount' => 200]]])
        ->assertCreated()
        ->assertJsonPath('data.meals.0.entries.0.measure', 'ml')
        ->assertJsonPath('data.meals.0.entries.0.amount', 200)
        ->assertJsonPath('data.meals.0.entries.0.calories', 122)
        ->assertJsonPath('data.meals.0.entries.0.amount_text', '200 ml, mais ou menos 1 copo');

    $leite->update(['kcal_per_100g' => 99]);
    $this->getJson('/api/v1/days/today')->assertJsonPath('data.meals.0.entries.0.calories', 122); // RN49
});

it('registra alimento próprio', function () {
    $barra = CustomFood::create(['user_id' => $this->user->id, 'name' => 'Barra caseira', 'name_normalized' => 'barra caseira', 'measure' => 'g',
        'kcal_per_100' => 380, 'protein_per_100' => 30, 'carbs_per_100' => 35, 'fat_per_100' => 12]);

    $this->postJson('/api/v1/days/today/meals/lanche/entries', ['entries' => [['custom_food_id' => $barra->id, 'amount' => 40]]])
        ->assertCreated()
        ->assertJsonPath('data.meals.1.entries.0.name', 'Barra caseira')
        ->assertJsonPath('data.meals.1.entries.0.calories', 152)
        ->assertJsonPath('data.meals.1.entries.0.custom_food_id', $barra->id);
});

it('o mesmo item sugerido não é registrado duas vezes', function () {
    $item = ['suggestion_item_id' => $this->almoco['items'][0]['id']];
    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [$item]])->assertCreated();

    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [$item]])
        ->assertStatus(409)->assertJsonPath('code', 'ALREADY_REGISTERED');
    $this->postJson('/api/v1/days/today/meals/jantar/entries', ['entries' => [['suggestion_item_id' => $this->almoco['items'][1]['id']]]])
        ->assertUnprocessable();
});

it('edita a quantidade recalculando o retrato e remove o último registro desfazendo o "feita" (CA38)', function () {
    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [['suggestion_item_id' => $this->almoco['items'][0]['id']]]]);
    $registro = MealEntry::sole();
    $esperado = (int) round(Food::find($registro->food_id)->kcal_per_100g * 1.5);

    $this->patchJson("/api/v1/days/today/entries/{$registro->id}", ['amount' => 150])
        ->assertOk()->assertJsonPath('data.meals.2.entries.0.amount', 150)->assertJsonPath('data.meals.2.entries.0.calories', $esperado);

    $this->deleteJson("/api/v1/days/today/entries/{$registro->id}")
        ->assertOk()->assertJsonPath('data.meals.2.done', false)->assertJsonPath('data.meals.2.entries', []);
    expect(DayMeal::where('slot', 'almoco')->sole()->done_at)->toBeNull();
});

it('o "Desfazer" da remoção devolve o vínculo com a sugestão e a quantidade editada', function () {
    $id = $this->almoco['items'][0]['id'];
    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [['suggestion_item_id' => $id]]]);
    $this->deleteJson('/api/v1/days/today/entries/'.MealEntry::sole()->id);

    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [['suggestion_item_id' => $id, 'amount' => 150]]])
        ->assertCreated()->assertJsonPath('data.meals.2.entries.0.amount', 150)->assertJsonPath('data.meals.2.items.0.registered', true);
});

it('ontem é gravado na primeira leitura, com ids na sugestão para o "+", e uma vez só (CA39, RN22)', function () {
    $ontem = $this->getJson('/api/v1/days/2026-10-06')->assertOk()
        ->assertJsonPath('data.editable', true)->assertJsonPath('data.materialized', true)->json('data');
    expect($ontem['meals'][4]['items'][0]['id'])->toBeInt();

    $this->getJson('/api/v1/days/2026-10-06')->assertOk();
    $this->postJson('/api/v1/days/2026-10-06/meals/jantar/entries', ['entries' => [['suggestion_item_id' => $ontem['meals'][4]['items'][0]['id']]]])
        ->assertCreated()->assertJsonPath('data.date', '2026-10-06')->assertJsonPath('data.meals.4.done', true);

    expect(DayMeal::whereDate('date', '2026-10-06')->count())->toBe(count($ontem['meals']))
        ->and(MealEntry::count())->toBe(1);
});

it('anteontem e amanhã não aceitam registro (CA39, Review Focus 3)', function (string $data) {
    $comida = Food::first();
    $this->postJson("/api/v1/days/{$data}/meals/jantar/entries", ['entries' => [['food_id' => $comida->id, 'amount' => 100]]])
        ->assertStatus(409)->assertJsonPath('code', 'DAY_NOT_EDITABLE');
})->with(['2026-10-05', '2026-10-08']);

it('valida o lote', function (array $corpo) {
    $this->postJson('/api/v1/days/today/meals/almoco/entries', $corpo)->assertUnprocessable();
})->with([
    'vazio' => [['entries' => []]],
    'onze' => [['entries' => array_fill(0, 11, ['food_id' => 1, 'amount' => 10])]],
    'dois alimentos' => [['entries' => [['food_id' => 1, 'custom_food_id' => 1, 'amount' => 10]]]],
    'sem quantidade' => [['entries' => [['food_id' => 1]]]],
    'zero' => [['entries' => [['food_id' => 1, 'amount' => 0]]]],
    'demais' => [['entries' => [['food_id' => 1, 'amount' => 2000.1]]]],
    'inativo' => [['entries' => [['food_id' => 999999, 'amount' => 10]]]],
]);

it('nada de outro usuário: registro, alimento próprio e item sugerido dão 404 (CA42)', function () {
    $outro = User::factory()->onboarded()->create();
    login($outro);
    planoPronto($outro);
    $this->getJson('/api/v1/days/today');
    $itemDoOutro = DayMealItem::whereHas('dayMeal', fn ($q) => $q->where('user_id', $outro->id))->first();
    $proprioDoOutro = CustomFood::create(['user_id' => $outro->id, 'name' => 'Dele', 'name_normalized' => 'dele', 'measure' => 'g',
        'kcal_per_100' => 100, 'protein_per_100' => 1, 'carbs_per_100' => 1, 'fat_per_100' => 1]);
    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [['custom_food_id' => $proprioDoOutro->id, 'amount' => 10]]])->assertCreated();
    $registroDoOutro = MealEntry::sole();

    login($this->user);
    $this->patchJson("/api/v1/days/today/entries/{$registroDoOutro->id}", ['amount' => 10])->assertNotFound();
    $this->deleteJson("/api/v1/days/today/entries/{$registroDoOutro->id}")->assertNotFound();
    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [['custom_food_id' => $proprioDoOutro->id, 'amount' => 10]]])->assertNotFound();
    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [['suggestion_item_id' => $itemDoOutro->id]]])->assertNotFound();
    expect(MealEntry::count())->toBe(1);
});
