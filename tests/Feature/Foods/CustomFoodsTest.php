<?php

use App\Models\CustomFood;
use App\Models\MealEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
    $this->corpo = ['name' => 'Barra de cereal caseira', 'measure' => 'g', 'per_100' => ['calories' => 380, 'protein' => 30, 'carbs' => 35, 'fat' => 12]];
});

it('cadastra e registra (CA35)', function () {
    $id = $this->postJson('/api/v1/custom-foods', $this->corpo)->assertCreated()
        ->assertJsonPath('data.kind', 'custom')->assertJsonPath('data.per_100.calories', 380)->json('data.id');

    $this->postJson('/api/v1/days/today/meals/lanche/entries', ['entries' => [['custom_food_id' => $id, 'amount' => 40]]])
        ->assertCreated()->assertJsonPath('data.meals.1.entries.0.calories', 152);
});

it('recusa números que não batem e campos fora do limite (CA36)', function (array $troca, string $campo) {
    $this->postJson('/api/v1/custom-foods', array_replace_recursive($this->corpo, $troca))
        ->assertUnprocessable()->assertJsonValidationErrors([$campo]);
})->with([
    'kcal baixa demais' => [['per_100' => ['calories' => 38]], 'per_100.calories'],
    'kcal acima de 900' => [['per_100' => ['calories' => 901]], 'per_100.calories'],
    'macros somam mais de 100' => [['per_100' => ['protein' => 60, 'carbs' => 50]], 'per_100'],
    'nome curto' => [['name' => 'A'], 'name'],
    'medida' => [['measure' => 'kg'], 'measure'],
]);

it('a mensagem da coerência é a da spec', function () {
    $erros = $this->postJson('/api/v1/custom-foods', array_replace_recursive($this->corpo, ['per_100' => ['calories' => 38]]))->json('errors');

    expect($erros['per_100.calories'][0])->toBe('Os números não batem: confira as calorias.');
});

it('nome repetido (sem acento/maiúscula) é recusado; apagado pode ser cadastrado de novo', function () {
    $id = $this->postJson('/api/v1/custom-foods', $this->corpo)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/custom-foods', [...$this->corpo, 'name' => 'BARRA DE CEREAL CASEÍRA'])->assertUnprocessable()->assertJsonValidationErrors(['name']);

    $this->deleteJson("/api/v1/custom-foods/{$id}")->assertNoContent();
    $this->postJson('/api/v1/custom-foods', $this->corpo)->assertCreated();
});

it('editar muda só os próximos registros; apagar mantém os antigos (CA45, Review Focus 4)', function () {
    $id = $this->postJson('/api/v1/custom-foods', $this->corpo)->json('data.id');
    $this->postJson('/api/v1/days/today/meals/lanche/entries', ['entries' => [['custom_food_id' => $id, 'amount' => 100]]]);

    $this->patchJson("/api/v1/custom-foods/{$id}", ['name' => 'Barra nova', 'per_100' => ['calories' => 400]])->assertOk()
        ->assertJsonPath('data.name', 'Barra nova')->assertJsonPath('data.per_100.calories', 400);
    expect(MealEntry::sole()->only(['name', 'calories']))->toBe(['name' => 'Barra de cereal caseira', 'calories' => 380]);

    $this->deleteJson("/api/v1/custom-foods/{$id}")->assertNoContent();
    $this->getJson('/api/v1/days/today')->assertJsonPath('data.meals.1.entries.0.name', 'Barra de cereal caseira');
    $this->postJson('/api/v1/days/today/meals/lanche/entries', ['entries' => [['custom_food_id' => $id, 'amount' => 10]]])->assertNotFound();
});

it('não mexe no alimento de outro usuário (Review Focus 5)', function () {
    $dele = CustomFood::create(['user_id' => User::factory()->create()->id, 'name' => 'Dele', 'name_normalized' => 'dele', 'measure' => 'g',
        'kcal_per_100' => 100, 'protein_per_100' => 1, 'carbs_per_100' => 1, 'fat_per_100' => 1]);

    $this->patchJson("/api/v1/custom-foods/{$dele->id}", ['name' => 'Meu'])->assertNotFound();
    $this->deleteJson("/api/v1/custom-foods/{$dele->id}")->assertNotFound();
});

it('não há limite de quantidade', function () {
    foreach (range(1, 120) as $n) {
        CustomFood::create(['user_id' => $this->user->id, 'name' => "Item {$n}", 'name_normalized' => "item {$n}", 'measure' => 'g',
            'kcal_per_100' => 100, 'protein_per_100' => 1, 'carbs_per_100' => 1, 'fat_per_100' => 1]);
    }
    $this->postJson('/api/v1/custom-foods', $this->corpo)->assertCreated();
});
