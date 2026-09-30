<?php

namespace App\Ai;

/** Porta única para a IA generativa (integracao-ia.md §2). */
interface AiClient
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     *
     * @throws AiUnavailableException
     */
    public function chat(array $messages, AiOptions $options): AiResult;
}
