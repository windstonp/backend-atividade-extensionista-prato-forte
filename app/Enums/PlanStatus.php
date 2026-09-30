<?php

namespace App\Enums;

enum PlanStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';

    /** Ainda vai virar `ready` ou `failed` (RN19: só um por usuário). */
    public function isBusy(): bool
    {
        return $this === self::Pending || $this === self::Generating;
    }
}
