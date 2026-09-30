<?php

use App\Ai\AiClient;
use App\Jobs\SummarizeConversationJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    seedCatalog();
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
});

it('nova conversa enfileira o resumo da anterior com mensagens novas (RN30)', function () {
    $resumida = conversaCom($this->user, 'Velha', 'r', '2026-09-20 10:00');
    $resumida->update(['summary' => 'x', 'summarized_message_id' => $resumida->messages()->max('id')]);
    $anterior = conversaCom($this->user, 'Ontem', 'r', '2026-09-27 10:00');
    Queue::fake();

    $this->postJson('/api/v1/conversations')->assertCreated();

    Queue::assertPushed(SummarizeConversationJob::class, 1);
    Queue::assertPushed(SummarizeConversationJob::class, fn ($job) => $job->conversationId === $anterior->id);
});

it('resume só as mensagens novas e incorpora o resumo anterior', function () {
    $conversa = conversaCom($this->user, 'Arroz', 'Pode trocar.', '2026-09-27 10:00');
    $conversa->update(['summary' => 'Não gosta de peixe.', 'summarized_message_id' => $conversa->messages()->min('id')]);
    fakeAi()->queue('summary', 'Não gosta de peixe. Trocou o arroz por batata-doce.');

    (new SummarizeConversationJob($conversa->id))->handle(app(AiClient::class));

    fakeAi()->assertSent('summary', function (array $mensagens) {
        $texto = json_encode($mensagens, JSON_UNESCAPED_UNICODE);
        expect($texto)->toContain('Não gosta de peixe.')->toContain('Pode trocar.')->not->toContain('"Arroz"');
    });
    expect($conversa->fresh())
        ->summary->toBe('Não gosta de peixe. Trocou o arroz por batata-doce.')
        ->summarized_message_id->toBe($conversa->messages()->max('id'));
});

it('IA fora ou conversa apagada: termina sem erro e sem resumo', function () {
    $conversa = conversaCom($this->user, 'Arroz', 'r', '2026-09-27 10:00');
    fakeAi()->failNext('summary');

    (new SummarizeConversationJob($conversa->id))->handle(app(AiClient::class));
    expect($conversa->fresh()->summary)->toBeNull();

    $conversa->delete();
    (new SummarizeConversationJob($conversa->id))->handle(app(AiClient::class));
});

it('a próxima pergunta leva os 3 resumos mais recentes, nunca o de conversa apagada (CA03, CA11)', function () {
    foreach (['A' => '2026-09-21', 'B' => '2026-09-22', 'C' => '2026-09-23', 'D' => '2026-09-24'] as $titulo => $dia) {
        conversaCom($this->user, $titulo, 'r', "{$dia} 10:00")->update(['summary' => "resumo {$titulo}"]);
    }
    $this->user->conversations()->where('title', 'D')->first()->delete();
    $nova = $this->user->conversations()->create();

    $this->postJson("/api/v1/conversations/{$nova->id}/messages", ['content' => 'Oi'])->assertCreated();

    fakeAi()->assertSent('chat', function (array $mensagens) {
        $memoria = collect($mensagens)->first(fn ($m) => str_starts_with($m['content'], 'Memória de conversas anteriores:'));
        expect($memoria['content'])->toContain('resumo C')->toContain('resumo B')->toContain('resumo A')->not->toContain('resumo D');
    });
});
