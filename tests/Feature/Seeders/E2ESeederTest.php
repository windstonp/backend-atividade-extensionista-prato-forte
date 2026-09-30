<?php

use App\Enums\PlanStatus;
use App\Models\Food;
use App\Models\Restriction;
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

it('semeia o catálogo e deixa a conta "novo" com objetivo e dados respondidos (E2E-03)', function () {
    $this->seed(E2ESeeder::class);

    $novo = User::where('email', 'novo@e2e.pratoforte.test')->sole()->profile;

    expect(Restriction::count())->toBe(6)
        ->and(Food::count())->toBeGreaterThan(50)
        ->and([$novo->goal, $novo->preferred_name, $novo->age, $novo->height_cm, (float) $novo->start_weight_kg, $novo->sex])
        ->toBe(['perder-gordura', 'Nina', 30, 170, 70.0, 'feminino']);
});

it('as contas concluídas já têm plano pronto e ativo, gerado pela IA falsa', function () {
    $this->seed(E2ESeeder::class);

    foreach (['concluido-chromium', 'concluido-webkit', 'senha-chromium', 'senha-webkit'] as $conta) {
        $plano = User::where('email', "{$conta}@e2e.pratoforte.test")->sole()->activePlan()->first();
        expect($plano?->status)->toBe(PlanStatus::Ready);
    }
});
