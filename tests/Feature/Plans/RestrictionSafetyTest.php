<?php

use App\Enums\PlanStatus;
use App\Models\DayMealItem;
use App\Models\Food;
use App\Models\User;
use App\Services\Foods\FoodFilter;
use App\Services\Plans\PlanService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/** Revisão final do 04A: nenhum caminho põe no prato um alimento que a restrição atual proíbe (RN17). */
beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
});

/** Proíbe um alimento por "outras restrições", sem passar pelo endpoint (como uma corrida faria). */
function proibir(User $user, Food $food): void
{
    $user->profile->update(['other_restrictions' => [$food->name]]);
    $user->unsetRelation('profile');
}

/** @return list<int> alimentos de hoje, como a API mostra */
function alimentosDeHoje(): array
{
    return collect(test()->getJson('/api/v1/days/today')->assertOk()->json('data.meals'))
        ->flatMap(fn ($meal) => array_column($meal['items'], 'food_id'))->all();
}

it('plano montado antes de uma restrição nova não é ativado; outro é pedido na hora (I1)', function () {
    $plano = planoPronto($this->user);
    $comida = $plano->meals()->with('items.food')->orderBy('position')->first()->items->first()->food;
    $plano->update(['status' => PlanStatus::Generating, 'is_active' => false]);
    proibir($this->user, $comida);

    expect(app(PlanService::class)->activate($plano->fresh()))->toBeFalse();

    expect($plano->fresh())
        ->status->toBe(PlanStatus::Failed)
        ->failure_reason->toBe('RESTRICTIONS_CHANGED');
    $novo = $this->user->activePlan()->with('meals.items')->sole();
    expect($novo->id)->toBeGreaterThan($plano->id)
        ->and($novo->meals->flatMap->items->pluck('food_id')->all())->not->toContain($comida->id);
});

it('hoje e os próximos dias nunca mostram alimento proibido, mesmo com o plano antigo ativo (I2)', function () {
    $plano = planoPronto($this->user);
    $comida = $plano->meals()->with('items.food')->orderBy('position')->first()->items->first()->food;
    proibir($this->user, $comida);

    expect(alimentosDeHoje())->not->toContain($comida->id)->not->toBeEmpty();
    $amanha = collect($this->getJson('/api/v1/days/2026-09-29')->assertOk()->json('data.meals'))
        ->flatMap(fn ($meal) => array_column($meal['items'], 'food_id'))->all();
    expect($amanha)->not->toContain($comida->id);
});

it('restrição nova tira na hora o alimento das refeições não feitas, mesmo se a IA falhar (I2)', function () {
    planoPronto($this->user);
    $item = itemDeHoje('proteina');
    fakeAi()->failNext('plan');
    fakeAi()->failNext('plan');

    $this->patchJson('/api/v1/profile/steps/restricoes', ['restrictions' => [], 'other_restrictions' => [$item->food->name]])
        ->assertJsonPath('meta.plan_effect', 'regeneration_started');

    expect(alimentosDeHoje())->not->toContain($item->food_id);
});

it('desfazer não devolve alimento que ficou proibido depois da troca (I2)', function () {
    planoPronto($this->user);
    $item = itemDeHoje('carboidrato');
    $original = $item->food;
    trocarPelaPrimeiraOpcao($item->id);
    proibir($this->user, $original);

    $this->postJson('/api/v1/days/today/undo')->assertOk();

    expect(DayMealItem::where('day_meal_id', $item->day_meal_id)->pluck('food_id')->all())->not->toContain($original->id);
});

it('pedido forçado cancela os planos ainda na fila; só o mais novo espera a IA (I3)', function () {
    planoPronto($this->user);
    Queue::fake();
    $service = app(PlanService::class);

    $a = $service->requestGeneration($this->user, force: true);
    $b = $service->requestGeneration($this->user, force: true);

    expect($a->fresh())->status->toBe(PlanStatus::Failed)->failure_reason->toBe('SUPERSEDED')
        ->and($b->fresh()->status)->toBe(PlanStatus::Pending);
});

it('"outras restrições" no plural também pegam o alimento (M7)', function (string $termo) {
    $this->user->profile->update(['other_restrictions' => [$termo]]);

    $permitidos = app(FoodFilter::class)->allowedFor($this->user->fresh());

    expect($permitidos->pluck('slug')->all())->not->toContain('camarao-cozido');
})->with(['camarões', 'Camarões', 'camaroes']);
