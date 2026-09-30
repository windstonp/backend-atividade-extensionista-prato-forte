<?php

use App\Models\UsabilityResponse;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['validacao.rodada' => '2026-1', 'validacao.inicio' => '2026-09-01']);
    $this->travelTo(CarbonImmutable::parse('2026-10-04 10:00', 'America/Sao_Paulo'));
});

$resposta = ['sus_answers' => [4, 2, 5, 1, 4, 2, 5, 1, 4, 2], 'usefulness' => 4, 'liked' => 'Os horários batem com o meu treino.', 'disliked' => null];

it('convida quem concluiu o onboarding há 7 dias (CA04)', function () {
    $user = login(User::factory()->onboarded()->create());
    $user->profile->update(['onboarding_completed_at' => now()->subDays(8)]);

    $this->getJson('/api/v1/usability-responses/status')->assertOk()->assertExactJson(['data' => ['round' => '2026-1', 'responded' => false, 'invite' => true]]);
});

it('não convida quem começou ontem e marcou poucas refeições; convida com 10 refeições feitas', function () {
    seedCatalog();
    $user = login(User::factory()->onboarded()->create());
    $user->profile->update(['onboarding_completed_at' => now()->subDay()]);
    expect($this->getJson('/api/v1/usability-responses/status')->json('data.invite'))->toBeFalse();

    $plano = planoPronto($user);
    foreach (range(1, 10) as $i) {
        $user->dayMeals()->create(['date' => now()->subDays($i)->toDateString(), 'meal_plan_id' => $plano->id, 'slot' => 'cafe', 'name' => 'Café', 'time' => '07:00', 'position' => 1, 'done_at' => now()]);
    }

    expect($this->getJson('/api/v1/usability-responses/status')->json('data.invite'))->toBeTrue();
});

it('responde (201), grava o SUS e não aceita de novo na rodada (CA05, CA06)', function () use ($resposta) {
    $user = login(User::factory()->onboarded()->create());

    $this->postJson('/api/v1/usability-responses', $resposta)->assertCreated()->assertExactJson(['data' => ['round' => '2026-1', 'responded' => true]]);
    $this->postJson('/api/v1/usability-responses', $resposta)->assertStatus(409)->assertJsonPath('code', 'ALREADY_RESPONDED');

    expect(UsabilityResponse::sole()->only(['round', 'sus_score', 'usefulness']))->toEqual(['round' => '2026-1', 'sus_score' => 85.0, 'usefulness' => 4])
        ->and($this->getJson('/api/v1/usability-responses/status')->json('data'))->toBe(['round' => '2026-1', 'responded' => true, 'invite' => false]);
});

it('rodada nova: pode responder de novo', function () use ($resposta) {
    login(User::factory()->onboarded()->create());
    $this->postJson('/api/v1/usability-responses', $resposta)->assertCreated();
    config(['validacao.rodada' => '2026-2']);

    $this->postJson('/api/v1/usability-responses', $resposta)->assertCreated();
    expect(UsabilityResponse::count())->toBe(2);
});

it('"Agora não" some com o convite nesta rodada; rodada aberta depois convida de novo', function () {
    $user = login(User::factory()->onboarded()->create());
    $user->profile->update(['onboarding_completed_at' => now()->subDays(8)]);

    $this->postJson('/api/v1/usability-responses/dismiss')->assertNoContent();
    expect($this->getJson('/api/v1/usability-responses/status')->json('data.invite'))->toBeFalse();

    config(['validacao.rodada' => '2026-2', 'validacao.inicio' => '2026-10-05']);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'America/Sao_Paulo'));
    expect($this->getJson('/api/v1/usability-responses/status')->json('data.invite'))->toBeTrue();
});

it('valida as respostas', function (array $mudanca, string $campo) use ($resposta) {
    login(User::factory()->onboarded()->create());

    $this->postJson('/api/v1/usability-responses', [...$resposta, ...$mudanca])->assertUnprocessable()->assertJsonValidationErrors($campo);
})->with([
    'nove respostas' => [['sus_answers' => [4, 2, 5, 1, 4, 2, 5, 1, 4]], 'sus_answers'],
    'fora da escala' => [['sus_answers' => [6, 2, 5, 1, 4, 2, 5, 1, 4, 2]], 'sus_answers.0'],
    'utilidade 0' => [['usefulness' => 0], 'usefulness'],
    'texto longo' => [['liked' => str_repeat('a', 1001)], 'liked'],
]);

it('dois envios ao mesmo tempo: o segundo vira 409, nunca 500', function () use ($resposta) {
    $user = login(User::factory()->onboarded()->create());
    // O outro envio gravou entre a checagem e o insert: o índice único estoura.
    UsabilityResponse::creating(function () use ($user) {
        UsabilityResponse::flushEventListeners();
        UsabilityResponse::create(['user_id' => $user->id, 'round' => '2026-1', 'sus_answers' => [3, 3, 3, 3, 3, 3, 3, 3, 3, 3], 'sus_score' => 50, 'usefulness' => 3]);
    });

    $this->postJson('/api/v1/usability-responses', $resposta)->assertStatus(409);
    expect(UsabilityResponse::count())->toBe(1);
});
