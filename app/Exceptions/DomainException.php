<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/** Regra de negócio violada. Services lançam; `ApiExceptionRenderer` responde. */
final class DomainException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly ErrorCode $errorCode,
        public readonly array $details = [],
        ?string $message = null,
    ) {
        parent::__construct($message ?? $errorCode->message());
    }
}
