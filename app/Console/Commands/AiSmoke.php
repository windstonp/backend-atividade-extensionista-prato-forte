<?php

namespace App\Console\Commands;

use App\Ai\AiClient;
use App\Ai\AiOptions;
use App\Ai\AiUnavailableException;
use Illuminate\Console\Command;

/** Confere a IA de verdade antes da demonstração (estrategia-de-testes.md §IA). Nunca roda nos testes automáticos. */
class AiSmoke extends Command
{
    protected $signature = 'ai:smoke';

    protected $description = 'Faz uma pergunta mínima à IA configurada e mostra se respondeu';

    public function handle(AiClient $ai): int
    {
        if (config('services.ai.driver') !== 'openai') {
            $this->warn('AI_DRIVER=fake: a IA é a falsa, não há o que testar. Configure AI_DRIVER=openai, AI_BASE_URL e AI_API_KEY no .env.');

            return self::SUCCESS;
        }

        $modelo = (string) config('services.ai.model_chat');
        try {
            $r = $ai->chat([['role' => 'user', 'content' => 'Responda apenas: ok']], new AiOptions('smoke', $modelo, 16, temperature: 0.0));
        } catch (AiUnavailableException $e) {
            $this->error("A IA não respondeu ({$e->getMessage()}). Confira AI_BASE_URL, AI_API_KEY e o saldo da conta.");

            return self::FAILURE;
        }

        $this->info("A IA respondeu: modelo {$modelo}, {$r->durationMs} ms, tokens {$r->promptTokens}+{$r->completionTokens}.");

        return self::SUCCESS;
    }
}
