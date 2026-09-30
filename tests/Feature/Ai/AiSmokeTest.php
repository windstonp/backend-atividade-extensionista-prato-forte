<?php

use App\Ai\AiClient;
use App\Ai\AiOptions;
use App\Ai\AiResult;
use App\Ai\AiUnavailableException;

it('com a IA falsa, avisa que não há o que testar', function () {
    config(['services.ai.driver' => 'fake']);

    $this->artisan('ai:smoke')->expectsOutputToContain('AI_DRIVER=fake')->assertSuccessful();
});

it('com a IA de verdade, pergunta, mostra modelo, tempo e tokens', function () {
    config(['services.ai.driver' => 'openai', 'services.ai.model_chat' => 'gpt-4o-mini']);
    app()->instance(AiClient::class, new class implements AiClient
    {
        public function chat(array $messages, AiOptions $options): AiResult
        {
            return new AiResult('ok', 12, 1, 840);
        }
    });

    $this->artisan('ai:smoke')->expectsOutputToContain('modelo gpt-4o-mini, 840 ms, tokens 12+1')->assertSuccessful();
});

it('IA fora do ar: mensagem clara e falha, sem stack trace', function () {
    config(['services.ai.driver' => 'openai']);
    app()->instance(AiClient::class, new class implements AiClient
    {
        public function chat(array $messages, AiOptions $options): AiResult
        {
            throw new AiUnavailableException('HTTP 401');
        }
    });

    $this->artisan('ai:smoke')->expectsOutputToContain('A IA não respondeu')->assertFailed();
});
