<?php

namespace App\Services\Notifications;

use Carbon\CarbonImmutable;

/** RN38 — nada antes de acordar nem depois de dormir (dormir depois da meia-noite como no RN12). Puro. */
final class AwakeWindow
{
    public static function contains(string $wake, string $sleep, CarbonImmutable $moment): bool
    {
        $minutos = fn (string $hora) => (int) substr($hora, 0, 2) * 60 + (int) substr($hora, 3, 2);
        $acorda = $minutos($wake);
        $dorme = $minutos($sleep);
        $agora = $moment->hour * 60 + $moment->minute;

        return $dorme > $acorda
            ? $agora >= $acorda && $agora <= $dorme
            : $agora >= $acorda || $agora <= $dorme;
    }
}
