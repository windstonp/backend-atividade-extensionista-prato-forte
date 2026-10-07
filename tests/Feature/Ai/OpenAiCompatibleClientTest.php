<?php

use App\Ai\AiOptions;
use App\Ai\AiUnavailableException;
use App\Ai\OpenAiCompatibleClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

it('modelo sobrecarregado (503/429) passa para o modelo reserva e registra o motivo do provedor', function () use ($options) {
    Log::spy();
    Http::fakeSequence('ia.test/*')
        ->push(['error' => ['code' => 503, 'message' => 'This model is currently experiencing high demand.', 'status' => 'UNAVAILABLE']], 503)
        ->push(['choices' => [['message' => ['content' => '{"ok":true}']]], 'usage' => []]);

    $result = (new OpenAiCompatibleClient('https://ia.test/v1', 'k', 30, 'modelo-reserva'))->chat([['role' => 'user', 'content' => 'oi']], $options());

    expect($result->content)->toBe('{"ok":true}');
    $modelos = [];
    Http::assertSent(function (Request $request) use (&$modelos) {
        $modelos[] = $request['model'];

        return true;
    });
    expect($modelos)->toBe(['gpt-x', 'modelo-reserva']);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => $msg === 'ai.provider_error'
        && $ctx['status'] === 503 && $ctx['model'] === 'gpt-x' && str_contains($ctx['reason'], 'high demand'));
});

it('sem modelo reserva, o 503 continua virando AiUnavailableException', function () use ($options) {
    Http::fake(['ia.test/*' => Http::response(['error' => ['message' => 'busy']], 503)]);

    expect(fn () => (new OpenAiCompatibleClient('https://ia.test/v1', 'k', 30))->chat([['role' => 'user', 'content' => 'oi']], $options()))
        ->toThrow(AiUnavailableException::class);
    Http::assertSentCount(1);
});

it('erro que não é de sobrecarga (400) não tenta o reserva', function () use ($options) {
    Http::fake(['ia.test/*' => Http::response(['error' => ['message' => 'bad']], 400)]);

    expect(fn () => (new OpenAiCompatibleClient('https://ia.test/v1', 'k', 30, 'modelo-reserva'))->chat([['role' => 'user', 'content' => 'oi']], $options()))
        ->toThrow(AiUnavailableException::class);
    Http::assertSentCount(1);
});

it('modelo principal que estoura o tempo também passa para o reserva', function () use ($options) {
    $chamadas = 0;
    Http::fake(function (Request $request) use (&$chamadas) {
        $chamadas++;
        if ($request['model'] === 'gpt-x') {
            throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds');
        }

        return Http::response(['choices' => [['message' => ['content' => 'ok']]], 'usage' => []]);
    });

    $result = (new OpenAiCompatibleClient('https://ia.test/v1', 'k', 30, 'modelo-reserva'))->chat([['role' => 'user', 'content' => 'oi']], $options());

    expect($result->content)->toBe('ok');
});
