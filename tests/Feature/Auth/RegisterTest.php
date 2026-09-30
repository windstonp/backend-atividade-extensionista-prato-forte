<?php

use App\Models\User;
use Carbon\CarbonImmutable;

it('cria a conta, o perfil vazio e as configurações, e já entra (CA01)', function () {
    $this->postJson('/api/v1/register', registerPayload())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Camila Réus')
        ->assertJsonPath('data.email', 'camila.reus@gmail.com')
        ->assertJsonPath('data.preferred_name', 'Camila')
        ->assertJsonPath('data.onboarding_completed', false)
        ->assertJsonPath('data.next_step', 'objetivo')
        ->assertJsonStructure(['data' => ['id', 'created_at']])
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.settings');

    $user = User::sole();
    expect($user->consented_at)->not->toBeNull()
        ->and($user->terms_version)->toBe('2026-10')
        ->and($user->profile()->exists())->toBeTrue()
        ->and($user->settings()->value('unit_system'))->toBe('metric');
    $this->assertAuthenticatedAs($user, 'web');
});

it('devolve created_at em ISO 8601 no fuso de São Paulo', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00:00', 'America/Sao_Paulo'));

    $this->postJson('/api/v1/register', registerPayload())
        ->assertJsonPath('data.created_at', '2026-09-23T10:00:00-03:00');
});

it('grava o e-mail em minúsculas e sem espaços (RN01)', function () {
    $this->postJson('/api/v1/register', registerPayload(['email' => '  Camila.Reus@Gmail.COM ']))
        ->assertCreated()
        ->assertJsonPath('data.email', 'camila.reus@gmail.com');
});

it('recusa e-mail já cadastrado em qualquer caixa (CA02)', function () {
    User::factory()->create(['email' => 'camila.reus@gmail.com']);

    $this->postJson('/api/v1/register', registerPayload(['email' => 'CAMILA.REUS@gmail.com']))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.email.0', 'Esse e-mail já tem conta.');

    expect(User::count())->toBe(1);
});

it('valida cada campo com a mensagem da spec', function (array $overrides, string $field, string $message) {
    $this->postJson('/api/v1/register', registerPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$field}.0", $message);

    expect(User::count())->toBe(0);
})->with([
    'nome vazio' => [['name' => ''], 'name', 'Escreva seu nome.'],
    'nome de 1 letra' => [['name' => 'C'], 'name', 'Escreva seu nome.'],
    'e-mail inválido' => [['email' => 'camila@'], 'email', 'Confira o e-mail.'],
    'senha curta' => [['password' => 'abc1', 'password_confirmation' => 'abc1'], 'password', 'Use 8 ou mais caracteres, com letra e número.'],
    'senha sem número' => [['password' => 'senhasenha', 'password_confirmation' => 'senhasenha'], 'password', 'Use 8 ou mais caracteres, com letra e número.'],
    'senha sem letra' => [['password' => '12345678', 'password_confirmation' => '12345678'], 'password', 'Use 8 ou mais caracteres, com letra e número.'],
    'senha com mais de 72' => [['password' => str_repeat('a1', 37), 'password_confirmation' => str_repeat('a1', 37)], 'password', 'Use no máximo 72 caracteres.'],
    'confirmação diferente' => [['password_confirmation' => 'outra1234'], 'password', 'As senhas não conferem.'],
    'termo não aceito' => [['terms_accepted' => false], 'terms_accepted', 'Para continuar, aceite o termo.'],
    'versão antiga do termo' => [['terms_version' => '2025-01'], 'terms_version', 'Atualize a página e aceite o termo de novo.'],
]);

it('aceita senha com acentos', function () {
    $this->postJson('/api/v1/register', registerPayload(['password' => 'ação12345', 'password_confirmation' => 'ação12345']))
        ->assertCreated();
});

it('ignora campos que o cliente não pode definir', function () {
    $this->postJson('/api/v1/register', registerPayload([
        'id' => 999,
        'consented_at' => '2000-01-01 00:00:00',
        'is_admin' => true,
        'remember_token' => 'forjado',
    ]))->assertCreated();

    $user = User::sole();
    expect($user->id)->not->toBe(999)
        ->and($user->consented_at->isToday())->toBeTrue()
        ->and($user->remember_token)->not->toBe('forjado');
});

it('limita cadastros a 3 por minuto por IP', function () {
    foreach (range(1, 3) as $i) {
        $this->postJson('/api/v1/register', registerPayload(['email' => "pessoa{$i}@exemplo.com"]))->assertCreated();
    }

    $this->postJson('/api/v1/register', registerPayload(['email' => 'pessoa4@exemplo.com']))
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
});

it('só aceita a versão vigente do termo (2026-10)', function () {
    $this->postJson('/api/v1/register', registerPayload(['terms_version' => '2026-09']))->assertUnprocessable()->assertJsonValidationErrors('terms_version');
});
