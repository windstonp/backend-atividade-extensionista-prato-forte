<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

it('encerra a sessão no servidor (RF03)', function () {
    $user = User::factory()->create();
    $login = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'senha1234'])->assertOk();
    followSession($login);

    $this->postJson('/api/v1/logout')->assertNoContent();

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();

    // O navegador ainda manda o cookie antigo: não pode valer mais.
    forgetServerState();
    $this->getJson('/api/v1/me')->assertUnauthorized();
});
