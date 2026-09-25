<?php

use App\Models\User;

it('entra e indica o destino pelo onboarding (CA03)', function (User $user, bool $completed, ?string $nextStep) {
    $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'senha1234'])
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.onboarding_completed', $completed)
        ->assertJsonPath('data.next_step', $nextStep);

    $this->assertAuthenticatedAs($user, 'web');
})->with([
    'onboarding completo' => [fn () => User::factory()->onboarded()->create(), true, null],
    'parado em atividade' => [fn () => User::factory()->withCompletedSteps(['objetivo', 'dados'])->create(), false, 'atividade'],
]);

it('mantém a sessão nas próximas requisições', function () {
    $user = User::factory()->create();

    $login = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'senha1234'])->assertOk();
    followSession($login);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', $user->email);
});

it('aceita o e-mail em outra caixa e com espaços', function () {
    $user = User::factory()->create(['email' => 'camila@exemplo.com']);

    $this->postJson('/api/v1/login', ['email' => '  CAMILA@Exemplo.com ', 'password' => 'senha1234'])->assertOk();

    $this->assertAuthenticatedAs($user, 'web');
});

it('aceita senha com acentos', function () {
    $user = User::factory()->create(['password' => 'ação12345']);

    $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'ação12345'])->assertOk();
});

it('não revela se o e-mail existe (CA04)', function () {
    User::factory()->create(['email' => 'camila@exemplo.com']);

    $wrongPassword = $this->postJson('/api/v1/login', ['email' => 'camila@exemplo.com', 'password' => 'errada123']);
    $noAccount = $this->postJson('/api/v1/login', ['email' => 'ninguem@exemplo.com', 'password' => 'errada123']);

    foreach ([$wrongPassword, $noAccount] as $response) {
        $response->assertUnprocessable()
            ->assertExactJson(['message' => 'E-mail ou senha incorretos.', 'code' => 'INVALID_CREDENTIALS']);
    }
    $this->assertGuest('web');
});

it('pede os campos vazios', function () {
    $this->postJson('/api/v1/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.email.0', 'Confira o e-mail.')
        ->assertJsonPath('errors.password.0', 'Digite sua senha.');
});

it('responde 422, não 500, quando o e-mail vem como lista', function () {
    $this->postJson('/api/v1/login', ['email' => ['a@b.com'], 'password' => 'senha1234'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_ERROR');
});

it('bloqueia a 6ª tentativa em 1 minuto', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/login', ['email' => 'camila@exemplo.com', 'password' => 'errada123'])->assertUnprocessable();
    }

    $this->postJson('/api/v1/login', ['email' => 'camila@exemplo.com', 'password' => 'errada123'])
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
        ->assertJsonPath('details.retry_after', fn (int $seconds) => $seconds > 0 && $seconds <= 60);
});
