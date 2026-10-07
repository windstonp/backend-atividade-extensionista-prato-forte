<?php

use App\Models\DayMeal;
use App\Models\Food;
use App\Models\PlanMeal;
use App\Models\PlanMealItem;
use App\Models\Restriction;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create()); // acorda 06:20, treina 19:00, dorme 23:00, seg/qua/sex
    $this->plano = planoPronto($this->user);
});

it('restrição nova refaz o plano na hora, sem o alimento restrito (RN21, CA07)', function () {
    $resposta = $this->patchJson('/api/v1/profile/steps/restricoes', ['restrictions' => ['frutos-do-mar'], 'other_restrictions' => []])
        ->assertOk()
        ->assertJsonPath('meta.plan_effect', 'regeneration_started');

    $novo = $resposta->json('meta.plan_id');
    $frutosDoMar = Restriction::where('slug', 'frutos-do-mar')->sole()->foods()->pluck('foods.id');
    expect($novo)->not->toBe($this->plano->id)
        ->and($this->user->activePlan()->value('id'))->toBe($novo)
        ->and(PlanMealItem::whereIn('plan_meal_id', PlanMeal::where('meal_plan_id', $novo)->pluck('id'))->whereIn('food_id', $frutosDoMar)->exists())->toBeFalse();
});

it('"outras restrições" novas também refazem o plano', function () {
    $this->patchJson('/api/v1/profile/steps/restricoes', ['restrictions' => [], 'other_restrictions' => ['banana']])
        ->assertJsonPath('meta.plan_effect', 'regeneration_started');
});

it('mudanças que afetam o cardápio sugerem refazer, sem gerar (RN21)', function (string $step, array $troca) {
    $this->patchJson("/api/v1/profile/steps/{$step}", stepPayload($step, $troca))
        ->assertOk()
        ->assertJsonPath('meta.plan_effect', 'regeneration_suggested')
        ->assertJsonPath('meta.plan_id', null);

    expect($this->user->activePlan()->value('id'))->toBe($this->plano->id)->and($this->user->mealPlans()->count())->toBe(1);
})->with([
    'objetivo' => ['objetivo', ['goal' => 'perder-gordura']],
    'peso de hoje' => ['dados', ['weight_kg' => 61.0]],
    'atividade' => ['atividade', ['activity_level' => 'intenso']],
    'cozinha' => ['preferencias', ['pantry_items' => ['ovos']]],
    'lugar do almoço' => ['rotina', ['lunch_place' => 'casa']],
]);

it('mudar só o nome, ou salvar igual, não mexe no plano', function (string $step, array $troca) {
    $this->patchJson("/api/v1/profile/steps/{$step}", stepPayload($step, $troca))->assertJsonPath('meta.plan_effect', 'none');
})->with([
    'nome preferido' => ['dados', ['preferred_name' => 'Mila', 'weight_kg' => 58.4]],
    'rotina igual' => ['rotina', []],
]);

it('treino às 07:00 muda os horários na hora, sem IA, e preserva a refeição feita (CA09, RN14)', function () {
    $this->getJson('/api/v1/days/today')->assertOk();
    registrarRefeicao('cafe');

    $this->patchJson('/api/v1/profile/steps/rotina', stepPayload('rotina', ['training_time' => '07:00']))
        ->assertJsonPath('meta.plan_effect', 'times_updated')
        ->assertJsonPath('meta.plan_id', null);

    // Treino cedo (RN14): pré-treino 06:30, café 08:15, lanche 10:30, almoço 12:30, jantar 20:30.
    expect($this->plano->meals()->pluck('time', 'slot')->all())->toBe([
        'pre-treino' => '06:30:00', 'cafe' => '08:15:00', 'lanche' => '10:30:00', 'almoco' => '12:30:00', 'jantar' => '20:30:00',
    ])->and($this->user->mealPlans()->count())->toBe(1);
    $hoje = DayMeal::whereDate('date', today())->get()->keyBy('slot');
    expect(substr($hoje['cafe']->time, 0, 5))->toBe('07:00')
        ->and(substr($hoje['pre-treino']->time, 0, 5))->toBe('06:30')
        ->and(substr($hoje['lanche']->time, 0, 5))->toBe('10:30')
        ->and($hoje['jantar']->note)->toBeNull();
});

it('Preferências: restrição refaz; só cozinha ou "não curto" sugerem refazer (CA07, CA08)', function () {
    $base = ['restrictions' => [], 'other_restrictions' => [], 'pantry_items' => [], 'disliked_food_ids' => []];

    $this->putJson('/api/v1/profile/preferences', [...$base, 'pantry_items' => ['maca']])->assertJsonPath('meta.plan_effect', 'regeneration_suggested');
    $this->putJson('/api/v1/profile/preferences', [...$base, 'pantry_items' => ['maca'], 'disliked_food_ids' => [Food::where('slug', 'jilo')->value('id')]])
        ->assertJsonPath('meta.plan_effect', 'regeneration_suggested');
    $this->putJson('/api/v1/profile/preferences', [...$base, 'pantry_items' => ['maca'], 'disliked_food_ids' => [Food::where('slug', 'jilo')->value('id')], 'restrictions' => ['lactose']])
        ->assertJsonPath('meta.plan_effect', 'regeneration_started');
});
