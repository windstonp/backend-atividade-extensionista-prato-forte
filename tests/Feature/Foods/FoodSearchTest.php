<?php

use App\Models\CustomFood;
use App\Models\Food;
use App\Models\Restriction;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
});

it('acha sem acento e por sinônimo; começa-com vem antes de contém', function () {
    $nomes = collect($this->getJson('/api/v1/foods?q=feijao')->assertOk()->json('data'))->pluck('name');
    expect($nomes->first())->toStartWith('Feijão');

    expect(collect($this->getJson('/api/v1/foods?q=cafezinho')->json('data'))->pluck('name'))->toContain('Café sem açúcar');
});

it('mostra o que está fora do plano e o conflito de restrição, sem esconder (RN51, RN52, CA34)', function () {
    Food::where('slug', 'leite-integral')->update(['in_plans' => false]);
    $lactose = Restriction::where('slug', 'lactose')->sole();
    $this->user->restrictions()->attach($lactose);

    $leite = collect($this->getJson('/api/v1/foods?q=leite')->json('data'))->firstWhere('name', 'Leite integral');

    expect($leite)->not->toBeNull()
        ->and($leite['measure'])->toBe('ml')
        ->and($leite['conflicts'])->toBe([$lactose->label])
        ->and($leite['portion'])->toBe(['amount' => 200, 'text' => '200 ml, mais ou menos 1 copo']);
});

it('alimento próprio só aparece para o dono e some ao apagar', function () {
    $outro = User::factory()->onboarded()->create();
    CustomFood::create(['user_id' => $outro->id, 'name' => 'Barra do outro', 'name_normalized' => 'barra do outro', 'measure' => 'g',
        'kcal_per_100' => 1, 'protein_per_100' => 0, 'carbs_per_100' => 0, 'fat_per_100' => 0]);
    $minha = CustomFood::create(['user_id' => $this->user->id, 'name' => 'Barra minha', 'name_normalized' => 'barra minha', 'measure' => 'g',
        'kcal_per_100' => 380, 'protein_per_100' => 30, 'carbs_per_100' => 35, 'fat_per_100' => 12]);

    $achados = collect($this->getJson('/api/v1/foods?q=barra')->json('data'));
    expect($achados->pluck('name')->all())->toContain('Barra minha')->not->toContain('Barra do outro')
        ->and($achados->firstWhere('name', 'Barra minha')['kind'])->toBe('custom');

    $minha->delete();
    expect(collect($this->getJson('/api/v1/foods?q=barra')->json('data'))->pluck('name'))->not->toContain('Barra minha');
});

it('recentes: os mais registrados nos últimos 30 dias, com a última quantidade', function () {
    $ovo = Food::where('slug', 'ovos-cozidos')->sole();
    $this->postJson('/api/v1/days/today/meals/cafe/entries', ['entries' => [['food_id' => $ovo->id, 'amount' => 100]]]);
    $this->postJson('/api/v1/days/today/meals/lanche/entries', ['entries' => [['food_id' => $ovo->id, 'amount' => 50]]]);

    $this->getJson('/api/v1/foods/recent')->assertOk()
        ->assertJsonPath('data.0.name', 'Ovos cozidos')
        ->assertJsonPath('data.0.last_amount', 50);
});

it('busca exige 2 letras', function () {
    $this->getJson('/api/v1/foods?q=a')->assertUnprocessable();
});
