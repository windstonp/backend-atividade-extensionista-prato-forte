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
     * @param  array<string, array{total: int, done: int}>  $days  dias materializados, por `Y-m-d` (pode ir além dos 28, para a sequência)
     * @return array{days: list<array{date: string, status: string}>, complete_days: int, streak: int}
     */
    public function compute(array $days, CarbonImmutable $today): array
    {
        $lista = [];
        for ($i = self::JANELA - 1; $i >= 0; $i--) {
            $data = $today->subDays($i)->toDateString();
            $lista[] = ['date' => $data, 'status' => $i === 0 ? 'hoje' : $this->status($days[$data] ?? null)];
        }

        // A sequência não para na grade: anda para trás a partir de ontem enquanto o dia estiver completo.
        $sequencia = 0;
        for ($dia = $today->subDay(); $this->status($days[$dia->toDateString()] ?? null) === 'completo'; $dia = $dia->subDay()) {
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
