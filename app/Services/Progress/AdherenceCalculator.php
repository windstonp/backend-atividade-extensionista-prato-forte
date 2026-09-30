<?php

namespace App\Services\Progress;

use Carbon\CarbonImmutable;

/**
 * RN36 — constância dos últimos 28 dias (inclui hoje). Sequência = dias completos seguidos terminando ontem. Puro.
 */
final class AdherenceCalculator
{
    public const JANELA = 28;

    /**
     * @param  array<string, array{total: int, done: int}>  $days  dias materializados, por `Y-m-d`
     * @return array{days: list<array{date: string, status: string}>, complete_days: int, streak: int}
     */
    public function compute(array $days, CarbonImmutable $today): array
    {
        $lista = [];
        for ($i = self::JANELA - 1; $i >= 0; $i--) {
            $data = $today->subDays($i)->toDateString();
            $lista[] = ['date' => $data, 'status' => $i === 0 ? 'hoje' : $this->status($days[$data] ?? null)];
        }

        $sequencia = 0;
        for ($i = self::JANELA - 2; $i >= 0 && $lista[$i]['status'] === 'completo'; $i--) {
            $sequencia++;
        }

        return [
            'days' => $lista,
            'complete_days' => count(array_filter($lista, fn (array $d) => $d['status'] === 'completo')),
            'streak' => $sequencia,
        ];
    }

    /**
     * @param  array{total: int, done: int}|null  $dia
     */
    private function status(?array $dia): string
    {
        if ($dia === null || $dia['done'] === 0) {
            return 'vazio';
        }

        return $dia['done'] >= $dia['total'] ? 'completo' : 'parcial';
    }
}
