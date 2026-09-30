<?php

use App\Ai\AiClient;
use App\Ai\AiOptions;
use App\Ai\AiUnavailableException;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('registra a chamada sem guardar conteúdo (RN44)', function () {
    $user = User::factory()->create();
    fakeAi()->queue('plan', '{"meals":[]}');

    app(AiClient::class)->chat([['role' => 'user', 'content' => 'segredo de saúde']], new AiOptions('plan', 'modelo-x', 100, userId: $user->id));

    expect(AiRequest::sole()->only(['user_id', 'purpose', 'model', 'status']))
        ->toBe(['user_id' => $user->id, 'purpose' => 'plan', 'model' => 'modelo-x', 'status' => 'ok'])
        ->and(Schema::getColumnListing('ai_requests'))->not->toContain('content', 'messages', 'prompt', 'response');
});

it('registra a falha e repassa a exceção', function () {
    fakeAi()->failNext('plan');

    expect(fn () => app(AiClient::class)->chat([], new AiOptions('plan', 'modelo-x', 100)))->toThrow(AiUnavailableException::class)
        ->and(AiRequest::sole()->status)->toBe('error');
});
