<?php

use App\Models\Food;
use App\Models\PantryItem;
use App\Models\Restriction;
use App\Models\User;
use App\Services\Foods\FoodFilter;

beforeEach(fn () => seedCatalog());

function slugsPermitidos(User $user): array
{
    return app(FoodFilter::class)->allowedFor($user)->pluck('slug')->all();
}

it('tira os alimentos das restrições marcadas (RN16)', function () {
    $user = User::factory()->onboarded()->create();
    $user->restrictions()->attach(Restriction::where('slug', 'castanhas')->sole());

    expect(slugsPermitidos($user))
        ->not->toContain('amendoim-torrado', 'pasta-de-amendoim', 'castanha-do-para', 'castanha-de-caju')
        ->toContain('arroz-branco-cozido', 'frango-grelhado');
});

it('tira pelo nome ou sinônimo de "outras restrições", sem ligar para acento e maiúscula', function () {
    $user = User::factory()->onboarded()->create();
    $user->profile->update(['other_restrictions' => ['Camarao', 'MANDIOCA']]);

    expect(slugsPermitidos($user))->not->toContain('camarao-cozido', 'aipim-cozido')->toContain('batata-cozida');
});

it('tira o que a pessoa prefere não ver', function () {
    $user = User::factory()->onboarded()->create();
    $user->dislikedFoods()->attach(Food::where('slug', 'jilo')->sole());

    expect(slugsPermitidos($user))->not->toContain('jilo')->toContain('beterraba');
});

it('"nada de origem animal" tira ovo, leite e mel e mantém o tofu', function () {
    $user = User::factory()->onboarded()->create();
    $user->restrictions()->attach(Restriction::where('slug', 'sem-animal')->sole());

    expect(slugsPermitidos($user))->not->toContain('ovos-cozidos', 'leite-integral', 'mel', 'frango-grelhado')->toContain('tofu', 'feijao-carioca');
});

it('dá os alimentos ligados à cozinha, para priorizar', function () {
    $user = User::factory()->onboarded()->create();
    $user->pantryItems()->attach(PantryItem::where('slug', 'arroz-e-feijao')->sole());

    $slugs = Food::whereIn('id', app(FoodFilter::class)->pantryFoodIds($user))->orderBy('slug')->pluck('slug')->all();

    expect($slugs)->toBe(['arroz-branco-cozido', 'arroz-integral', 'feijao-carioca', 'feijao-preto']);
});

it('responde se um alimento pode aparecer', function () {
    $user = User::factory()->onboarded()->create();
    $user->restrictions()->attach(Restriction::where('slug', 'castanhas')->sole());
    $filtro = app(FoodFilter::class);

    expect($filtro->isAllowed($user, Food::where('slug', 'castanha-de-caju')->value('id')))->toBeFalse()
        ->and($filtro->isAllowed($user, Food::where('slug', 'banana')->value('id')))->toBeTrue();
});
