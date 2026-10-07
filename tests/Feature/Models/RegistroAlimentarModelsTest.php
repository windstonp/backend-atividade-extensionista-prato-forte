<?php

use App\Models\CustomFood;
use App\Models\DayMeal;
use App\Models\Food;
use App\Models\MealEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
    $this->getJson('/api/v1/days/today')->assertOk();
    $this->almoco = DayMeal::where('slot', 'almoco')->sole();
});

it('soma o consumido pelos retratos dos registros, não pela sugestão', function () {
    MealEntry::create(['user_id' => $this->user->id, 'day_meal_id' => $this->almoco->id, 'food_id' => Food::first()->id,
        'name' => 'X', 'measure' => 'g', 'amount' => 100, 'calories' => 300, 'protein' => 20.0, 'carbs' => 30.0, 'fat' => 5.5, 'position' => 1]);
    MealEntry::create(['user_id' => $this->user->id, 'day_meal_id' => $this->almoco->id, 'food_id' => Food::first()->id,
        'name' => 'Y', 'measure' => 'g', 'amount' => 50, 'calories' => 101, 'protein' => 1.05, 'carbs' => 0.0, 'fat' => 0.0, 'position' => 2]);

    expect($this->almoco->fresh()->load('entries')->consumed())
        ->toBe(['calories' => 401, 'protein' => 21.1, 'carbs' => 30.0, 'fat' => 5.5]);
});

it('a meta da refeição é a soma da sugestão', function () {
    $meal = $this->almoco->fresh()->load('items.food');
    $soma = collect($meal->items)->sum(fn ($i) => (int) round($i->food->kcal_per_100g * $i->grams / 100));

    expect($meal->target()['calories'])->toBe($soma);
});

it('registro tem exatamente um alimento: catálogo ou próprio', function () {
    $proprio = CustomFood::create(['user_id' => $this->user->id, 'name' => 'Barra', 'name_normalized' => 'barra', 'measure' => 'g',
        'kcal_per_100' => 380, 'protein_per_100' => 30, 'carbs_per_100' => 35, 'fat_per_100' => 12]);

    expect(fn () => MealEntry::create(['user_id' => $this->user->id, 'day_meal_id' => $this->almoco->id,
        'food_id' => Food::first()->id, 'custom_food_id' => $proprio->id,
        'name' => 'X', 'measure' => 'g', 'amount' => 10, 'calories' => 1, 'protein' => 0, 'carbs' => 0, 'fat' => 0, 'position' => 1]))
        ->toThrow(QueryException::class);
});

it('alimento próprio apagado continua acessível pelo registro antigo', function () {
    $proprio = CustomFood::create(['user_id' => $this->user->id, 'name' => 'Barra', 'name_normalized' => 'barra', 'measure' => 'g',
        'kcal_per_100' => 380, 'protein_per_100' => 30, 'carbs_per_100' => 35, 'fat_per_100' => 12]);
    $registro = MealEntry::create(['user_id' => $this->user->id, 'day_meal_id' => $this->almoco->id, 'custom_food_id' => $proprio->id,
        'name' => 'Barra', 'measure' => 'g', 'amount' => 40, 'calories' => 152, 'protein' => 12, 'carbs' => 14, 'fat' => 4.8, 'position' => 1]);

    $proprio->delete();

    expect($this->user->customFoods()->count())->toBe(0)
        ->and($registro->fresh()->customFood->name)->toBe('Barra');
});

it('foods ganha medida e in_plans; os alimentos de antes ficam no plano', function () {
    expect(Food::where('slug', 'leite-integral')->sole()->measure)->toBe('ml')
        ->and(Food::where('slug', 'arroz-branco-cozido')->sole()->measure)->toBe('g')
        ->and(Food::where('in_plans', false)->count())->toBe(0);
});
