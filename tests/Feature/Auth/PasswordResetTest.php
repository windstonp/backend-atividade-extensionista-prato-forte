<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

function resetPayload(string $token, array $overrides = []): array
{
    return array_merge([
        'token' => $token,
        'email' => 'camila@exemplo.com',
        'password' => 'novaSenha9',
        'password_confirmation' => 'novaSenha9',
    ], $overrides);
}

it('envia o link do front para quem tem conta, mesmo com e-mail em outra caixa', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'camila@exemplo.com']);

    $this->postJson('/api/v1/password/forgot', ['email' => '  Camila@Exemplo.com '])
        ->assertOk()
        ->assertExactJson(['message' => 'Se houver uma conta com esse e-mail, enviamos um link.']);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user) {
        $url = $notification->url($user);

        return str_starts_with($url, 'http://localhost:3000/senha/redefinir?token=')
            && str_contains($url, 'email=camila%40exemplo.com');
    });
});

it('escreve o e-mail em português com o link', function () {
    $user = User::factory()->create(['name' => 'Camila Réus']);

    $mail = (new ResetPasswordNotification('tok123'))->toMail($user);

    expect($mail->subject)->toBe('Prato Forte — crie uma senha nova')
        ->and($mail->greeting)->toBe('Oi, Camila!')
        ->and($mail->actionText)->toBe('Criar senha nova')
        ->and($mail->actionUrl)->toContain('/senha/redefinir?token=tok123');
});

it('responde igual para e-mail sem conta e não envia nada (CA05)', function () {
    Notification::fake();

    $this->postJson('/api/v1/password/forgot', ['email' => 'ninguem@exemplo.com'])
        ->assertOk()
        ->assertExactJson(['message' => 'Se houver uma conta com esse e-mail, enviamos um link.']);

    Notification::assertNothingSent();
});

it('redefine a senha, encerra todas as sessões e não entra sozinho', function () {
    $user = User::factory()->create(['email' => 'camila@exemplo.com']);
    fakeSession($user, 'sessao-antiga');
    $token = Password::createToken($user);

    $this->postJson('/api/v1/password/reset', resetPayload($token))
        ->assertOk()
        ->assertExactJson(['message' => 'Senha redefinida.']);

    expect(Hash::check('novaSenha9', $user->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'sessao-antiga')->exists())->toBeFalse();
    $this->assertGuest('web');
});

it('recusa link com mais de 60 minutos (CA06)', function () {
    $user = User::factory()->create(['email' => 'camila@exemplo.com']);
    $token = Password::createToken($user);
    $this->travel(61)->minutes();

    $this->postJson('/api/v1/password/reset', resetPayload($token))
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Esse link expirou. Peça outro.', 'code' => 'INVALID_RESET_TOKEN']);
});

it('recusa link já usado (CA06)', function () {
    $user = User::factory()->create(['email' => 'camila@exemplo.com']);
    $token = Password::createToken($user);
    $this->postJson('/api/v1/password/reset', resetPayload($token))->assertOk();

    $this->postJson('/api/v1/password/reset', resetPayload($token, ['password' => 'outra1234', 'password_confirmation' => 'outra1234']))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'INVALID_RESET_TOKEN');
});

it('aplica a regra de senha na redefinição (RN02)', function () {
    $user = User::factory()->create(['email' => 'camila@exemplo.com']);

    $this->postJson('/api/v1/password/reset', resetPayload(Password::createToken($user), ['password' => 'curta', 'password_confirmation' => 'curta']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'Use 8 ou mais caracteres, com letra e número.');
});

it('limita pedidos de link a 3 por hora', function () {
    Notification::fake();

    foreach (range(1, 3) as $attempt) {
        $this->postJson('/api/v1/password/forgot', ['email' => 'camila@exemplo.com'])->assertOk();
    }

    $this->postJson('/api/v1/password/forgot', ['email' => 'camila@exemplo.com'])
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
});
