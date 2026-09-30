<?php

use App\Models\DayMealChange;
use App\Models\Food;
use App\Models\Restriction;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    $this->user->restrictions()->attach(Restriction::where('slug', 'castanhas')->sole());
    planoPronto($this->user);
});

it('troca o alimento, marca o original e registra para desfazer (RN26)', function () {
    $item = itemDeHoje('carboidrato');
    $opcoes = $this->getJson("/api/v1/days/today/items/{$item->id}/substitutions")->json('data.options');

    $dia = $this->postJson("/api/v1/days/today/items/{$item->id}/swap", ['food_id' => $opcoes[0]['food_id']])->assertOk()->json('data');

    $trocado = collect($dia['meals'])->flatMap(fn ($m) => $m['items'])->firstWhere('id', $item->id);
    expect($trocado['food_id'])->toBe($opcoes[0]['food_id'])
        ->and($trocado['grams'])->toBe($opcoes[0]['grams'])
        ->and($trocado['source'])->toBe('manual')
        ->and($trocado['replaced_from'])->toBe($item->food->name)
        ->and($dia['last_change']['text'])->toBe($item->food->name.' trocado por '.mb_strtolower(mb_substr($opcoes[0]['name'], 0, 1)).mb_substr($opcoes[0]['name'], 1))
        ->and(DayMealChange::sole()->type->value)->toBe('swap');
});

it('trocas seguidas guardam o original da refeição-modelo (RN26)', function () {
    $item = itemDeHoje('carboidrato');
    $original = $item->food->name;

    trocarPelaPrimeiraOpcao($item->id);
    trocarPelaPrimeiraOpcao($item->id);

    $this->getJson('/api/v1/days/today')->assertOk();
    expect($item->fresh()->replacedFood->name)->toBe($original);
});

it('trocar não muda o "feita" da refeição (RN26)', function () {
    $item = itemDeHoje('carboidrato');
    $slot = $item->dayMeal->slot;
    $this->patchJson("/api/v1/days/today/meals/{$slot}", ['done' => true]);

    trocarPelaPrimeiraOpcao($item->id);

    expect($item->dayMeal->fresh()->isDone())->toBeTrue();
});

it('recusa food_id que não está entre as opções, inclusive um alimento proibido forjado (RN17, Review Focus 1)', function () {
    $item = itemDeHoje('carboidrato');

    foreach (['castanha-de-caju', 'frango-grelhado'] as $slug) {
        $this->postJson("/api/v1/days/today/items/{$item->id}/swap", ['food_id' => Food::where('slug', $slug)->value('id')])
            ->assertStatus(409)
            ->assertJsonPath('code', 'SUBSTITUTION_NOT_ALLOWED');
    }
    expect($item->fresh()->food_id)->toBe($item->food_id)->and(DayMealChange::count())->toBe(0);
});

it('food_id é obrigatório', function () {
    $item = itemDeHoje('carboidrato');

    $this->postJson("/api/v1/days/today/items/{$item->id}/swap", [])->assertJsonValidationErrors(['food_id']);
});
