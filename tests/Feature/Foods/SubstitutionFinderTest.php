<?php

use App\Models\Food;
use App\Services\Foods\SubstitutionFinder;
use App\Services\Foods\SubstitutionOption;
use Illuminate\Support\Collection;

beforeEach(fn () => seedCatalog());

/** @return array<int, Food> */
function catalogo(array $sem = []): Collection
{
    return Food::where('in_plans', true)->whereNotIn('slug', $sem)->orderBy('id')->get()->keyBy('id');
}

function resumo(array $opcoes): array
{
    return array_map(fn (SubstitutionOption $o) => [$o->food->slug, $o->grams, $o->calorieDelta, $o->inPantry], $opcoes);
}

it('troca o arroz por carboidratos, com porção pelo carboidrato e a cozinha primeiro (RN25)', function () {
    $arroz = Food::where('slug', 'arroz-branco-cozido')->sole();
    $cozinha = Food::whereIn('slug', ['batata-doce-cozida', 'tapioca'])->pluck('id')->all();

    $opcoes = (new SubstitutionFinder)->find($arroz, 150, catalogo(), $cozinha);

    // arroz 150 g: 192 kcal, 42,2 g de carboidrato
    expect(resumo($opcoes))->toBe([
        ['batata-doce-cozida', 230.0, -15, true],
        ['tapioca', 70.0, -24, true],
        ['cuscuz-de-milho', 165.0, -6, false],
        ['arroz-integral', 165.0, 13, false],
    ]);
});

it('troca proteína pela proteína e nunca oferece o que foi filtrado (CA08)', function () {
    $frango = Food::where('slug', 'frango-grelhado')->sole();

    $opcoes = (new SubstitutionFinder)->find($frango, 120, catalogo(sem: ['camarao-cozido']), []);

    expect(resumo($opcoes))->toBe([
        ['frango-desfiado', 120.0, 5, false],
        ['peixe-assado', 145.0, -14, false],
        ['patinho-moido', 105.0, 39, false],
        ['tofu', 200.0, -39, false],
    ]);
});

it('fruta troca por kcal e devolve no máximo 4', function () {
    $banana = Food::where('slug', 'banana')->sole();

    $opcoes = (new SubstitutionFinder)->find($banana, 60, catalogo(), []);

    expect($opcoes)->toHaveCount(4)
        ->and(array_map(fn (SubstitutionOption $o) => $o->food->group, $opcoes))->each->toBe('fruta')
        ->and(array_map(fn (SubstitutionOption $o) => abs($o->calorieDelta) <= 0.35 * 59, $opcoes))->each->toBeTrue();
});

it('lista vazia quando não há candidato do grupo', function () {
    $cafe = Food::where('slug', 'cafe-sem-acucar')->sole();

    expect((new SubstitutionFinder)->find($cafe, 100, catalogo(), []))->toBe([]);
});
