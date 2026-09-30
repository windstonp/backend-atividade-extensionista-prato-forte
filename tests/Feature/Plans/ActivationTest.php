<?php

use App\Models\DayMeal;
use App\Models\User;
use Carbon\CarbonImmutable;

it('plano novo às 15:00 mantém café, lanche e almoço feitos e troca o resto (CA11, RN20)', function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 15:00', 'America/Sao_Paulo'));
    $user = login(User::factory()->onboarded()->create());
    $antigo = planoPronto($user);
    $this->getJson('/api/v1/days/today')->assertOk();
    foreach (['cafe', 'lanche', 'almoco'] as $slot) {
        $this->patchJson("/api/v1/days/today/meals/{$slot}", ['done' => true])->assertOk();
    }
    $feitas = DayMeal::whereIn('slot', ['cafe', 'lanche', 'almoco'])->with('items')->get()
        ->mapWithKeys(fn (DayMeal $m) => [$m->slot => [$m->id, $m->items->pluck('id')->all()]])->all();

    $novo = $this->postJson('/api/v1/plans')->assertAccepted()->json('data.id');

    $dia = DayMeal::with('items')->whereDate('date', today())->get()->keyBy('slot');
    foreach ($feitas as $slot => [$id, $itens]) {
        expect($dia[$slot]->id)->toBe($id)
            ->and($dia[$slot]->isDone())->toBeTrue()
            ->and($dia[$slot]->meal_plan_id)->toBe($antigo->id)
            ->and($dia[$slot]->items->pluck('id')->all())->toBe($itens);
    }
    expect($dia['pre-treino']->meal_plan_id)->toBe($novo)
        ->and($dia['jantar']->meal_plan_id)->toBe($novo)
        ->and($dia)->toHaveCount(5);
    $this->getJson('/api/v1/days/today')->assertJsonPath('data.meals.*.slot', ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar']);
});
