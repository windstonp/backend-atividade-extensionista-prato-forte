<?php

use App\Models\User;
use App\Models\WeighIn;

beforeEach(fn () => seedCatalog());

it('conclui, cria a primeira pesagem e responde 202 (RF07, RN34)', function () {
    $user = login(User::factory()->answered()->create());

    $this->postJson('/api/v1/onboarding/complete')
        ->assertAccepted()
        ->assertExactJson(['data' => ['plan' => null]]);

    expect($user->profile->fresh()->isOnboarded())->toBeTrue()
        ->and(WeighIn::where('user_id', $user->id)->sole()->weight_kg)->toBe(58.4)
        ->and(WeighIn::where('user_id', $user->id)->sole()->date->isToday())->toBeTrue();
    $this->getJson('/api/v1/me')->assertJsonPath('data.onboarding_completed', true)->assertJsonPath('data.next_step', null);
});

it('aplica a meta sugerida quando a pessoa não informou (CA04)', function () {
    $user = User::factory()->answered()->create();
    $user->profile->update(['goal_weight_kg' => null, 'goal_weight_source' => null]);
    login($user);

    $this->postJson('/api/v1/onboarding/complete')->assertAccepted();

    expect((float) $user->profile->fresh()->goal_weight_kg)->toBe(61.5)
        ->and($user->profile->fresh()->goal_weight_source)->toBe('suggested');
});

it('grava a meta automática de quem quer manter o peso e nenhuma de quem quer disposição (RN10)', function (string $goal, ?float $kg, ?string $source) {
    $user = User::factory()->answered()->create();
    $user->profile->update(['goal' => $goal, 'goal_weight_kg' => null, 'goal_weight_source' => null]);
    login($user);

    $this->postJson('/api/v1/onboarding/complete')->assertAccepted();

    $profile = $user->profile->fresh();
    expect($profile->goal_weight_kg === null ? null : (float) $profile->goal_weight_kg)->toBe($kg)
        ->and($profile->goal_weight_source)->toBe($source);
})->with([
    'manter' => ['manter-peso', 58.4, 'auto'],
    'disposição' => ['mais-disposicao', null, null],
]);

it('aponta a primeira etapa incompleta (422 com details.step)', function (array $steps, string $expected) {
    $user = User::factory()->answered()->create();
    $user->profile->update(['completed_steps' => $steps]);
    login($user);

    $this->postJson('/api/v1/onboarding/complete')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('details.step', $expected);

    expect($user->profile->fresh()->isOnboarded())->toBeFalse();
})->with([
    'nada salvo' => [[], 'objetivo'],
    'falta a rotina' => [['objetivo', 'dados', 'atividade', 'preferencias', 'restricoes'], 'rotina'],
]);

it('aponta a etapa salva que ficou sem campo obrigatório', function () {
    $user = User::factory()->answered()->create();
    $user->profile->update(['activity_level' => null]);
    login($user);

    $this->postJson('/api/v1/onboarding/complete')->assertJsonPath('details.step', 'atividade');
});

it('é idempotente: concluir de novo responde 200 e não duplica a pesagem', function () {
    $user = login(User::factory()->answered()->create());
    $this->postJson('/api/v1/onboarding/complete')->assertAccepted();

    $this->postJson('/api/v1/onboarding/complete')
        ->assertOk()
        ->assertExactJson(['data' => ['plan' => null]]);

    expect(WeighIn::where('user_id', $user->id)->count())->toBe(1);
});
