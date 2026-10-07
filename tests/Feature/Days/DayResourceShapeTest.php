<?php

use App\Models\Food;
use App\Models\Restriction;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
});

it('sem registro: refeição não feita, sem situação, consumido zero', function () {
    $almoco = collect($this->getJson('/api/v1/days/today')->json('data.meals'))->firstWhere('slot', 'almoco');

    expect($almoco)->toHaveKeys(['calories', 'macros', 'consumed', 'status', 'goal_met', 'items', 'entries'])
        ->and($almoco['done'])->toBeFalse()
        ->and($almoco['status'])->toBeNull()
        ->and($almoco['goal_met'])->toBeFalse()
        ->and($almoco['entries'])->toBe([])
        ->and($almoco['consumed']['calories'])->toBe(0)
        ->and($almoco['items'][0])->toHaveKeys(['measure', 'registered']);
});

it('situação e meta batida seguem RN48; consumido do dia vem dos registros (CA37, CA41)', function () {
    $almoco = collect($this->getJson('/api/v1/days/today')->json('data.meals'))->firstWhere('slot', 'almoco');
    $todos = array_map(fn ($i) => ['suggestion_item_id' => $i['id']], $almoco['items']);

    $dia = $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => $todos])->json('data');
    $depois = collect($dia['meals'])->firstWhere('slot', 'almoco');

    expect($depois['status'])->toBe(['calories' => 'ok', 'protein' => 'ok', 'fat' => 'ok'])
        ->and($depois['goal_met'])->toBeTrue()
        ->and($dia['totals']['consumed']['calories'])->toBe($depois['consumed']['calories']);
});

it('registro com restrição traz o conflito, sem bloquear (CA34)', function () {
    $lactose = Restriction::where('slug', 'lactose')->sole();
    $this->user->restrictions()->attach($lactose);
    $leite = Food::where('slug', 'leite-integral')->sole();

    $this->postJson('/api/v1/days/today/meals/cafe/entries', ['entries' => [['food_id' => $leite->id, 'amount' => 200]]])
        ->assertCreated()
        ->assertJsonPath('data.meals.0.entries.0.conflicts', [$lactose->label]);
});

it('hoje e ontem são editáveis; antes de ontem e o futuro não', function (string $data, bool $editavel) {
    $this->getJson("/api/v1/days/{$data}")->assertJsonPath('data.editable', $editavel);
})->with([['today', true], ['2026-10-06', true], ['2026-10-05', false], ['2026-10-08', false]]);
