<?php

use App\Enums\ItemSource;
use App\Enums\MealSlot;
use App\Models\DayMealItem;
use App\Models\NutriMessage;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
    $this->getJson('/api/v1/days/today')->assertOk();
    $this->conversa = $this->user->conversations()->create();
    $this->resposta = function (string $pergunta): NutriMessage {
        $id = $this->postJson("/api/v1/conversations/{$this->conversa->id}/messages", ['content' => $pergunta])->json('data.assistant_message.id');

        return NutriMessage::findOrFail($id);
    };
    $this->aplicar = fn (NutriMessage $m, int $i = 0) => $this->postJson("/api/v1/messages/{$m->id}/actions/{$i}");
});

it('substituir troca o item de hoje com source nutri, confirma e deixa desfazer (CA05)', function () {
    $m = ($this->resposta)('Posso trocar o arroz por batata?');
    $card = $m->card;
    $refeicao = mb_strtolower(MealSlot::from($card['slot'])->label());

    ($this->aplicar)($m)
        ->assertOk()
        ->assertJsonPath('data.message.actions', [])
        ->assertJsonPath('data.message.actions_available', false)
        ->assertJsonPath('data.confirmation.content', "Feito. Seu {$refeicao} de hoje vai com ".mb_strtolower($card['to']['name']).'.')
        ->assertJsonPath('data.confirmation.actions.0.kind', 'ver-refeicao')
        ->assertJsonPath('data.day.last_change.text', fn ($t) => str_contains($t, 'trocado por'));

    $item = DayMealItem::where('food_id', $card['to']['food_id'])->whereHas('dayMeal', fn ($q) => $q->where('slot', $card['slot']))->sole();
    expect($item->source)->toBe(ItemSource::Nutri)->and($item->grams)->toBe((float) $card['to']['grams']);

    $this->postJson('/api/v1/days/today/undo')->assertOk();
    expect(DayMealItem::whereKey($item->id)->exists())->toBeFalse();
});

it('aplicar-refeicao troca o jantar inteiro', function () {
    $m = ($this->resposta)('Monta um jantar com ovo e brócolis?');

    ($this->aplicar)($m)->assertOk()->assertJsonPath('data.confirmation.content', 'Feito. Seu jantar de hoje foi trocado.');

    $jantar = $this->user->dayMeals()->where('slot', 'jantar')->with('items')->sole();
    expect($jantar->items->pluck('food_id')->all())->toBe(array_column($m->card['items'], 'food_id'))
        ->and($jantar->items->every(fn ($i) => $i->source === ItemSource::Nutri))->toBeTrue();
});

it('dispensar só some com as ações; recarregar não as traz de volta (CA07)', function () {
    $m = ($this->resposta)('Posso trocar o arroz por batata?');

    ($this->aplicar)($m, 2)->assertOk()->assertJsonMissingPath('data.confirmation');

    $this->getJson("/api/v1/conversations/{$this->conversa->id}/messages")->assertJsonPath('data.0.actions', []);
});

it('duas vezes: 409 ACTION_ALREADY_APPLIED', function () {
    $m = ($this->resposta)('Posso trocar o arroz por batata?');
    ($this->aplicar)($m)->assertOk();

    ($this->aplicar)($m)->assertStatus(409)->assertJsonPath('code', 'ACTION_ALREADY_APPLIED');
});

it('de ontem: 409 ACTION_EXPIRED com a data (CA08)', function () {
    $m = ($this->resposta)('Posso trocar o arroz por batata?');
    $this->travel(1)->days();

    ($this->aplicar)($m)->assertStatus(409)->assertJsonPath('code', 'ACTION_EXPIRED')->assertJsonPath('message', 'Essa sugestão era para 28/09.');
});

it('refeição já feita: 409 MEAL_ALREADY_DONE com o nome da refeição', function () {
    $m = ($this->resposta)('Monta um jantar com ovo e brócolis?');
    $this->patchJson('/api/v1/days/today/meals/jantar', ['done' => true]);

    ($this->aplicar)($m)->assertStatus(409)->assertJsonPath('code', 'MEAL_ALREADY_DONE')
        ->assertJsonPath('message', 'Esse jantar já está marcado como feito. Desmarque para trocar.');
});

it('alimento ficou proibido depois da resposta: 409 SUBSTITUTION_NOT_ALLOWED e o dia não muda (Review Focus 4)', function () {
    $m = ($this->resposta)('Posso trocar o arroz por batata?');
    $this->user->profile->update(['other_restrictions' => [$m->card['to']['name']]]);
    $antes = DayMealItem::pluck('food_id')->sort()->values()->all();

    ($this->aplicar)($m)->assertStatus(409)->assertJsonPath('code', 'SUBSTITUTION_NOT_ALLOWED');

    expect(DayMealItem::pluck('food_id')->sort()->values()->all())->toBe($antes)
        ->and($m->fresh()->actions_resolved_at)->toBeNull();
});

it('índice inexistente, ação só de interface, mensagem do usuário ou de outra pessoa: 404 (CA10)', function () {
    $m = ($this->resposta)('Posso trocar o arroz por batata?');
    $pergunta = $this->conversa->messages()->where('role', 'user')->firstOrFail();

    ($this->aplicar)($m, 9)->assertNotFound();
    ($this->aplicar)($m, 1)->assertNotFound(); // outra-opcao é de interface
    ($this->aplicar)($pergunta)->assertNotFound();

    login(User::factory()->onboarded()->create());
    ($this->aplicar)($m)->assertNotFound();
});
