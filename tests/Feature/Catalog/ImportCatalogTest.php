<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->original = File::get(database_path('data/foods.csv'));
});

afterEach(function () {
    File::put(database_path('data/foods.csv'), $this->original);
});

it('acrescenta só o que é novo e não mexe nos 62 (Review Focus 1)', function () {
    $antes = count(file(database_path('data/foods.csv')));

    $this->artisan('catalog:import', ['arquivo' => base_path('tests/Fixtures/catalogo/taco-amostra.csv')])
        ->expectsOutputToContain('novos')->assertSuccessful();
    $this->artisan('catalog:import', ['arquivo' => base_path('tests/Fixtures/catalogo/ibge-amostra.csv')])->assertSuccessful();

    $linhas = array_map('str_getcsv', file(database_path('data/foods.csv'), FILE_IGNORE_NEW_LINES));
    $slugs = array_column(array_slice($linhas, 1), 0);
    expect(count($slugs))->toBe(count(array_unique($slugs)))
        ->and(count($linhas))->toBe($antes + 14 + 2) // 15 da TACO − 1 sem kcal (o repetido cai pelo slug); 2 novos do IBGE
        ->and(str_starts_with(File::get(database_path('data/foods.csv')), explode("\n", $this->original)[0]))->toBeTrue();
});

it('--dry-run não grava', function () {
    $this->artisan('catalog:import', ['arquivo' => base_path('tests/Fixtures/catalogo/taco-amostra.csv'), '--dry-run' => true])->assertSuccessful();
    expect(File::get(database_path('data/foods.csv')))->toBe($this->original);
});
