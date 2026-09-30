<?php

namespace App\Ai;

final readonly class AiResult
{
    public function __construct(
        public string $content,
        public ?int $promptTokens,
        public ?int $completionTokens,
        public int $durationMs,
    ) {}
}
