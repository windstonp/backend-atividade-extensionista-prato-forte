<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Tabelas com dados do usuário e a coluna que aponta para ele (RN06).
 * Plano novo criou tabela com user_id? Acrescente aqui.
 */
dataset('tabelas do usuário', [
    'users' => ['users', 'id'],
    'profiles' => ['profiles', 'user_id'],
    'user_settings' => ['user_settings', 'user_id'],
    'sessions' => ['sessions', 'user_id'],
]);

it('não deixa nenhuma linha do usuário para trás (CA08)', function (string $table, string $column) {
    $user = login(User::factory()->onboarded()->create(['email' => 'camila@exemplo.com']));
    fakeSession($user, 'outro-celular');
    Password::createToken($user);

    $this->deleteJson('/api/v1/me', ['password' => 'senha1234'])->assertNoContent();

    expect(DB::table($table)->where($column, $user->id)->exists())->toBeFalse("sobrou linha em {$table}")
        ->and(DB::table('password_reset_tokens')->where('email', 'camila@exemplo.com')->exists())->toBeFalse();
    $this->assertGuest('web');
})->with('tabelas do usuário');

it('libera o e-mail para um novo cadastro (CA08)', function () {
    login(User::factory()->create(['email' => 'camila.reus@gmail.com']));
    $this->deleteJson('/api/v1/me', ['password' => 'senha1234'])->assertNoContent();
    forgetServerState();

    $this->postJson('/api/v1/register', registerPayload())->assertCreated();
});

it('exige a senha correta e não apaga nada', function () {
    $user = login();

    $this->deleteJson('/api/v1/me', ['password' => 'errada123'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'A senha não confere.');

    expect(User::whereKey($user->id)->exists())->toBeTrue();
});
