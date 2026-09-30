<?php

namespace App\Ai\Schemas;

use RuntimeException;

/** A IA respondeu fora do contrato. A mensagem vai de volta para ela na nova tentativa. */
final class InvalidAiResponse extends RuntimeException {}
