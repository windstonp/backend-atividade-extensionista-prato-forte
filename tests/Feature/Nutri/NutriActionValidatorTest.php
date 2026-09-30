<?php

use App\Models\DayMealItem;
use App\Models\Food;
use App\Models\Restriction;
use App\Models\User;
use App\Services\Nutri\NutriActionValidator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
    $this->carbo = itemDeHoje('carboidrato');
    $this->slot = $this->carbo->dayMeal->slot;
    $this->validar = fn (?array $acao) => app(NutriActionValidator::class)->validate($this->user->fresh(), $acao);
});

/** Um carboidrato permitido diferente do item, que o RN25 aceite. */
function outroCarbo(DayMealItem $item): Food
{
    $opcao = test()->getJson("/api/v1/days/today/items/{$item->id}/substitutions")->json('data.options.0');

    return Food::findOrFail($opcao['food_id']);
}

it('troca válida vira cartão com números do catálogo e três ações', function () {
    $destino = outroCarbo($this->carbo);

    $r = ($this->validar)(['type' => 'substituir', 'slot' => $this->slot, 'from_food_id' => $this->carbo->food_id, 'to_food_id' => $destino->id]);

    expect($r['card']['type'])->toBe('swap')
        ->and($r['card']['from']['name'])->toBe($this->carbo->food->name)
        ->and($r['card']['to']['name'])->toBe($destino->name)
        ->and($r['card']['carbs_before'])->toBe(round($this->carbo->food->carbs_per_100g * $this->carbo->grams / 100, 1))
        ->and($r['card']['calorie_delta'])->toBe($r['card']['to']['calories'] - $r['card']['from']['calories'])
        ->and(array_column($r['actions'], 'kind'))->toBe(['substituir', 'outra-opcao', 'dispensar'])
        ->and($r['actions'][0]['label'])->toBe('Substituir no '.mb_strtolower($this->carbo->dayMeal->name).' de hoje')
        ->and($r['actions'][0]['index'])->toBe(0);
});

it('descarta troca inválida', function (Closure $acao) {
    expect(($this->validar)($acao($this)))->toBeNull();
})->with([
    'slot que não existe' => [fn ($t) => ['type' => 'substituir', 'slot' => 'ceia', 'from_food_id' => $t->carbo->food_id, 'to_food_id' => 1]],
    'origem fora da refeição' => [fn ($t) => ['type' => 'substituir', 'slot' => $t->slot, 'from_food_id' => 999999, 'to_food_id' => 1]],
    'destino inexistente' => [fn ($t) => ['type' => 'substituir', 'slot' => $t->slot, 'from_food_id' => $t->carbo->food_id, 'to_food_id' => 999999]],
    'tipo desconhecido' => [fn ($t) => ['type' => 'apagar', 'slot' => $t->slot]],
    'sem ação' => [fn ($t) => null],
]);

it('destino proibido pela alergia é descartado (CA06)', function () {
    $castanha = Restriction::where('slug', 'castanhas')->sole()->foods()->firstOrFail();
    $this->user->restrictions()->sync([Restriction::where('slug', 'castanhas')->sole()->id]);

    expect(($this->validar)(['type' => 'substituir', 'slot' => $this->slot, 'from_food_id' => $this->carbo->food_id, 'to_food_id' => $castanha->id]))->toBeNull();
});

it('refeição inteira: 1–6 itens, 5–600 g, permitidos; cartão com macros e aviso de proteína', function () {
    $jantar = $this->user->dayMeals()->where('slot', 'jantar')->with('items.food')->sole();
    $ovo = Food::where('slug', 'ovos-cozidos')->firstOrFail();
    $brocolis = Food::where('slug', 'brocolis-no-vapor')->firstOrFail();

    $r = ($this->validar)(['type' => 'aplicar-refeicao', 'slot' => 'jantar', 'items' => [
        ['food_id' => $ovo->id, 'grams' => 100], ['food_id' => $brocolis->id, 'grams' => 80],
    ]]);

    expect($r['card']['type'])->toBe('meal')
        ->and($r['card']['title'])->toBe($jantar->name)
        ->and($r['card']['items'])->toHaveCount(2)
        ->and($r['card']['calories'])->toBe(collect($r['card']['items'])->sum('calories'))
        ->and($r['card']['warning'])->toMatch('/^Fica \d+ g de proteína abaixo do jantar original\.$/')
        ->and(array_column($r['actions'], 'kind'))->toBe(['aplicar-refeicao', 'outra-opcao', 'dispensar'])
        ->and($r['actions'][0]['label'])->toBe('Aplicar no jantar de hoje');

    foreach ([[], array_fill(0, 7, ['food_id' => $ovo->id, 'grams' => 50]), [['food_id' => $ovo->id, 'grams' => 700]], [['food_id' => $ovo->id, 'grams' => 3]]] as $itens) {
        expect(($this->validar)(['type' => 'aplicar-refeicao', 'slot' => 'jantar', 'items' => $itens]))->toBeNull();
    }
});
