<?php

use App\Models\DayMeal;
use App\Models\User;
use App\Services\Plans\PlanService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Sao_Paulo')); // segunda-feira, dia de treino
    $this->user = login(User::factory()->onboarded()->create()); // treina seg/qua/sex às 19:00
    $this->plano = planoPronto($this->user);
});

it('materializa hoje na primeira leitura, uma vez só (RN22)', function () {
    $primeira = $this->getJson('/api/v1/days/today')->assertOk()->json('data.meals.*.id');
    $segunda = $this->getJson('/api/v1/days/2026-09-28')->json('data.meals.*.id');

    expect($primeira)->toBe($segunda)->and(DayMeal::where('user_id', $this->user->id)->count())->toBe(5);
});

it('mostra hoje com as 5 refeições em ordem, a próxima e os totais (RN15, RN24)', function () {
    $dia = $this->getJson('/api/v1/days/today')
        ->assertJsonPath('data.date', '2026-09-28')
        ->assertJsonPath('data.is_today', true)
        ->assertJsonPath('data.editable', true)
        ->assertJsonPath('data.materialized', true)
        ->assertJsonPath('data.is_training_day', true)
        ->assertJsonPath('data.meals.*.slot', ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar'])
        ->assertJsonPath('data.meals.*.is_next', [true, false, false, false, false])
        ->assertJsonPath('data.targets', ['kcal' => 2250, 'protein_g' => 115, 'carbs_g' => 305, 'fat_g' => 65])
        ->assertJsonPath('data.totals.consumed.calories', 0)
        ->assertJsonPath('data.last_change', null)
        ->json('data');

    expect($dia['totals']['planned']['calories'])->toBe(array_sum(array_column($dia['meals'], 'calories')))
        ->and($dia['totals']['remaining'])->toBe($dia['totals']['planned'])
        ->and($dia['meals'][0]['items'][0])->toHaveKeys(['id', 'food_id', 'name', 'grams', 'amount', 'calories', 'macros', 'source', 'replaced_from'])
        ->and($dia['meals'][0]['items'][0]['source'])->toBe('plan');
});

it('no dia de treino o jantar tem a nota do treino (CA04)', function () {
    $this->getJson('/api/v1/days/today')
        ->assertJsonPath('data.meals.4.name', 'Jantar')
        ->assertJsonPath('data.meals.4.note', 'Depois do treino das 19h')
        ->assertJsonPath('data.meals.3.name', 'Pré-treino');
});

it('terça sem treino é prévia, com "Lanche da tarde" e sem nota, e não grava nada (CA05, RN22)', function () {
    $this->getJson('/api/v1/days/2026-09-29')
        ->assertOk()
        ->assertJsonPath('data.is_today', false)
        ->assertJsonPath('data.editable', false)
        ->assertJsonPath('data.materialized', false)
        ->assertJsonPath('data.is_training_day', false)
        ->assertJsonPath('data.meals.3.name', 'Lanche da tarde')
        ->assertJsonPath('data.meals.4.note', null)
        ->assertJsonPath('data.meals.0.id', null)
        ->assertJsonPath('data.meals.*.is_next', [false, false, false, false, false]);

    expect(DayMeal::whereDate('date', '2026-09-29')->exists())->toBeFalse();
});

it('passado sem registro vem vazio e só para leitura', function () {
    $this->getJson('/api/v1/days/2026-09-26')
        ->assertOk()
        ->assertJsonPath('data.meals', [])
        ->assertJsonPath('data.materialized', false)
        ->assertJsonPath('data.editable', false);
});

it('recusa datas fora de [hoje − 90, hoje + 6] com 422', function (string $data) {
    $this->getJson("/api/v1/days/{$data}")->assertUnprocessable()->assertJsonValidationErrors(['date']);
})->with(['2026-06-29', '2026-10-05', '2026-02-30', 'ontem']);

it('aceita as bordas do intervalo', function (string $data) {
    $this->getJson("/api/v1/days/{$data}")->assertOk();
})->with(['2026-06-30', '2026-10-04']);

it('"hoje" é o dia de São Paulo, também perto da meia-noite (Review Focus 3)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 23:59', 'America/Sao_Paulo'));
    $this->getJson('/api/v1/days/today')->assertJsonPath('data.date', '2026-09-28');

    $this->travelTo(CarbonImmutable::parse('2026-09-29 03:01', 'UTC')); // 00:01 em São Paulo
    $this->getJson('/api/v1/days/today')->assertJsonPath('data.date', '2026-09-29');
    // D13: ontem continua editável para o registro; antes de ontem, não.
    $this->getJson('/api/v1/days/2026-09-28')->assertJsonPath('data.editable', true)->assertJsonPath('data.materialized', true);
    $this->getJson('/api/v1/days/2026-09-27')->assertJsonPath('data.editable', false);
});

it('sem plano ativo responde NO_ACTIVE_PLAN com o status do último plano', function () {
    fakeAi()->failNext('plan');
    $outro = login(User::factory()->onboarded()->create());
    $falhou = app(PlanService::class)->requestGeneration($outro);

    $this->getJson('/api/v1/days/today')
        ->assertStatus(409)
        ->assertJsonPath('code', 'NO_ACTIVE_PLAN')
        ->assertJsonPath('details.plan_status', 'failed')
        ->assertJsonPath('details.plan_id', $falhou->id);
});

it('exige o onboarding concluído (RN07)', function () {
    login(User::factory()->answered()->create());

    $this->getJson('/api/v1/days/today')->assertStatus(409)->assertJsonPath('code', 'ONBOARDING_INCOMPLETE');
});
