<?php

use App\Models\User;

beforeEach(function () {
    config(['webpush.vapid.public_key' => 'BChaveDeTeste']);
    $this->user = login(User::factory()->create()); // onboarding não é exigido
});

it('devolve o padrão: métrico, lembrete e resumo ligados, dicas desligadas', function () {
    $this->getJson('/api/v1/settings')->assertOk()->assertExactJson(['data' => [
        'unit_system' => 'metric',
        'notifications' => ['meal_reminders' => true, 'weekly_summary' => true, 'tips' => false],
        'push' => ['vapid_public_key' => 'BChaveDeTeste', 'subscriptions' => 0],
    ]]);
});

it('PUT parcial muda só o que veio', function () {
    $this->putJson('/api/v1/settings', ['notifications' => ['tips' => true]])->assertOk()
        ->assertJsonPath('data.notifications', ['meal_reminders' => true, 'weekly_summary' => true, 'tips' => true])
        ->assertJsonPath('data.unit_system', 'metric');

    $this->putJson('/api/v1/settings', ['unit_system' => 'imperial'])->assertOk()->assertJsonPath('data.notifications.tips', true);

    expect($this->user->settings->fresh()->unit_system)->toBe('imperial')
        ->and($this->getJson('/api/v1/me')->json('data.settings.unit_system'))->toBe('imperial');
});

it('valida unidade e booleanos', function (array $corpo, string $campo) {
    $this->putJson('/api/v1/settings', $corpo)->assertUnprocessable()->assertJsonValidationErrors($campo);
})->with([
    [['unit_system' => 'stone'], 'unit_system'],
    [['notifications' => ['tips' => 'sim']], 'notifications.tips'],
]);

it('sem sessão: 401', function () {
    forgetServerState();

    $this->getJson('/api/v1/settings')->assertUnauthorized();
});
