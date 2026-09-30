<?php

use App\Models\DayMeal;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 13:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
});

it('marcar o almoço soma exatamente as kcal dele e persiste (CA06)', function () {
    $almoco = $this->getJson('/api/v1/days/today')->json('data.meals.2');

    $this->patchJson('/api/v1/days/today/meals/almoco', ['done' => true])
        ->assertOk()
        ->assertJsonPath('data.meals.2.done', true)
        ->assertJsonPath('data.totals.consumed.calories', $almoco['calories'])
        ->assertJsonPath('data.totals.consumed.protein', $almoco['macros']['protein']);

    $this->getJson('/api/v1/days/today')->assertJsonPath('data.meals.2.done', true);
});

it('a próxima refeição é a primeira não feita', function () {
    $this->patchJson('/api/v1/days/today/meals/cafe', ['done' => true]);

    $this->patchJson('/api/v1/days/today/meals/lanche', ['done' => true])
        ->assertJsonPath('data.meals.*.is_next', [false, false, true, false, false]);
});

it('desmarcar volta o consumido a zero', function () {
    $this->patchJson('/api/v1/days/today/meals/almoco', ['done' => true]);

    $this->patchJson('/api/v1/days/today/meals/almoco', ['done' => false])
        ->assertJsonPath('data.meals.2.done', false)
        ->assertJsonPath('data.totals.consumed.calories', 0);
});

it('marcar de novo não muda a hora em que foi feita', function () {
    $this->patchJson('/api/v1/days/today/meals/cafe', ['done' => true]);
    $primeira = DayMeal::where('slot', 'cafe')->sole()->done_at;
    $this->travel(10)->minutes();

    $this->patchJson('/api/v1/days/today/meals/cafe', ['done' => true]);

    expect(DayMeal::where('slot', 'cafe')->sole()->done_at->equalTo($primeira))->toBeTrue();
});

it('ontem e amanhã não são editáveis (CA07, RN23)', function (string $data) {
    $this->patchJson("/api/v1/days/{$data}/meals/almoco", ['done' => true])
        ->assertStatus(409)
        ->assertJsonPath('code', 'DAY_NOT_EDITABLE');
})->with(['2026-09-27', '2026-09-29']);

it('slot que não existe responde 404 e "done" é obrigatório', function () {
    $this->patchJson('/api/v1/days/today/meals/ceia', ['done' => true])->assertNotFound();
    $this->patchJson('/api/v1/days/today/meals/cafe', [])->assertJsonValidationErrors(['done']);
});
