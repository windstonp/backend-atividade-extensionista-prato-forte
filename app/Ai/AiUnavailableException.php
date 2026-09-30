<?php

namespace App\Ai;

use RuntimeException;
use Throwable;

/** IA fora do ar, lenta demais ou sem configuração. Vira `AI_UNAVAILABLE` para quem chama. */
final class AiUnavailableException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $timeout = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
