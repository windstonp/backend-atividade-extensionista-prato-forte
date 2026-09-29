<?php

use App\Models\User;

it('mostra a prévia calculada pelo backend (RN13, CA10)', function () {
    login(User::factory()->answered()->create());

    $this->getJson('/api/v1/plans/preview-targets')
        ->assertOk()
        ->assertExactJson(['data' => ['kcal' => 2250, 'protein_g' => 115, 'carbs_g' => 305, 'fat_g' => 65, 'meals' => 5]]);
});

it('usa o peso atual (a pesagem mais recente)', function () {
    $user = login(User::factory()->onboarded()->create());
    $user->weighIns()->create(['date' => today(), 'weight_kg' => 70.0]);

    // 70 kg: TMB 1.429 × 1,55 × 1,10 = 2.436,5 → 2.450; P 140; G 68,1 → 70; C 319,4 → 320
    $this->getJson('/api/v1/plans/preview-targets')
        ->assertJsonPath('data', ['kcal' => 2450, 'protein_g' => 140, 'carbs_g' => 320, 'fat_g' => 70, 'meals' => 5]);
});

it('diz quais etapas faltam para a prévia', function () {
    $user = User::factory()->create();
    $user->profile->update(['goal' => 'ganhar-massa', 'completed_steps' => ['objetivo']]);
    login($user);

    $this->getJson('/api/v1/plans/preview-targets')
        ->assertUnprocessable()
        ->assertJsonPath('details.missing_steps', ['dados', 'atividade']);
});
