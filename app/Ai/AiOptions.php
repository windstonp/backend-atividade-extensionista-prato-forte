<?php

namespace App\Ai;

final readonly class AiOptions
{
    /** @param string $purpose `plan` | `chat` | `summary` (vai para ai_requests) */
    public function __construct(
        public string $purpose,
        public string $model,
        public int $maxTokens,
        public bool $json = false,
        public float $temperature = 0.4,
        public ?int $userId = null,
    ) {}
}
