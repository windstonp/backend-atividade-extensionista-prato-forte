<?php

use App\Services\Nutri\ConversationService;

it('corta o título em 60 caracteres na fronteira de palavra (RN28)', function (string $pergunta, string $titulo) {
    expect(ConversationService::title($pergunta))->toBe($titulo);
})->with([
    'curta' => ['Posso trocar o arroz por batata?', 'Posso trocar o arroz por batata?'],
    'longa' => [
        'Posso trocar o arroz branco do almoço por batata-doce cozida sem perder energia para o treino?',
        'Posso trocar o arroz branco do almoço por batata-doce cozida…',
    ],
    'espaços' => ["  Oi,   tudo   bem?  \n", 'Oi, tudo bem?'],
    'palavra gigante' => [str_repeat('a', 70), str_repeat('a', 60).'…'],
]);
