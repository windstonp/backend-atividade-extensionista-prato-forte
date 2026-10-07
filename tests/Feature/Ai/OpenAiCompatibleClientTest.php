<?php

use App\Ai\AiOptions;
use App\Ai\AiUnavailableException;
use App\Ai\OpenAiCompatibleClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

$options = fn () => new AiOptions(purpose: 'plan', model: 'gpt-x', maxTokens: 100, json: true);

it('envia no formato OpenAI, pede JSON e devolve o conteúdo com os tokens', function () use ($options) {
    Http::fake(['ia.test/*' => Http::response([
        'choices' => [['message' => ['content' => '{"ok":true}']]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ])]);

    $result = (new OpenAiCompatibleClient('https://ia.test/v1', 'chave-secreta', 30))->chat([['role' => 'user', 'content' => 'oi']], $options());

    expect($result->content)->toBe('{"ok":true}')->and($result->promptTokens)->toBe(10)->and($result->completionTokens)->toBe(5);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://ia.test/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer chave-secreta')
        && $request['model'] === 'gpt-x'
        && $request['max_tokens'] === 100
        && $request['response_format'] === ['type' => 'json_object']);
});

it('resposta 5xx vira AiUnavailableException', function () use ($options) {
    Http::fake(['ia.test/*' => Http::response('fora do ar', 503)]);

    (new OpenAiCompatibleClient('https://ia.test/v1', 'chave', 30))->chat([], $options());
})->throws(AiUnavailableException::class);

it('sem conexão (depois das novas tentativas) vira AiUnavailableException', function () use ($options) {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    (new OpenAiCompatibleClient('https://ia.test/v1', 'chave', 30))->chat([], $options());
})->throws(AiUnavailableException::class);

it('sem chave configurada nem tenta chamar', function () use ($options) {
    Http::fake();

    expect(fn () => (new OpenAiCompatibleClient('https://ia.test/v1', null, 30))->chat([], $options()))
        ->toThrow(AiUnavailableException::class);
    Http::assertNothingSent();
});

it('junta as mensagens system do começo numa só (o Gemini seguia só a última e ignorava o formato)', function () use ($options) {
    Http::fake(['ia.test/*' => Http::response(['choices' => [['message' => ['content' => '{}']]], 'usage' => []])]);

    (new OpenAiCompatibleClient('https://ia.test/v1', 'k', 30))->chat([
        ['role' => 'system', 'content' => 'regras'],
        ['role' => 'system', 'content' => 'contexto'],
        ['role' => 'user', 'content' => 'oi'],
        ['role' => 'assistant', 'content' => 'olá'],
        ['role' => 'user', 'content' => 'e aí?'],
    ], $options());

    Http::assertSent(fn (Request $request) => $request['messages'] === [
        ['role' => 'system', 'content' => "regras\n\ncontexto"],
        ['role' => 'user', 'content' => 'oi'],
        ['role' => 'assistant', 'content' => 'olá'],
        ['role' => 'user', 'content' => 'e aí?'],
    ]);
});
