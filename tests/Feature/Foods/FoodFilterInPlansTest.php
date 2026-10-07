<?php

use App\Models\Food;
use App\Models\Restriction;
use App\Models\User;
use App\Services\Foods\FoodFilter;

beforeEach(function () {
    seedCatalog();
    $this->user = User::factory()->onboarded()->create();
});

it('alimento fora de in_plans não entra no plano, troca nem Nutri (RN52)', function () {
    $arroz = Food::where('slug', 'arroz-branco-cozido')->sole();
    expect(app(FoodFilter::class)->allowedFor($this->user)->has($arroz->id))->toBeTrue();

    $arroz->update(['in_plans' => false]);

    expect(app(FoodFilter::class)->allowedFor($this->user)->has($arroz->id))->toBeFalse();
});

it('conflitos trazem o rótulo da restrição e o termo digitado (RN51)', function () {
    $this->user->restrictions()->attach(Restriction::where('slug', 'lactose')->sole());
    $this->user->profile->update(['other_restrictions' => ['aveia']]);
    $foods = Food::whereIn('slug', ['leite-integral', 'aveia-em-flocos', 'arroz-branco-cozido'])->get()->keyBy('id');

    $conflitos = app(FoodFilter::class)->conflictsFor($this->user->fresh(), $foods);

    expect($conflitos[Food::where('slug', 'leite-integral')->value('id')])->toBe([Restriction::where('slug', 'lactose')->value('label')])
        ->and($conflitos[Food::where('slug', 'aveia-em-flocos')->value('id')])->toBe(['aveia'])
        ->and($conflitos[Food::where('slug', 'arroz-branco-cozido')->value('id')])->toBe([]);
});
