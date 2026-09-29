<?php

use App\Models\Food;
use App\Models\PantryItem;
use App\Models\Restriction;
use App\Models\User;

beforeEach(fn () => seedCatalog());

function preferencesPayload(array $overrides = []): array
{
    return array_merge([
        'restrictions' => ['castanhas', 'lactose'],
        'other_restrictions' => ['camarão'],
        'pantry_items' => ['ovos', 'maca'],
        'disliked_food_ids' => [Food::where('slug', 'figado-bovino')->value('id')],
    ], $overrides);
}

it('substitui as quatro listas de uma vez (RF17)', function () {
    $user = login(User::factory()->onboarded()->create());
    $user->restrictions()->attach(Restriction::where('slug', 'gluten')->sole());
    $user->pantryItems()->attach(PantryItem::where('slug', 'frango')->sole());

    $this->putJson('/api/v1/profile/preferences', preferencesPayload())
        ->assertOk()
        ->assertJsonPath('data.restrictions.*.slug', ['lactose', 'castanhas'])
        ->assertJsonPath('data.pantry_items.*.slug', ['ovos', 'maca'])
        ->assertJsonPath('data.other_restrictions', ['camarão'])
        ->assertJsonPath('data.disliked_foods.*.name', ['Fígado bovino'])
        ->assertJsonPath('meta', ['plan_effect' => 'none', 'plan_id' => null]);
});

it('aceita listas vazias', function () {
    login(User::factory()->onboarded()->create());

    $this->putJson('/api/v1/profile/preferences', ['restrictions' => [], 'other_restrictions' => [], 'pantry_items' => [], 'disliked_food_ids' => []])
        ->assertOk()
        ->assertJsonPath('data.restrictions', [])
        ->assertJsonPath('data.disliked_foods', []);
});

it('só aceita em "prefiro não ver" alimentos da lista de "não curto"', function () {
    $user = login(User::factory()->onboarded()->create());
    $arroz = Food::where('slug', 'arroz-branco-cozido')->value('id');

    $this->putJson('/api/v1/profile/preferences', preferencesPayload(['disliked_food_ids' => [$arroz]]))
        ->assertJsonValidationErrors(['disliked_food_ids.0' => 'Confira os alimentos que você prefere não ver.']);

    expect($user->dislikedFoods()->count())->toBe(0);
});

it('exige as quatro listas (substituição completa)', function (string $missing) {
    login(User::factory()->onboarded()->create());
    $payload = preferencesPayload();
    unset($payload[$missing]);

    $this->putJson('/api/v1/profile/preferences', $payload)->assertJsonValidationErrors([$missing]);
})->with(['restrictions', 'other_restrictions', 'pantry_items', 'disliked_food_ids']);

it('exige o onboarding concluído (RN07)', function () {
    login(User::factory()->answered()->create());

    $this->putJson('/api/v1/profile/preferences', preferencesPayload())
        ->assertStatus(409)
        ->assertJsonPath('details.next_step', 'resumo');
});
