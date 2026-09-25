<?php

use App\Models\User;

it('devolve o usuário com o estado do onboarding e as configurações', function () {
    $user = login(User::factory()->create(['name' => 'Camila Réus']));

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.preferred_name', 'Camila')
        ->assertJsonPath('data.onboarding_completed', false)
        ->assertJsonPath('data.next_step', 'objetivo')
        ->assertJsonPath('data.settings.unit_system', 'metric')
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token')
        ->assertJsonMissingPath('data.consented_at');
});

it('usa o nome preferido quando o usuário escolheu um', function () {
    $user = login();
    $user->profile->update(['preferred_name' => 'Mila']);

    $this->getJson('/api/v1/me')->assertJsonPath('data.preferred_name', 'Mila');
});

it('responde 401 sem sessão', function () {
    $this->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Sua sessão expirou. Entre de novo.', 'code' => 'UNAUTHENTICATED']);
});
