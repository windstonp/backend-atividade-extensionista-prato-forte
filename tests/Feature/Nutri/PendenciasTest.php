<?php

use App\Ai\AiClient;
use App\Ai\AiOptions;
use App\Ai\AiResult;
use App\Jobs\SummarizeConversationJob;
use App\Models\NutriConversation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 11:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
});

it('o resumo da mesma conversa não entra duas vezes na fila', function () {
    Queue::fake();

    SummarizeConversationJob::dispatch(10);
    SummarizeConversationJob::dispatch(10);

    Queue::assertPushed(SummarizeConversationJob::class, 1);
});

it('o resumo manda à IA no máximo as 40 mensagens mais recentes desde o último resumo', function () {
    $conversa = $this->user->conversations()->create(['title' => 'Longa', 'last_message_at' => now()]);
    foreach (range(1, 60) as $i) {
        $conversa->messages()->create(['role' => $i % 2 ? 'user' : 'assistant', 'content' => "m{$i}"]);
    }
    fakeAi()->queue('summary', 'Resumo.');

    (new SummarizeConversationJob($conversa->id))->handle(app(AiClient::class));

    fakeAi()->assertSent('summary', function (array $mensagens) {
        $conversa = array_values(array_filter($mensagens, fn ($m) => $m['role'] !== 'system'));
        expect($conversa)->toHaveCount(40)->and($conversa[0]['content'])->toBe('m21')->and(end($conversa)['content'])->toBe('m60');
    });
    expect($conversa->fresh()->summarized_message_id)->toBe($conversa->messages()->max('id'));
});

it('content que não é texto dá 422, não 500', function () {
    $conversa = $this->user->conversations()->create();

    $this->postJson("/api/v1/conversations/{$conversa->id}/messages", ['content' => ['x']])
        ->assertUnprocessable()->assertJsonValidationErrors('content');
});

it('conversa apagada enquanto a IA responde: 404 e nada gravado', function () {
    $conversa = $this->user->conversations()->create();
    $real = app(AiClient::class);
    app()->instance(AiClient::class, new class($real, $conversa->id) implements AiClient
    {
        public function __construct(private AiClient $real, private int $id) {}

        public function chat(array $messages, AiOptions $options): AiResult
        {
            NutriConversation::whereKey($this->id)->delete(); // a outra aba apagou

            return $this->real->chat($messages, $options);
        }
    });

    $this->postJson("/api/v1/conversations/{$conversa->id}/messages", ['content' => 'Posso trocar o arroz por batata?'])
        ->assertNotFound();

    expect(DB::table('nutri_messages')->count())->toBe(0);
});

it('a lista de conversas não faz uma consulta por conversa', function () {
    foreach (range(1, 15) as $i) {
        conversaCom($this->user, "Conversa {$i}", "resposta {$i}", "2026-09-{$i} 10:00");
    }
    DB::enableQueryLog();

    $dados = $this->getJson('/api/v1/conversations')->assertOk()->json('data');

    expect($dados)->toHaveCount(15)->and($dados[0])->toMatchArray(['message_count' => 2, 'preview' => 'resposta 15'])
        ->and(count(DB::getQueryLog()))->toBeLessThanOrEqual(8);
});
