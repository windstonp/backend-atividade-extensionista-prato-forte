<?php

use App\Models\NutriConversation;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = login(User::factory()->onboarded()->create());
});

function conversaCom(User $user, string $titulo, string $ultima, string $quando): NutriConversation
{
    $conversa = $user->conversations()->create(['title' => $titulo, 'last_message_at' => CarbonImmutable::parse($quando)]);
    $conversa->messages()->create(['role' => 'user', 'content' => $titulo]);
    $conversa->messages()->create(['role' => 'assistant', 'content' => $ultima]);

    return $conversa;
}

it('lista só conversas com mensagens, da mais recente para a mais antiga', function () {
    conversaCom($this->user, 'Antiga', 'Resposta antiga', '2026-09-20 10:00');
    conversaCom($this->user, 'Nova', 'Pode. No seu almoço os 150 g de arroz…', '2026-09-22 12:10');
    $this->user->conversations()->create(); // vazia: não aparece

    $this->getJson('/api/v1/conversations')
        ->assertOk()
        ->assertJsonPath('data.*.title', ['Nova', 'Antiga'])
        ->assertJsonPath('data.0.preview', 'Pode. No seu almoço os 150 g de arroz…')
        ->assertJsonPath('data.0.message_count', 2)
        ->assertJsonPath('meta.per_page', 15);
});

it('pagina com cursor de 15 em 15', function () {
    foreach (range(1, 16) as $i) {
        conversaCom($this->user, "C{$i}", 'r', "2026-09-01 10:{$i}");
    }

    $primeira = $this->getJson('/api/v1/conversations')->assertJsonCount(15, 'data');
    $this->getJson('/api/v1/conversations?cursor='.$primeira->json('meta.next_cursor'))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'C1')
        ->assertJsonPath('meta.next_cursor', null);
});

it('nova conversa: cria (201) e depois reaproveita a vazia (200) — CA04', function () {
    $id = $this->postJson('/api/v1/conversations')->assertCreated()->assertJsonPath('data.message_count', 0)->json('data.id');

    $this->postJson('/api/v1/conversations')->assertOk()->assertJsonPath('data.id', $id);
    expect(NutriConversation::count())->toBe(1);
});

it('abre e apaga a própria conversa; a de outra pessoa é 404 (CA10, CA11)', function () {
    $minha = conversaCom($this->user, 'Minha', 'r', '2026-09-22 10:00');
    $outra = conversaCom(User::factory()->onboarded()->create(), 'Dela', 'r', '2026-09-22 10:00');

    $this->getJson("/api/v1/conversations/{$minha->id}")->assertOk()->assertJsonPath('data.title', 'Minha');
    $this->getJson("/api/v1/conversations/{$outra->id}")->assertNotFound();
    $this->deleteJson("/api/v1/conversations/{$outra->id}")->assertNotFound();

    $this->deleteJson("/api/v1/conversations/{$minha->id}")->assertNoContent();
    expect(NutriConversation::find($minha->id))->toBeNull();
});

it('quem não concluiu o onboarding não conversa', function () {
    login(User::factory()->create());
    $this->getJson('/api/v1/conversations')->assertStatus(409)->assertJsonPath('code', 'ONBOARDING_INCOMPLETE');
});
