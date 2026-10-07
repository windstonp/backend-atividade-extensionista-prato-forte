<?php

use App\Models\Food;

it('catálogo tem ≥ 700 ativos, todos com medida, porção e fonte; os 62 de antes ficam no plano (CA43)', function () {
    seedCatalog();

    expect(Food::where('is_active', true)->count())->toBeGreaterThanOrEqual(700)
        ->and(Food::whereNotIn('measure', ['g', 'ml'])->count())->toBe(0)
        ->and(Food::where('typical_portion_g', '<=', 0)->count())->toBe(0)
        ->and(Food::where('source', '')->count())->toBe(0)
        ->and(Food::where('in_plans', true)->count())->toBe(62)
        ->and(Food::where('slug', 'arroz-branco-cozido')->value('in_plans'))->toBeTrue();
});

it('semear duas vezes não duplica (Review Focus 4)', function () {
    seedCatalog();
    $n = Food::count();
    seedCatalog();
    expect(Food::count())->toBe($n);
});
