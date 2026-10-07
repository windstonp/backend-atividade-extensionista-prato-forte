<?php

use App\Models\User;
use Carbon\CarbonImmutable;

const MARCA_DE_HOJE = '2026-09-28 20:00';

beforeEach(function () {
    seedCatalog();
    $this->user = login(User::factory()->onboarded()->create()); // treina seg, qua, sex; meta 62 kg
});

function pesagens(User $user, array $pares): void
{
    foreach ($pares as [$data, $kg]) {
        $user->weighIns()->create(['date' => $data, 'weight_kg' => $kg]);
    }
}

/** Vai até o dia, materializa e marca as refeições; devolve o consumido do dia. */
function comerNoDia(string $data, array $slots): array
{
    test()->travelTo(CarbonImmutable::parse("{$data} 21:00", 'America/Sao_Paulo'));
    test()->getJson('/api/v1/days/today')->assertOk();
    foreach ($slots as $slot) {
        test()->patchJson("/api/v1/days/today/meals/{$slot}", ['done' => true])->assertOk();
    }

    return test()->getJson('/api/v1/days/today')->json('data.totals.consumed');
}

it('só a pesagem do onboarding: um ponto, sem previsão (CA01)', function () {
    $this->travelTo(CarbonImmutable::parse(MARCA_DE_HOJE, 'America/Sao_Paulo'));
    pesagens($this->user, [['2026-09-28', 58.4]]);

    $peso = $this->getJson('/api/v1/progress')->assertOk()->json('data.weight');

    expect($peso)->toMatchArray(['start_kg' => 58.4, 'current_kg' => 58.4, 'goal_kg' => 62.0, 'goal_source' => 'user', 'change_kg' => 0.0, 'span_weeks' => 0, 'forecast' => null])
        ->and($peso['points'])->toBe([['date' => '2026-09-28', 'weight_kg' => 58.4]]);
});

it('as 6 pesagens do mock: variação, semanas reais e previsão (CA03)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 20:00', 'America/Sao_Paulo'));
    pesagens($this->user, [['2026-08-11', 56.8], ['2026-08-18', 57.0], ['2026-08-25', 57.5], ['2026-09-01', 57.6], ['2026-09-08', 58.0], ['2026-09-15', 58.4]]);

    $dados = $this->getJson('/api/v1/progress?period=6w')->assertOk()->json('data');

    expect($dados['period'])->toBe('6w')
        ->and($dados['weight'])->toMatchArray(['start_kg' => 56.8, 'current_kg' => 58.4, 'change_kg' => 1.6, 'span_weeks' => 5])
        ->and($dados['weight']['forecast'])->toBe(['date' => '2026-12-05', 'label' => 'início de dezembro']);
});

it('o período limita os pontos: 3m deixa de fora o que tem mais de 91 dias', function () {
    $this->travelTo(CarbonImmutable::parse(MARCA_DE_HOJE, 'America/Sao_Paulo'));
    pesagens($this->user, [['2026-06-01', 55.0], ['2026-07-01', 56.0], ['2026-09-28', 58.4]]);

    expect(array_column($this->getJson('/api/v1/progress?period=3m')->json('data.weight.points'), 'date'))->toBe(['2026-07-01', '2026-09-28'])
        ->and(array_column($this->getJson('/api/v1/progress?period=all')->json('data.weight.points'), 'date'))->toBe(['2026-06-01', '2026-07-01', '2026-09-28'])
        ->and($this->getJson('/api/v1/progress?period=6w')->json('data.weight.start_kg'))->toBe(58.4);
});

it('sem pesagens no período: pontos vazios e números nulos', function () {
    $this->travelTo(CarbonImmutable::parse(MARCA_DE_HOJE, 'America/Sao_Paulo'));

    expect($this->getJson('/api/v1/progress')->json('data.weight'))
        ->toMatchArray(['start_kg' => null, 'current_kg' => null, 'change_kg' => null, 'span_weeks' => null, 'points' => [], 'forecast' => null]);
});

it('"mais disposição": sem meta e sem previsão (CA07)', function () {
    $this->user->profile->update(['goal' => 'mais-disposicao', 'goal_weight_kg' => null, 'goal_weight_source' => null]);
    $this->travelTo(CarbonImmutable::parse('2026-09-15 20:00', 'America/Sao_Paulo'));
    pesagens($this->user, [['2026-08-11', 56.8], ['2026-08-25', 57.5], ['2026-09-15', 58.4]]);

    expect($this->getJson('/api/v1/progress')->json('data.weight'))->toMatchArray(['goal_kg' => null, 'goal_source' => null, 'forecast' => null]);
});

it('período inválido: 422', function () {
    $this->getJson('/api/v1/progress?period=1y')->assertUnprocessable();
});

it('constância dos 28 dias vem de day_meals, e as médias só contam dias com refeição feita', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-21 07:00', 'America/Sao_Paulo')); // o plano nasce antes dos dias
    planoPronto($this->user);
    $segunda = comerNoDia('2026-09-21', ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar']); // treino, completo
    $terca = comerNoDia('2026-09-22', ['cafe']);                                                // descanso, parcial
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00', 'America/Sao_Paulo'));
    $this->getJson('/api/v1/days/today')->assertOk();                                            // hoje, nada feito

    $dados = $this->getJson('/api/v1/progress')->assertOk()->json('data');
    $dias = collect($dados['adherence']['days'])->pluck('status', 'date');
    $plano = $this->user->activePlan()->sole();

    expect($dias['2026-09-21'])->toBe('completo')->and($dias['2026-09-22'])->toBe('parcial')->and($dias['2026-09-23'])->toBe('hoje')
        ->and($dias['2026-09-20'])->toBe('vazio')
        ->and($dados['adherence']['complete_days'])->toBe(1)->and($dados['adherence']['streak'])->toBe(0)
        ->and($dados['averages'])->toMatchArray([
            'days_counted' => 2,
            'protein' => ['avg_g' => (int) round(($segunda['protein'] + $terca['protein']) / 2), 'target_g' => $plano->target_protein_g],
            'calories' => ['avg_kcal' => (int) round(($segunda['calories'] + $terca['calories']) / 2), 'target_kcal' => $plano->target_kcal],
        ]);
});

it('observação: proteína baixa nos dias sem treino (RN37)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-21 07:00', 'America/Sao_Paulo'));
    planoPronto($this->user);
    comerNoDia('2026-09-21', ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar']); // segunda, treino: ~100%
    comerNoDia('2026-09-22', ['cafe', 'lanche']);                                   // terça, descanso: bem abaixo

    expect($this->getJson('/api/v1/progress')->json('data.averages.insight'))
        ->toBe('Você fica um pouco abaixo da meta de proteína nos dias sem treino.');
});

it('sem observação quando só há dias de treino', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-21 07:00', 'America/Sao_Paulo'));
    planoPronto($this->user);
    comerNoDia('2026-09-21', ['cafe']);

    expect($this->getJson('/api/v1/progress')->assertOk()->json('data.averages.insight'))->toBeNull();
});

it('nenhum dia com refeição feita: médias vazias (CA06)', function () {
    $this->travelTo(CarbonImmutable::parse(MARCA_DE_HOJE, 'America/Sao_Paulo'));
    planoPronto($this->user);
    $this->getJson('/api/v1/days/today')->assertOk();

    expect($this->getJson('/api/v1/progress')->json('data.averages'))->toBe([
        'days_counted' => 0,
        'protein' => ['avg_g' => null, 'target_g' => $this->user->activePlan()->sole()->target_protein_g],
        'calories' => ['avg_kcal' => null, 'target_kcal' => $this->user->activePlan()->sole()->target_kcal],
        'insight' => null,
    ]);
});

it('a sequência olha além dos 28 dias da grade', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 20:00', 'America/Sao_Paulo'));
    $plano = planoPronto($this->user);
    foreach (range(1, 35) as $dias) {
        $data = CarbonImmutable::today()->subDays($dias)->toDateString();
        $this->user->dayMeals()->create(['date' => $data, 'meal_plan_id' => $plano->id, 'slot' => 'cafe', 'name' => 'Café', 'time' => '07:00', 'position' => 1, 'done_at' => now()]);
    }

    expect($this->getJson('/api/v1/progress')->assertOk()->json('data.adherence.streak'))->toBe(35);
});

it('fronteira do 3m: hoje − 90 entra, hoje − 91 não', function () {
    $this->travelTo(CarbonImmutable::parse(MARCA_DE_HOJE, 'America/Sao_Paulo'));
    pesagens($this->user, [['2026-06-29', 55.0], ['2026-06-30', 55.5], ['2026-09-28', 58.4]]);

    expect(array_column($this->getJson('/api/v1/progress?period=3m')->json('data.weight.points'), 'date'))->toBe(['2026-06-30', '2026-09-28']);
});

it('dia de um plano antigo (já substituído) conta na constância como os outros', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-21 07:00', 'America/Sao_Paulo'));
    $antigo = planoPronto($this->user);
    comerNoDia('2026-09-21', ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar']);
    $antigo->update(['is_active' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-09-22 07:00', 'America/Sao_Paulo'));
    planoPronto($this->user);

    $dias = collect($this->getJson('/api/v1/progress')->json('data.adherence.days'))->pluck('status', 'date');

    expect($dias['2026-09-21'])->toBe('completo');
});
