<?php

namespace App\Ai;

use App\Models\AiRequest;

/** Embrulha qualquer AiClient e grava `ai_requests` (sem conteúdo — RN44). */
final class LoggingAiClient implements AiClient
{
    public function __construct(private readonly AiClient $inner) {}

    public function chat(array $messages, AiOptions $options): AiResult
    {
        $start = hrtime(true);
        try {
            $result = $this->inner->chat($messages, $options);
        } catch (AiUnavailableException $e) {
            $this->log($options, $e->timeout ? 'timeout' : 'error', null, null, (int) ((hrtime(true) - $start) / 1_000_000), 'AI_UNAVAILABLE');

            throw $e;
        }

        $this->log($options, 'ok', $result->promptTokens, $result->completionTokens, $result->durationMs, null);

        return $result;
    }

    private function log(AiOptions $options, string $status, ?int $promptTokens, ?int $completionTokens, int $durationMs, ?string $errorCode): void
    {
        AiRequest::create([
            'user_id' => $options->userId,
            'purpose' => $options->purpose,
            'model' => $options->model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'duration_ms' => $durationMs,
            'status' => $status,
            'error_code' => $errorCode,
        ]);
    }
}
