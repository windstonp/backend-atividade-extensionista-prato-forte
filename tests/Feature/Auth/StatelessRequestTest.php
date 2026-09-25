<?php

use App\Models\User;

// Sem Origin/Referer do front (curl, fetch no servidor, SANCTUM_STATEFUL_DOMAINS errado) não há sessão.
beforeEach(fn () => $this->withoutHeaders(['Origin', 'Referer']));

it('recusa cadastro sem sessão antes de criar a conta', function () {
    $this->postJson('/api/v1/register', registerPayload())
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN');

    expect(User::count())->toBe(0);
});

it('recusa login sem sessão com o JSON padrão', function () {
    $user = User::factory()->create();

    $this->post('/api/v1/login', ['email' => $user->email, 'password' => 'senha1234'])
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('code', 'FORBIDDEN');
});
