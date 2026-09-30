<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/** `{date}` das rotas do dia: `today` ou `YYYY-MM-DD` entre hoje − 90 e hoje + 6 (fuso da comunidade). */
final class DayDate
{
    public static function parse(string $value): CarbonImmutable
    {
        $today = CarbonImmutable::today();
        if ($value === 'today') {
            return $today;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $date = null;
        }

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value
            || $date->lt($today->subDays(90)) || $date->gt($today->addDays(6))) {
            throw ValidationException::withMessages(['date' => 'Escolha um dia entre os últimos 90 e os próximos 6.']);
        }

        return $date;
    }
}
