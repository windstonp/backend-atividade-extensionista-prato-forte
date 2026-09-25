<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('troca a senha e derruba as outras sessões, mantendo a atual (CA07)', function () {
    $user = login();
    fakeSession($user, 'outro-navegador');

    $this->putJson('/api/v1/me/password', [
        'current_password' => 'senha1234',
        'password' => 'novaSenha9',
        'password_confirmation' => 'novaSenha9',
    ])->assertOk()->assertExactJson(['message' => 'Senha trocada.']);

    expect(Hash::check('novaSenha9', $user->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'outro-navegador')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
});

it('exige a senha atual correta (RN05)', function () {
    $user = login();

    $this->putJson('/api/v1/me/password', [
        'current_password' => 'errada123',
        'password' => 'novaSenha9',
        'password_confirmation' => 'novaSenha9',
    ])->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'A senha atual não confere.');

    expect(Hash::check('senha1234', $user->fresh()->password))->toBeTrue();
});

it('aplica a regra de senha nova (RN02)', function () {
    login();

    $this->putJson('/api/v1/me/password', [
        'current_password' => 'senha1234',
        'password' => 'semnumero',
        'password_confirmation' => 'semnumero',
    ])->assertUnprocessable()->assertJsonPath('errors.password.0', 'Use 8 ou mais caracteres, com letra e número.');
});
