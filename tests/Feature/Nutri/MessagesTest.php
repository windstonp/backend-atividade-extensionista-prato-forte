<?php

use App\Ai\Prompts\NutriPrompt;
use App\Models\NutriConversation;
use App\Models\NutriMessage;
use App\Models\Restriction;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 11:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
    $this->conversa = $this->user->conversations()->create();
    $this->perguntar = fn (string $texto, ?NutriConversation $c = null) => $this->postJson('/api/v1/conversations/'.($c ?? $this->conversa)->id.'/messages', ['content' => $texto]);
});

it('responde com as duas mensagens e dá título à conversa (RF20, RN28)', function () {
    ($this->perguntar)('Posso trocar o arroz por batata?')
        ->assertCreated()
        ->assertJsonPath('data.user_message.role', 'user')
        ->assertJsonPath('data.user_message.content', 'Posso trocar o arroz por batata?')
        ->assertJsonPath('data.assistant_message.role', 'assistant')
        ->assertJsonPath('data.assistant_message.card.type', 'swap')
        ->assertJsonPath('data.assistant_message.actions.0.kind', 'substituir')
        ->assertJsonPath('data.assistant_message.actions_available', true)
        ->assertJsonPath('data.assistant_message.follow_up_suggestions', ['E no jantar, o que como?', 'Por que a batata segura mais a fome?']);

    expect($this->conversa->fresh())
        ->title->toBe('Posso trocar o arroz por batata?')
        ->last_message_at->not->toBeNull();
});

it('envia à IA o system prompt, o contexto e as 20 últimas mensagens — nunca grava system (RN29, CA12)', function () {
    foreach (range(1, 12) as $i) {
        $this->conversa->messages()->create(['role' => 'user', 'content' => "pergunta {$i}"]);
        $this->conversa->messages()->create(['role' => 'assistant', 'content' => "resposta {$i}"]);
    }

    ($this->perguntar)('O que comer antes do treino?')->assertCreated();

    fakeAi()->assertSent('chat', function (array $mensagens) {
        expect($mensagens[0])->toBe(['role' => 'system', 'content' => NutriPrompt::system()])
            ->and($mensagens[1]['content'])->toStartWith('Contexto de agora (JSON): ')
            ->and(collect($mensagens)->where('role', '!=', 'system')->count())->toBe(21)
            ->and(end($mensagens))->toBe(['role' => 'user', 'content' => 'O que comer antes do treino?'])
            ->and(json_encode($mensagens))->not->toContain('pergunta 1"');
    });
    expect(NutriMessage::where('role', 'system')->exists())->toBeFalse();
});

it('sem sugestões da IA, usa a reserva (CA14)', function () {
    $sugestoes = ($this->perguntar)('Qual o sentido da vida?')->json('data.assistant_message.follow_up_suggestions');

    expect($sugestoes)->not->toBeEmpty()->and(count($sugestoes))->toBeLessThanOrEqual(3);
});

it('sugestão citando alimento proibido não aparece (CA14, RN45)', function () {
    $this->user->restrictions()->sync([Restriction::where('slug', 'castanhas')->sole()->id]);
    fakeAi()->queue('chat', json_encode(['reply' => 'Claro.', 'suggestions' => ['Posso comer castanha de caju?', 'E no jantar?']]));

    expect(($this->perguntar)('Oi')->json('data.assistant_message.follow_up_suggestions'))->toBe(['E no jantar?']);
});

it('IA propondo alimento proibido: resposta só com texto (CA06)', function () {
    $this->user->restrictions()->sync([Restriction::where('slug', 'castanhas')->sole()->id]);

    ($this->perguntar)('Posso pôr castanha no lanche?')
        ->assertCreated()
        ->assertJsonPath('data.assistant_message.card', null)
        ->assertJsonPath('data.assistant_message.actions', []);
});

it('IA fora do ar: 503 e nada gravado (CA09)', function () {
    fakeAi()->failNext('chat');

    ($this->perguntar)('Oi')->assertStatus(503)->assertJsonPath('code', 'AI_UNAVAILABLE');

    expect(NutriMessage::count())->toBe(0)->and($this->conversa->fresh()->title)->toBeNull();
});

it('valida a pergunta: vazia, só espaços, mais de 1.000', function (string $texto) {
    ($this->perguntar)($texto)->assertUnprocessable()->assertJsonValidationErrors(['content']);
})->with(['', '    ', str_repeat('a', 1001)]);

it('20 por minuto (RN33)', function () {
    foreach (range(1, 20) as $i) {
        ($this->perguntar)("pergunta {$i}")->assertCreated();
    }
    ($this->perguntar)('mais uma')->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
});

it('lista as mensagens mais recentes primeiro, de 30 em 30, com as ações só se ainda valem (CA07, CA08)', function () {
    ($this->perguntar)('Posso trocar o arroz por batata?');
    $this->travel(1)->days();

    $this->getJson("/api/v1/conversations/{$this->conversa->id}/messages")
        ->assertOk()
        ->assertJsonPath('data.0.role', 'assistant')
        ->assertJsonPath('data.0.actions', [])
        ->assertJsonPath('data.0.actions_available', false)
        ->assertJsonPath('data.1.role', 'user')
        ->assertJsonPath('meta.per_page', 30);
});

it('mensagens de conversa de outra pessoa: 404 (CA10)', function () {
    $dela = User::factory()->onboarded()->create()->conversations()->create();

    $this->getJson("/api/v1/conversations/{$dela->id}/messages")->assertNotFound();
    ($this->perguntar)('Oi', $dela)->assertNotFound();
});
