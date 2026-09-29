<?php

use App\Models\Food;
use App\Models\PantryItem;
use App\Models\Restriction;
use Database\Seeders\CatalogSeeder;

beforeEach(fn () => seedCatalog());

it('semeia as 6 restrições, com castanhas e frutos do mar como alergia', function () {
    expect(Restriction::orderBy('position')->pluck('slug')->all())
        ->toBe(['lactose', 'gluten', 'castanhas', 'frutos-do-mar', 'sem-carne', 'sem-animal'])
        ->and(Restriction::where('is_allergy', true)->pluck('slug')->sort()->values()->all())
        ->toBe(['castanhas', 'frutos-do-mar']);
});

it('semeia os 17 itens de cozinha nas 3 categorias', function () {
    expect(PantryItem::count())->toBe(17)
        ->and(PantryItem::distinct()->orderBy('category')->pluck('category')->all())->toBe(['carboidratos', 'frutas', 'proteinas']);
});

it('liga cada item de cozinha a pelo menos um alimento', function () {
    expect(PantryItem::doesntHave('foods')->pluck('slug')->all())->toBe([]);
});

it('liga cada restrição a pelo menos um alimento que ela exclui', function () {
    expect(Restriction::doesntHave('foods')->pluck('slug')->all())->toBe([]);
});

it('tem todos os alimentos do plano de exemplo do mock', function (string $name) {
    expect(Food::where('name', $name)->exists())->toBeTrue("falta {$name}");
})->with([
    'Arroz branco cozido', 'Arroz integral', 'Atum em lata, na água', 'Aveia em flocos', 'Azeite de oliva',
    'Banana', 'Batata-doce cozida', 'Brócolis no vapor', 'Café sem açúcar', 'Cuscuz de milho', 'Feijão carioca',
    'Frango grelhado', 'Iogurte natural', 'Macarrão parafuso', 'Mamão', 'Ovos cozidos', 'Ovos mexidos',
    'Patinho moído', 'Pão francês', 'Queijo minas', 'Salada de alface e tomate', 'Tapioca',
]);

it('marca a lista "prefiro não ver" do mock', function () {
    expect(Food::where('common_dislike', true)->orderBy('name')->pluck('name')->all())
        ->toBe(['Berinjela', 'Beterraba', 'Fígado bovino', 'Jiló', 'Peixe assado']);
});

it('tira de "sem origem animal" tudo que "sem carne" tira', function () {
    $semCarne = Restriction::where('slug', 'sem-carne')->sole()->foods()->pluck('foods.id');
    $semAnimal = Restriction::where('slug', 'sem-animal')->sole()->foods()->pluck('foods.id');

    expect($semCarne->diff($semAnimal)->all())->toBe([]);
});

it('tem pelo menos 3 opções ativas por grupo principal', function (string $group) {
    expect(Food::where('group', $group)->where('is_active', true)->count())->toBeGreaterThanOrEqual(3);
})->with(['proteina', 'carboidrato', 'leguminosa', 'fruta', 'vegetal']);

it('pode rodar de novo sem duplicar nada', function () {
    $antes = [Food::count(), Restriction::count(), PantryItem::count()];

    $this->seed(CatalogSeeder::class);

    expect([Food::count(), Restriction::count(), PantryItem::count()])->toBe($antes);
});
