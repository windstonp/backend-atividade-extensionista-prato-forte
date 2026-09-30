<?php

namespace App\Services\Nutri;

use App\Ai\AiClient;
use App\Ai\AiOptions;
use App\Ai\AiUnavailableException;
use App\Ai\Prompts\NutriPrompt;
use App\Ai\Schemas\NutriResponse;
use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use App\Models\NutriConversation;
use App\Models\NutriMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** RF20 — pergunta ao Nutri com contexto (RN29), ação validada (RN31) e sugestões filtradas (RN45). */
class NutriChatService
{
    private const HISTORY = 20;

    private const MEMORY = 3;

    public function __construct(
        private readonly AiClient $ai,
        private readonly NutriContextBuilder $context,
        private readonly NutriActionValidator $validator,
        private readonly FollowUpSanitizer $sanitizer,
        private readonly SuggestionBuilder $suggestions,
    ) {}

    /** @return array{user: NutriMessage, assistant: NutriMessage} */
    public function send(User $user, NutriConversation $conversation, string $content): array
    {
        $messages = [
            ['role' => 'system', 'content' => NutriPrompt::system()],
            ['role' => 'system', 'content' => NutriPrompt::context($this->context->forAi($user))],
        ];
        $memory = $this->memory($user, $conversation);
        if ($memory !== []) {
            $messages[] = ['role' => 'system', 'content' => NutriPrompt::memory($memory)];
        }
        $messages = [...$messages, ...$this->messagesFor($conversation), ['role' => 'user', 'content' => $content]];

        try {
            $result = $this->ai->chat($messages, new AiOptions('chat', (string) config('services.ai.model_chat'), 700, json: true, temperature: 0.5, userId: $user->id));
        } catch (AiUnavailableException) {
            throw new DomainException(ErrorCode::AiUnavailable); // nada gravado (integracao-ia §4.4)
        }

        $response = NutriResponse::parse($result->content);
        $action = $this->validator->validate($user, $response->action);
        $followUps = $this->sanitizer->clean($response->suggestions, $content, $user);
        if ($followUps === []) {
            $followUps = $this->suggestions->continuations($user);
        }

        return DB::transaction(function () use ($conversation, $content, $response, $action, $followUps) {
            $question = $conversation->messages()->create(['role' => 'user', 'content' => $content]);
            $answer = $conversation->messages()->create([
                'role' => 'assistant',
                'content' => $response->reply,
                'follow_up' => $response->followUp,
                'follow_up_suggestions' => $followUps,
                'card' => $action['card'] ?? null,
                'actions' => $action['actions'] ?? [],
            ]);
            $conversation->update([
                'title' => $conversation->title ?? ConversationService::title($content),
                'last_message_at' => now(),
            ]);

            return ['user' => $question, 'assistant' => $answer];
        });
    }

    /**
     * @return list<array{role: string, content: string}> as 20 últimas, em ordem
     */
    public function messagesFor(NutriConversation $conversation): array
    {
        return $conversation->messages()->latest('id')->limit(self::HISTORY)->get(['role', 'content'])
            ->reverse()
            ->map(fn (NutriMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->values()->all();
    }

    /**
     * RN30 — resumos das 3 outras conversas mais recentes que têm resumo.
     *
     * @return list<string>
     */
    private function memory(User $user, NutriConversation $current): array
    {
        return $user->conversations()
            ->whereKeyNot($current->id)
            ->whereNotNull('summary')
            ->orderByDesc('last_message_at')
            ->limit(self::MEMORY)
            ->pluck('summary')
            ->map(fn ($s) => (string) $s)
            ->all();
    }
}
