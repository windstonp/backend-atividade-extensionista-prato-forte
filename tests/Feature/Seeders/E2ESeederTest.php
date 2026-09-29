<?php

use App\Models\User;
use Database\Seeders\E2ESeeder;

it('cria as contas fixas dos testes E2E do front', function () {
    $this->seed(E2ESeeder::class);

    $email = fn (string $email) => User::where('email', $email)->sole();

    expect($email('concluido-chromium@e2e.pratoforte.test')->profile->isOnboarded())->toBeTrue()
        ->and($email('concluido-webkit@e2e.pratoforte.test')->profile->isOnboarded())->toBeTrue()
        ->and($email('novo@e2e.pratoforte.test')->profile->nextStep()?->value)->toBe('atividade')
        ->and($email('senha-chromium@e2e.pratoforte.test')->profile->isOnboarded())->toBeTrue()
        ->and($email('senha-webkit@e2e.pratoforte.test')->profile->isOnboarded())->toBeTrue();
});

it('usa a senha combinada com o front', function () {
    $this->seed(E2ESeeder::class);

    $this->postJson('/api/v1/login', ['email' => 'concluido-chromium@e2e.pratoforte.test', 'password' => 'senha1234'])
        ->assertOk()
        ->assertJsonPath('data.onboarding_completed', true);
});
