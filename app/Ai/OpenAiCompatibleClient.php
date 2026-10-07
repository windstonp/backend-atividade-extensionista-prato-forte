<?php

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/** `POST {base}/chat/completions` (OpenAI, aimlapi.com…). Sem streaming no MVP. */
final class OpenAiCompatibleClient implements AiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeoutSeconds,
    ) {}

    public function chat(array $messages, AiOptions $options): AiResult
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new AiUnavailableException('AI_API_KEY não configurada.');
        }

        $body = [
            'model' => $options->model,
            'messages' => self::mergeLeadingSystem($messages),
            'max_tokens' => $options->maxTokens,
            'temperature' => $options->temperature,
        ];
        if ($options->json) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $start = hrtime(true);
        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->retry(2, 500, fn (Throwable $e) => $e instanceof ConnectionException, throw: false)
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', $body);
        } catch (ConnectionException $e) {
            throw new AiUnavailableException('Sem conexão com a IA.', str_contains($e->getMessage(), 'timed out'), $e);
        }

        if (! $response->successful()) {
            throw new AiUnavailableException("A IA respondeu {$response->status()}.");
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw new AiUnavailableException('A IA respondeu sem conteúdo.');
        }

        return new AiResult(
            $content,
            is_int($response->json('usage.prompt_tokens')) ? $response->json('usage.prompt_tokens') : null,
            is_int($response->json('usage.completion_tokens')) ? $response->json('usage.completion_tokens') : null,
            (int) ((hrtime(true) - $start) / 1_000_000),
        );
    }

    /**
     * As mensagens `system` do começo viram uma só: o Gemini (API compatível) seguia só a última
     * e ignorava o formato pedido na primeira. Na OpenAI o resultado é o mesmo.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return list<array{role: string, content: string}>
     */
    private static function mergeLeadingSystem(array $messages): array
    {
        $system = [];
        while ($messages !== [] && $messages[0]['role'] === 'system') {
            $system[] = array_shift($messages)['content'];
        }

        return $system === [] ? $messages : [['role' => 'system', 'content' => implode("\n\n", $system)], ...$messages];
    }
}
