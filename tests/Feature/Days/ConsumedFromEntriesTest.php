<?php

use App\Models\Food;
use App\Models\User;
use App\Services\Notifications\WeeklySummaryBuilder;
use App\Services\Nutri\NutriContextBuilder;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
    $this->getJson('/api/v1/days/today');
    $this->ovo = Food::where('slug', 'ovos-cozidos')->sole(); // 146 kcal, 13,3 g de proteína por 100 g
    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [['food_id' => $this->ovo->id, 'amount' => 200]]])->assertCreated();
});

it('médias da Evolução usam o que foi registrado (CA41)', function () {
    $this->getJson('/api/v1/progress?period=6w')->assertOk()
        ->assertJsonPath('data.averages.calories.avg_kcal', 292);
});

it('o resumo de domingo soma a proteína registrada', function () {
    $domingo = CarbonImmutable::parse('2026-10-11');
    expect(app(WeeklySummaryBuilder::class)->body($this->user->fresh(), $domingo))->toContain('média de 27 g de proteína');
});

it('o Nutri vê a sugestão e o que foi comido (RN29)', function () {
    $contexto = app(NutriContextBuilder::class)->forAi($this->user->fresh());
    $almoco = collect($contexto['refeicoes_hoje'])->firstWhere('slot', 'almoco');

    expect($almoco)->toHaveKeys(['sugestao', 'comido'])
        ->and($almoco['comido'])->toBe([['nome' => 'Ovos cozidos', 'quantidade' => 200.0, 'medida' => 'g', 'kcal' => 292]])
        ->and($contexto['restante_do_dia']['calories'])->toBe($contexto['metas_do_dia']['calories'] - 292);
});
