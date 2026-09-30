<?php

namespace App\Jobs;

use App\Ai\AiClient;
use App\Ai\AiOptions;
use App\Ai\AiUnavailableException;
use App\Ai\Prompts\NutriPrompt;
use App\Models\NutriConversation;
use App\Models\NutriMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/** RN30 — memória: resume a conversa em até 600 caracteres. Falhar não afeta ninguém (tenta na próxima). */
class SummarizeConversationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $conversationId) {}

    public function handle(AiClient $ai): void
    {
        $conversation = NutriConversation::find($this->conversationId);
        if ($conversation === null) {
            return;
        }
        $new = $conversation->messages()
            ->when($conversation->summarized_message_id, fn ($q, $id) => $q->where('id', '>', $id))
            ->orderBy('id')->get(['id', 'role', 'content']);
        if ($new->isEmpty()) {
            return;
        }

        $messages = [['role' => 'system', 'content' => NutriPrompt::summary()]];
        if ($conversation->summary !== null) {
            $messages[] = ['role' => 'system', 'content' => 'Resumo anterior: '.$conversation->summary];
        }
        foreach ($new as $message) {
            $messages[] = ['role' => $message->role, 'content' => $message->content];
        }

        try {
            $result = $ai->chat($messages, new AiOptions('summary', (string) config('services.ai.model_chat'), 250, temperature: 0.2, userId: $conversation->user_id));
        } catch (AiUnavailableException) {
            return;
        }

        /** @var NutriMessage $last */
        $last = $new->last();
        $conversation->update(['summary' => Str::limit(trim($result->content), 600, '…'), 'summarized_message_id' => $last->id]);
    }
}
