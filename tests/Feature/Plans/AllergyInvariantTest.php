<?php

use App\Models\DayMealItem;
use App\Models\Food;
use App\Models\PlanMealItem;
use App\Models\Restriction;
use App\Models\User;
use App\Services\Foods\FoodFilter;
use Carbon\CarbonImmutable;

// RN17 de ponta a ponta: com alergia a castanhas e a frutos do mar e "camarão" em outras restrições,
// nenhum alimento proibido aparece no plano, no dia ou nas trocas — mesmo com a IA errando.
it('nenhum alimento proibido chega ao plano, ao dia ou às trocas (RN17, CA08)', function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Sao_Paulo'));
    $user = login(User::factory()->onboarded()->create());
    $user->restrictions()->attach(Restriction::whereIn('slug', ['castanhas', 'frutos-do-mar'])->pluck('id'));
    $user->profile->update(['other_restrictions' => ['camarão']]);
    $proibidos = Food::whereNotIn('id', app(FoodFilter::class)->allowedFor($user)->keys())->pluck('id');
    fakeAi()->queue('plan', json_encode(['meals' => array_map(
        fn (string $slot) => ['slot' => $slot, 'items' => [['food_id' => $proibidos->first(), 'grams' => 100]]],
        ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar'],
    )]));

    planoPronto($user);
    $dia = $this->getJson('/api/v1/days/today')->assertOk()->json('data');

    expect(PlanMealItem::whereIn('food_id', $proibidos)->exists())->toBeFalse()
        ->and(DayMealItem::whereIn('food_id', $proibidos)->exists())->toBeFalse();
    foreach (collect($dia['meals'])->flatMap(fn ($m) => $m['items']) as $item) {
        $opcoes = $this->getJson("/api/v1/days/today/items/{$item['id']}/substitutions")->json('data.options.*.food_id');
        expect(array_intersect($opcoes, $proibidos->all()))->toBe([]);
    }
});
