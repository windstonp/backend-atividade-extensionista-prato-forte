<?php

use App\Ai\AiOptions;
use App\Ai\AiUnavailableException;

it('responde os roteiros na ordem e registra o que recebeu', function () {
    fakeAi()->queue('plan', 'primeira');
    fakeAi()->queue('plan', 'segunda');
    $opcoes = new AiOptions('plan', 'fake', 100);

    expect(fakeAi()->chat([['role' => 'user', 'content' => 'a']], $opcoes)->content)->toBe('primeira')
        ->and(fakeAi()->chat([['role' => 'user', 'content' => 'b']], $opcoes)->content)->toBe('segunda')
        ->and(fakeAi()->sentCount('plan'))->toBe(2);
    fakeAi()->assertSent('plan', fn (array $mensagens) => expect($mensagens[0]['role'])->toBe('user'));
});

it('falha quando roteirizado', function () {
    fakeAi()->failNext('plan');

    fakeAi()->chat([], new AiOptions('plan', 'fake', 100));
})->throws(AiUnavailableException::class);

it('sem roteiro, reclama de propósito que não sabe responder', function () {
    fakeAi()->chat([], new AiOptions('summary', 'fake', 100));
})->throws(LogicException::class);
