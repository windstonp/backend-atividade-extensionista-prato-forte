<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function () {
        // O Sanctum só abre sessão para requisições vindas do front ("stateful"), como no navegador.
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/']);
    })
    ->in('Feature');

uses(TestCase::class)->in('Unit');

/** Autentica um usuário (criado se não vier) na guarda de sessão. Senha das factories: senha1234. */
function login(?User $user = null): User
{
    $user ??= User::factory()->create();
    test()->actingAs($user, 'web');

    return $user;
}

/** Simula outra sessão aberta do usuário (outro celular ou navegador). */
function fakeSession(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => null,
        'user_agent' => null,
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);
}

/** Corpo válido de cadastro; sobrescreva o que o teste quiser variar. */
function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Camila Réus',
        'email' => 'camila.reus@gmail.com',
        'password' => 'senha1234',
        'password_confirmation' => 'senha1234',
        'terms_accepted' => true,
        'terms_version' => '2026-09',
    ], $overrides);
}
