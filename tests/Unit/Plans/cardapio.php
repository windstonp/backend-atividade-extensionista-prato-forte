<?php

use App\Models\Food;
use Illuminate\Support\Collection;

function alimentoDeTeste(int $id, string $grupo, float $kcal, float $proteina): Food
{
    return (new Food(['name' => "Alimento {$id}", 'group' => $grupo, 'kcal_per_100g' => $kcal, 'protein_per_100g' => $proteina, 'carbs_per_100g' => 0, 'fat_per_100g' => 0]))
        ->forceFill(['id' => $id]);
}

function cardapioDeTeste(): array
{
    return [
        ['slot' => 'cafe', 'items' => [['food_id' => 3, 'grams' => 100.0]]],
        ['slot' => 'lanche', 'items' => [['food_id' => 3, 'grams' => 100.0]]],
        ['slot' => 'almoco', 'items' => [['food_id' => 1, 'grams' => 200.0], ['food_id' => 2, 'grams' => 150.0]]],
        ['slot' => 'pre-treino', 'items' => [['food_id' => 1, 'grams' => 100.0]]],
        ['slot' => 'jantar', 'items' => [['food_id' => 1, 'grams' => 150.0], ['food_id' => 2, 'grams' => 150.0]]],
    ];
}

function alimentosDeTeste(): Collection
{
    return collect([alimentoDeTeste(1, 'carboidrato', 128, 2.5), alimentoDeTeste(2, 'proteina', 159, 32), alimentoDeTeste(3, 'fruta', 98, 1.3)])->keyBy('id');
}

function kcalDoCardapio(array $meals): float
{
    $foods = alimentosDeTeste();

    return collect($meals)->flatMap(fn ($m) => $m['items'])->sum(fn ($i) => $foods[$i['food_id']]->kcal_per_100g * $i['grams'] / 100);
}
