<?php

use App\Models\Rating;
use App\Models\User;

beforeEach(function () {
    seedCatalog();
    $this->user = login(User::factory()->onboarded()->create());
    $conversa = $this->user->conversations()->create(['title' => 'Arroz']);
    $this->pergunta = $conversa->messages()->create(['role' => 'user', 'content' => 'Posso trocar o arroz?']);
    $this->resposta = $conversa->messages()->create(['role' => 'assistant', 'content' => 'Pode.']);
});

function avaliar(array $corpo)
{
    return test()->putJson('/api/v1/ratings', $corpo);
}

it('marca 👍 numa resposta do Nutri e ela volta nas mensagens (CA01)', function () {
    avaliar(['rateable_type' => 'nutri_message', 'rateable_id' => $this->resposta->id, 'value' => 'up'])
        ->assertOk()
        ->assertExactJson(['data' => ['rateable_type' => 'nutri_message', 'rateable_id' => $this->resposta->id, 'value' => 'up', 'comment' => null]]);

    $mensagens = collect($this->getJson("/api/v1/conversations/{$this->resposta->conversation_id}/messages")->json('data'));
    expect($mensagens->firstWhere('id', $this->resposta->id)['rating'])->toBe(['value' => 'up', 'comment' => null]);
});

it('👎 com comentário e depois trocar: uma linha só (CA02)', function () {
    avaliar(['rateable_type' => 'nutri_message', 'rateable_id' => $this->resposta->id, 'value' => 'down', 'comment' => 'Não tenho batata-doce em casa.'])->assertOk();
    avaliar(['rateable_type' => 'nutri_message', 'rateable_id' => $this->resposta->id, 'value' => 'up'])->assertOk();

    expect(Rating::count())->toBe(1)->and(Rating::sole()->only(['value', 'comment']))->toBe(['value' => 'up', 'comment' => null]);
});

it('DELETE remove e é idempotente', function () {
    avaliar(['rateable_type' => 'nutri_message', 'rateable_id' => $this->resposta->id, 'value' => 'up'])->assertOk();
    $corpo = ['rateable_type' => 'nutri_message', 'rateable_id' => $this->resposta->id];

    $this->deleteJson('/api/v1/ratings', $corpo)->assertNoContent();
    $this->deleteJson('/api/v1/ratings', $corpo)->assertNoContent();

    expect(Rating::count())->toBe(0);
});

it('avalia o plano e ele traz a avaliação', function () {
    $plano = planoPronto($this->user);
    avaliar(['rateable_type' => 'meal_plan', 'rateable_id' => $plano->id, 'value' => 'down'])->assertOk();

    expect($this->getJson("/api/v1/plans/{$plano->id}")->json('data.rating'))->toBe(['value' => 'down', 'comment' => null]);
});

it('404 para a própria pergunta, mensagem de outra pessoa ou item que não existe (CA03)', function (Closure $alvo) {
    [$tipo, $id] = $alvo($this);
    avaliar(['rateable_type' => $tipo, 'rateable_id' => $id, 'value' => 'up'])->assertNotFound();

    expect(Rating::count())->toBe(0);
})->with([
    'pergunta do usuário' => [fn ($t) => ['nutri_message', $t->pergunta->id]],
    'de outra pessoa' => [function ($t) {
        $outra = User::factory()->onboarded()->create()->conversations()->create(['title' => 'x'])->messages()->create(['role' => 'assistant', 'content' => 'Oi']);

        return ['nutri_message', $outra->id];
    }],
    'não existe' => [fn ($t) => ['meal_plan', 999999]],
]);

it('valida o corpo', function (array $corpo, string $campo) {
    avaliar([...['rateable_type' => 'nutri_message', 'rateable_id' => $this->resposta->id, 'value' => 'up'], ...$corpo])
        ->assertUnprocessable()->assertJsonValidationErrors($campo);
})->with([
    [['rateable_type' => 'conversa'], 'rateable_type'],
    [['value' => 'meh'], 'value'],
    [['comment' => str_repeat('a', 501)], 'comment'],
]);

it('plano de outra pessoa: 404 no formato único', function () {
    $outro = planoPronto(User::factory()->onboarded()->create());

    avaliar(['rateable_type' => 'meal_plan', 'rateable_id' => $outro->id, 'value' => 'up'])
        ->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
});
