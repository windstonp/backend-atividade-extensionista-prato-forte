<?php

use App\Enums\PlanStatus;
use App\Jobs\GeneratePlanJob;
use App\Models\Food;
use App\Models\MealPlan;
use App\Models\PlanMealItem;
use App\Models\Restriction;
use App\Models\User;
use App\Services\Plans\PlanService;

beforeEach(function () {
    seedCatalog();
    $this->user = User::factory()->onboarded()->create(['name' => 'Camila Réus', 'email' => 'camila@exemplo.com']);
    $this->user->restrictions()->attach(Restriction::where('slug', 'castanhas')->sole());
});

function kcalEProteina(MealPlan $plan): array
{
    $itens = PlanMealItem::with('food')->whereIn('plan_meal_id', $plan->meals()->pluck('id'))->get();

    return [
        $itens->sum(fn ($i) => $i->food->kcal_per_100g * $i->grams / 100),
        $itens->sum(fn ($i) => $i->food->protein_per_100g * $i->grams / 100),
    ];
}

it('gera um plano válido, pronto e ativo, com os horários do RN14 (CA01, CA04)', function () {
    $plan = app(PlanService::class)->requestGeneration($this->user)->fresh();

    expect($plan->status)->toBe(PlanStatus::Ready)
        ->and($plan->is_active)->toBeTrue()
        ->and($plan->attempts)->toBe(1)
        ->and([$plan->target_kcal, $plan->target_protein_g])->toBe([2250, 115])
        ->and($plan->meals()->pluck('time', 'slot')->all())->toBe([
            'cafe' => '07:00:00', 'lanche' => '10:00:00', 'almoco' => '12:30:00', 'pre-treino' => '17:30:00', 'jantar' => '20:30:00',
        ])
        ->and($plan->meals()->pluck('name')->all())->toBe(['Café da manhã', 'Lanche da manhã', 'Almoço', 'Pré-treino', 'Jantar']);

    [$kcal, $proteina] = kcalEProteina($plan);
    expect(abs($kcal - 2250) / 2250)->toBeLessThanOrEqual(0.10)
        ->and($proteina)->toBeGreaterThanOrEqual(0.9 * 115);
});

it('recusa o alimento proibido da 1ª resposta e aceita a 2ª (CA02, RN17)', function () {
    $castanha = Food::where('slug', 'castanha-de-caju')->value('id');
    $proibido = ['food_id' => $castanha, 'grams' => 50];
    fakeAi()->queue('plan', json_encode(['meals' => array_map(
        fn (string $slot) => ['slot' => $slot, 'items' => [$proibido]],
        ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar'],
    )]));

    $plan = app(PlanService::class)->requestGeneration($this->user)->fresh();

    expect($plan->status)->toBe(PlanStatus::Ready)
        ->and($plan->attempts)->toBe(2)
        ->and(PlanMealItem::where('food_id', $castanha)->exists())->toBeFalse();
    fakeAi()->assertSent('plan', function (array $mensagens) use ($castanha) {
        if (count($mensagens) > 2) {
            expect(end($mensagens)['content'])->toContain('Corrija')->toContain((string) $castanha);
        }
    });
});

it('duas respostas fora do contrato deixam o plano como falhou (CA03)', function () {
    fakeAi()->queue('plan', 'não sei');
    fakeAi()->queue('plan', '{"meals": "nada"}');

    $plan = app(PlanService::class)->requestGeneration($this->user)->fresh();

    expect($plan->status)->toBe(PlanStatus::Failed)
        ->and($plan->failure_reason)->toBe('AI_INVALID_RESPONSE')
        ->and($plan->attempts)->toBe(2)
        ->and($plan->is_active)->toBeFalse();
});

it('IA fora do ar deixa o plano como falhou com AI_UNAVAILABLE', function () {
    fakeAi()->failNext('plan');

    $plan = app(PlanService::class)->requestGeneration($this->user)->fresh();

    expect($plan->status)->toBe(PlanStatus::Failed)->and($plan->failure_reason)->toBe('AI_UNAVAILABLE');
});

it('não manda nome nem e-mail para a IA, e guarda o que mandou no plano (minimização)', function () {
    $plan = app(PlanService::class)->requestGeneration($this->user)->fresh();

    fakeAi()->assertSent('plan', function (array $mensagens) {
        $tudo = implode("\n", array_column($mensagens, 'content'));
        expect($tudo)->not->toContain('Camila Réus')->not->toContain('camila@exemplo.com');
    });
    expect($plan->inputs)->toHaveKeys(['pessoa', 'metas_diarias', 'horarios', 'alimentos_permitidos', 'prompt_version'])
        ->and(collect($plan->inputs['alimentos_permitidos'])->pluck('id'))->not->toContain(Food::where('slug', 'castanha-de-caju')->value('id'));
});

it('quem não come nada de origem animal também recebe plano válido', function () {
    $this->user->restrictions()->sync(Restriction::where('slug', 'sem-animal')->pluck('id'));

    expect(app(PlanService::class)->requestGeneration($this->user)->fresh()->status)->toBe(PlanStatus::Ready);
});

it('o plano novo vira o único ativo (RN20)', function () {
    $primeiro = app(PlanService::class)->requestGeneration($this->user);
    $segundo = app(PlanService::class)->requestGeneration($this->user);

    expect($primeiro->fresh()->is_active)->toBeFalse()
        ->and($segundo->fresh()->is_active)->toBeTrue()
        ->and($this->user->activePlan()->value('id'))->toBe($segundo->id);
});

it('um plano mais antigo que termina depois do mais novo não é ativado (SUPERSEDED)', function () {
    $antigo = $this->user->mealPlans()->create(['status' => 'pending', 'target_kcal' => 2250, 'target_protein_g' => 115, 'target_carbs_g' => 305, 'target_fat_g' => 65, 'inputs' => []]);
    $novo = app(PlanService::class)->requestGeneration($this->user, force: true);

    GeneratePlanJob::dispatchSync($antigo->id);

    expect($antigo->fresh()->status)->toBe(PlanStatus::Failed)
        ->and($antigo->fresh()->failure_reason)->toBe('SUPERSEDED')
        ->and($novo->fresh()->is_active)->toBeTrue();
});

it('plans:fail-stale marca como TIMEOUT o que está gerando há mais de 3 min (RN19)', function () {
    $velho = $this->user->mealPlans()->create(['status' => 'generating', 'target_kcal' => 1, 'target_protein_g' => 1, 'target_carbs_g' => 1, 'target_fat_g' => 1, 'inputs' => []]);
    $velho->forceFill(['created_at' => now()->subMinutes(4)])->save();
    $recente = $this->user->mealPlans()->create(['status' => 'pending', 'target_kcal' => 1, 'target_protein_g' => 1, 'target_carbs_g' => 1, 'target_fat_g' => 1, 'inputs' => []]);

    $this->artisan('plans:fail-stale')->assertSuccessful();

    expect($velho->fresh()->failure_reason)->toBe('TIMEOUT')
        ->and($velho->fresh()->status)->toBe(PlanStatus::Failed)
        ->and($recente->fresh()->status)->toBe(PlanStatus::Pending);
});
