<?php

use App\Models\NutriConversation;
use App\Models\NutriMessage;
use App\Models\User;
use Carbon\CarbonImmutable;

it('apagar a conversa apaga as mensagens; apagar o usuário apaga as conversas', function () {
    $user = User::factory()->onboarded()->create();
    $conversa = $user->conversations()->create(['title' => 'Arroz']);
    $conversa->messages()->create(['role' => 'user', 'content' => 'Oi']);

    $conversa->delete();
    expect(NutriMessage::count())->toBe(0);

    $user->conversations()->create()->messages()->create(['role' => 'user', 'content' => 'Oi']);
    $user->delete();
    expect(NutriConversation::count())->toBe(0)->and(NutriMessage::count())->toBe(0);
});

it('guarda cartão, ações e sugestões como JSON e diz se as ações ainda valem', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'America/Sao_Paulo'));
    $conversa = User::factory()->onboarded()->create()->conversations()->create();
    $mensagem = $conversa->messages()->create([
        'role' => 'assistant', 'content' => 'Pode.',
        'card' => ['type' => 'swap'], 'actions' => [['index' => 0, 'kind' => 'dispensar', 'label' => 'Agora não']],
        'follow_up_suggestions' => ['E no jantar?'],
    ]);

    expect($mensagem->fresh()->card)->toBe(['type' => 'swap'])
        ->and($mensagem->fresh()->follow_up_suggestions)->toBe(['E no jantar?'])
        ->and($mensagem->actionsAvailable())->toBeTrue();

    $mensagem->update(['actions_resolved_at' => now(), 'resolved_action_index' => 0]);
    expect($mensagem->actionsAvailable())->toBeFalse();

    $outra = $conversa->messages()->create(['role' => 'assistant', 'content' => 'x', 'actions' => [['index' => 0]]]);
    $this->travel(1)->days();
    expect($outra->actionsAvailable())->toBeFalse(); // RN31: só no mesmo dia
});
