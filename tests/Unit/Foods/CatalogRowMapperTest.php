<?php

use App\Services\Foods\CatalogRowMapper;

$linha = fn (string $nome, string $categoria, $kcal = '100', $p = '1', $c = '1', $g = '1') => ['name' => $nome, 'category' => $categoria, 'kcal' => (string) $kcal, 'protein' => (string) $p, 'carbs' => (string) $c, 'fat' => (string) $g, 'source' => 'TACO 4ª ed.'];

it('grupo pela categoria da fonte', function (string $categoria, string $grupo) use ($linha) {
    expect((new CatalogRowMapper)->map($linha('X, y', $categoria))['group'])->toBe($grupo);
})->with([
    ['Cereais e derivados', 'carboidrato'], ['Verduras hortaliças e derivados', 'vegetal'], ['Frutas e derivados', 'fruta'],
    ['Gorduras e óleos', 'gordura'], ['Pescados e frutos do mar', 'proteina'], ['Carnes e derivados', 'proteina'],
    ['Leite e derivados', 'laticinio'], ['Bebidas (alcoólicas e não alcoólicas)', 'bebida'], ['Ovos e derivados', 'proteina'],
    ['Produtos açucarados', 'outros'], ['Miscelâneas', 'outros'], ['Leguminosas e derivados', 'leguminosa'],
    ['Nozes e sementes', 'gordura'], ['Alimentos preparados', 'outros'], ['Categoria nova', 'outros'],
]);

it('restrições conservadoras (Review Focus 2)', function (string $nome, string $categoria, array $esperadas) use ($linha) {
    $restricoes = explode('|', (new CatalogRowMapper)->map($linha($nome, $categoria))['restrictions']);
    expect(array_filter($restricoes))->toEqualCanonicalizing($esperadas);
})->with([
    ['Pão, trigo, francês', 'Cereais e derivados', ['gluten']],
    ['Bolo, pronto, chocolate', 'Cereais e derivados', ['gluten', 'lactose', 'sem-animal']],
    ['Paçoca, amendoim', 'Produtos açucarados', ['castanhas']],
    ['Castanha-do-Brasil, crua', 'Nozes e sementes', ['castanhas']],
    ['Camarão, cozido', 'Pescados e frutos do mar', ['frutos-do-mar', 'sem-carne', 'sem-animal']],
    ['Frango, peito, grelhado', 'Carnes e derivados', ['sem-carne', 'sem-animal']],
    ['Leite, de vaca, integral', 'Leite e derivados', ['lactose', 'sem-animal']],
    ['Ovo, de galinha, cozido', 'Ovos e derivados', ['sem-animal']],
    ['Banana, prata, crua', 'Frutas e derivados', []],
]);

it('medida em ml para bebidas e leite fluido; pó continua em g (Review Focus 3)', function (string $nome, string $categoria, string $medida) use ($linha) {
    expect((new CatalogRowMapper)->map($linha($nome, $categoria))['measure'])->toBe($medida);
})->with([
    ['Suco de laranja, pera', 'Bebidas (alcoólicas e não alcoólicas)', 'ml'],
    ['Leite, de vaca, desnatado, UHT', 'Leite e derivados', 'ml'],
    ['Leite, de vaca, integral, pó', 'Leite e derivados', 'g'],
    ['Iogurte, natural', 'Leite e derivados', 'g'],
    ['Café, infusão 10%', 'Bebidas (alcoólicas e não alcoólicas)', 'ml'],
]);

it('traço vira zero; sem kcal é descartado', function () use ($linha) {
    expect((new CatalogRowMapper)->map($linha('Leite, x', 'Leite e derivados', '34', '3.4', '4.5', 'Tr'))['fat'])->toBe('0')
        ->and((new CatalogRowMapper)->map($linha('Sal, dietético', 'Miscelâneas', 'NA', 'NA', 'NA', 'NA')))->toBeNull();
});

it('slug, sinônimo curto e porção por grupo (Review Focus 5)', function () use ($linha) {
    $r = (new CatalogRowMapper)->map($linha('Arroz, tipo 1, cozido', 'Cereais e derivados', '128', '2.5', '28.1', '0.2'));

    expect($r['slug'])->toBe('arroz-tipo-1-cozido')
        ->and($r['aliases'])->toBe('arroz')
        ->and($r['portion_g'])->toBe('100')
        ->and($r['in_plans'])->toBe('0')
        ->and($r['source'])->toBe('TACO 4ª ed.');
});
