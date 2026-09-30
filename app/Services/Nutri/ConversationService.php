<?php

namespace App\Services\Nutri;

use App\Jobs\SummarizeConversationJob;
use App\Models\NutriConversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** RN28 — conversas com o Nutri. */
class ConversationService
{
    /**
     * Começa uma conversa; se já houver uma vazia, ela volta (sem lixo). Lock no usuário: duas abas não criam duas.
     *
     * @return array{conversation: NutriConversation, created: bool}
     */
    public function start(User $user): array
    {
        $result = DB::transaction(function () use ($user) {
            User::whereKey($user->id)->lockForUpdate()->first();

            $empty = $user->conversations()->whereNull('last_message_at')->latest('id')->first();
            if ($empty !== null) {
                return ['conversation' => $empty, 'created' => false];
            }

            return ['conversation' => $user->conversations()->create(), 'created' => true];
        });

        // RN30 — memória: a conversa anterior mais recente com mensagens novas desde o último resumo.
        $previous = $user->conversations()
            ->whereKeyNot($result['conversation']->id)
            ->whereNotNull('last_message_at')
            ->orderByDesc('last_message_at')
            ->first();
        if ($previous !== null && $previous->messages()->where('id', '>', (int) $previous->summarized_message_id)->exists()) {
            SummarizeConversationJob::dispatch($previous->id);
        }

        return $result;
    }

    /** Título = primeira pergunta, até 60 caracteres, cortada na fronteira de palavra (+ "…"). */
    public static function title(string $question): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $question));
        if (mb_strlen($clean) <= 60) {
            return $clean;
        }

        $cut = mb_substr($clean, 0, 61);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > 0 ? mb_substr($cut, 0, $space) : mb_substr($clean, 0, 60), ' ,.;:').'…';
    }
}
