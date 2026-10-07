<?php

use App\Ai\Prompts\PlanPrompt;
use App\Enums\PlanStatus;
use App\Models\MealPlan;
use App\Models\PantryItem;
use App\Models\Restriction;
use App\Models\User;
use App\Services\Plans\PlanService;

beforeEach(fn () => seedCatalog());

/** kcal de cada refeição do plano (slot => kcal). */
function kcalPorRefeicao(MealPlan $plano): array
{
    return $plano->meals()->with('items.food')->get()->mapWithKeys(fn ($m) => [
        $m->slot => $m->items->sum(fn ($i) => $i->food->kcal_per_100g * $i->grams / 100),
    ])->all();
}

$perfis = [
    'Camila, com aveia na cozinha' => [fn () => tap(User::factory()->onboarded()->create(), fn (User $u) => $u->pantryItems()->sync(PantryItem::whereIn('slug', ['ovos', 'frango', 'arroz-e-feijao', 'batata-doce', 'banana', 'aveia', 'iogurte'])->pluck('id')))],
    'nada de origem animal' => [fn () => tap(User::factory()->onboarded()->create(), fn (User $u) => $u->restrictions()->sync(Restriction::where('slug', 'sem-animal')->pluck('id')))],
    'homem de 95 kg, meta alta' => [fn () => tap(User::factory()->onboarded()->create(), fn (User $u) => $u->profile->update(['sex' => 'masculino', 'start_weight_kg' => 95, 'height_cm' => 186, 'age' => 22]))],
];

it('a IA falsa monta porções de gente: nada passa de 2,5× a porção típica', function (Closure $pessoa) {
    $plano = app(PlanService::class)->requestGeneration($pessoa())->fresh();

    expect($plano->status)->toBe(PlanStatus::Ready);
    foreach ($plano->meals()->with('items.food')->get() as $refeicao) {
        foreach ($refeicao->items as $item) {
            expect($item->grams)->toBeLessThanOrEqual(2.5 * $item->food->typical_portion_g, "{$item->food->name} no {$refeicao->slot}: {$item->grams} g");
        }
    }
})->with($perfis);

it('a IA falsa divide as calorias entre as refeições como o prompt pede', function (Closure $pessoa) {
    $plano = app(PlanService::class)->requestGeneration($pessoa())->fresh();
    $kcal = kcalPorRefeicao($plano);
    $total = array_sum($kcal);

    foreach (PlanPrompt::DISTRIBUICAO_KCAL as $slot => $parte) {
        expect($kcal[$slot] / $total)->toBeGreaterThan($parte - 0.07, $slot)->toBeLessThan($parte + 0.07, $slot);
    }
})->with($perfis);

it('almoço e jantar levam carboidrato de prato (arroz, batata…), não aveia', function () {
    $camila = tap(User::factory()->onboarded()->create(), fn (User $u) => $u->pantryItems()->sync(PantryItem::whereIn('slug', ['ovos', 'frango', 'arroz-e-feijao', 'batata-doce', 'banana', 'aveia', 'iogurte'])->pluck('id')));
    $plano = app(PlanService::class)->requestGeneration($camila)->fresh();

    $carbos = $plano->meals()->whereIn('slot', ['almoco', 'jantar'])->with('items.food')->get()
        ->flatMap->items->filter(fn ($i) => $i->food->group === 'carboidrato');
    expect($carbos)->not->toBeEmpty();
    foreach ($carbos as $item) {
        expect($item->food->typical_portion_g)->toBeGreaterThanOrEqual(100, $item->food->name);
    }
});

it('o prompt manda a porção típica de cada alimento e a divisão das calorias', function () {
    $plano = app(PlanService::class)->requestGeneration(User::factory()->onboarded()->create())->fresh();

    expect($plano->inputs['prompt_version'])->toBe(PlanPrompt::VERSION)->toBeGreaterThanOrEqual(2)
        ->and($plano->inputs['distribuicao_kcal'])->toEqual(PlanPrompt::DISTRIBUICAO_KCAL)
        ->and($plano->inputs['alimentos_permitidos'][0])->toHaveKey('porcao_g');
    fakeAi()->assertSent('plan', fn (array $mensagens) => expect($mensagens[0]['content'])->toContain('porcao_g')->toContain('distribuicao_kcal'));
});
