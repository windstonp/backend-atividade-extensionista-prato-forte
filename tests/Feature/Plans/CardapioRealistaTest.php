<?php

use App\Ai\Prompts\PlanPrompt;
use App\Enums\PlanStatus;
use App\Models\Food;
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
    'Camila da demonstração' => [fn () => tap(User::factory()->onboarded()->create(), function (User $u) {
        $u->profile->update(['start_weight_kg' => 56.8]);
        $u->pantryItems()->sync(PantryItem::whereIn('slug', ['ovos', 'frango', 'arroz-e-feijao', 'batata-doce', 'banana', 'aveia', 'iogurte'])->pluck('id'));
        $u->restrictions()->sync(Restriction::where('slug', 'castanhas')->pluck('id'));
        $u->dislikedFoods()->sync(Food::whereIn('slug', ['figado-bovino', 'jilo'])->pluck('id'));
    })],
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

    expect($plano->inputs['prompt_version'])->toBe(PlanPrompt::VERSION)->toBeGreaterThanOrEqual(3)
        ->and($plano->inputs['distribuicao_kcal'])->toEqual(PlanPrompt::DISTRIBUICAO_KCAL)
        ->and($plano->inputs['alimentos_permitidos'][0])->toHaveKey('porcao_g')
        // v3: a meta de kcal de cada refeição já calculada (modelos menores erram a conta da proporção).
        ->and($plano->inputs['meta_kcal_por_refeicao']['almoco'])->toBe((int) round($plano->target_kcal * 0.30))
        ->and(array_sum($plano->inputs['meta_kcal_por_refeicao']))->toBeGreaterThanOrEqual($plano->target_kcal - 2);
    fakeAi()->assertSent('plan', fn (array $mensagens) => expect($mensagens[0]['content'])->toContain('porcao_g')->toContain('meta_kcal_por_refeicao'));
});

it('café da manhã leva carboidrato de café (pão, aveia, tapioca), não arroz', function (Closure $pessoa) {
    $plano = app(PlanService::class)->requestGeneration($pessoa())->fresh();

    $carbos = $plano->meals()->where('slot', 'cafe')->with('items.food')->sole()->items->filter(fn ($i) => $i->food->group === 'carboidrato');
    foreach ($carbos as $item) {
        expect($item->food->typical_portion_g)->toBeLessThan(100, $item->food->name);
    }
})->with($perfis);

it('nenhuma refeição empilha mais de dois carboidratos', function (Closure $pessoa) {
    $plano = app(PlanService::class)->requestGeneration($pessoa())->fresh();

    foreach ($plano->meals()->with('items.food')->get() as $refeicao) {
        expect($refeicao->items->filter(fn ($i) => $i->food->group === 'carboidrato')->count())->toBeLessThanOrEqual(2, $refeicao->slot);
    }
})->with($perfis);

it('não sobra proteína demais: o dia fica até 25% acima da meta', function (Closure $pessoa) {
    $plano = app(PlanService::class)->requestGeneration($pessoa())->fresh();

    $proteina = $plano->meals()->with('items.food')->get()->flatMap->items->sum(fn ($i) => $i->food->protein_per_100g * $i->grams / 100);
    expect($proteina)->toBeLessThanOrEqual(1.25 * $plano->target_protein_g);
})->with($perfis);

it('pré-treino com carboidrato leve (pão, tapioca), não arroz', function (Closure $pessoa) {
    $plano = app(PlanService::class)->requestGeneration($pessoa())->fresh();

    $carbos = $plano->meals()->where('slot', 'pre-treino')->with('items.food')->sole()->items->filter(fn ($i) => $i->food->group === 'carboidrato');
    foreach ($carbos as $item) {
        expect($item->food->typical_portion_g)->toBeLessThan(100, $item->food->name);
    }
})->with($perfis);
