<?php

use App\Models\Food;
use App\Models\PantryItem;
use App\Models\Restriction;
use App\Models\User;

beforeEach(fn () => seedCatalog());

it('devolve o perfil, com o peso atual vindo da última pesagem', function () {
    $user = login(User::factory()->onboarded()->create([
        'name' => 'Camila Réus', 'email' => 'camila.reus@gmail.com', 'created_at' => '2026-08-11 09:00:00',
    ]));
    $user->restrictions()->attach(Restriction::where('slug', 'castanhas')->sole());
    $user->pantryItems()->attach(PantryItem::where('slug', 'ovos')->sole());
    $figado = Food::where('slug', 'figado-bovino')->sole();
    $user->dislikedFoods()->attach($figado);
    $user->profile->update(['other_restrictions' => ['camarão']]);
    $user->weighIns()->create(['date' => '2026-09-01', 'weight_kg' => 57.0]);
    $user->weighIns()->create(['date' => '2026-09-20', 'weight_kg' => 58.9]);

    $this->getJson('/api/v1/profile')
        ->assertOk()
        ->assertExactJson(['data' => [
            'name' => 'Camila Réus', 'preferred_name' => 'Camila', 'email' => 'camila.reus@gmail.com', 'created_at' => '2026-08-11T09:00:00-03:00',
            'goal' => 'ganhar-massa', 'sex' => 'feminino', 'age' => 27, 'height_cm' => 164,
            'start_weight_kg' => 58.4, 'current_weight_kg' => 58.9, 'goal_weight_kg' => 62.0, 'goal_weight_source' => 'user',
            'healthy_weight_range' => ['min' => 49.8, 'max' => 67.0],
            'activity_level' => 'moderado', 'work_posture' => 'sentada',
            'wake_time' => '06:20', 'training_time' => '19:00', 'sleep_time' => '23:00', 'training_days' => [1, 3, 5], 'lunch_place' => 'marmita',
            'pantry_items' => [['slug' => 'ovos', 'label' => 'Ovos']],
            'restrictions' => [['slug' => 'castanhas', 'label' => 'Amendoim e castanhas', 'is_allergy' => true]],
            'other_restrictions' => ['camarão'],
            'disliked_foods' => [['id' => $figado->id, 'name' => 'Fígado bovino']],
            'gym' => 'Zfit', 'city' => 'Capivari de Baixo',
        ]]);
});

it('exige o onboarding concluído e diz a próxima etapa (RN07)', function () {
    login();

    $this->getJson('/api/v1/profile')
        ->assertStatus(409)
        ->assertJsonPath('code', 'ONBOARDING_INCOMPLETE')
        ->assertJsonPath('details.next_step', 'objetivo');
});

it('exige login', function () {
    $this->getJson('/api/v1/profile')->assertUnauthorized();
});
