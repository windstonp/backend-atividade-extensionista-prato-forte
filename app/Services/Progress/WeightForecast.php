<?php

namespace App\Services\Progress;

use Carbon\CarbonImmutable;

/**
 * RN35 — quando a pessoa chega na meta: regressão linear (peso × dia) sobre as pesagens do período.
 * Precisa de meta, ≥ 3 pesagens cobrindo ≥ 14 dias e a reta indo na direção da meta; horizonte de 52 semanas. Puro.
 */
final class WeightForecast
{
    private const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    /**
     * @param  list<array{date: string, weight_kg: float}>  $points  em ordem crescente de data
     * @return array{date: string, label: string}|null
     */
    public function estimate(array $points, ?float $goalKg, CarbonImmutable $today): ?array
    {
        if ($goalKg === null || count($points) < 3) {
            return null;
        }

        $inicio = CarbonImmutable::parse($points[0]['date']);
        $xs = array_map(fn (array $p) => $inicio->diffInDays(CarbonImmutable::parse($p['date'])), $points);
        $ys = array_map(fn (array $p) => (float) $p['weight_kg'], $points);
        if (end($xs) < 14) {
            return null;
        }

        $n = count($xs);
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $sxy = 0.0;
        $sxx = 0.0;
        foreach ($xs as $i => $x) {
            $sxy += ($x - $mx) * ($ys[$i] - $my);
            $sxx += ($x - $mx) ** 2;
        }
        $inclinacao = $sxy / $sxx;
        $ultimo = end($ys);

        $faltam = $goalKg - $ultimo;
        if ($faltam == 0.0 || $inclinacao == 0.0 || ($faltam > 0) !== ($inclinacao > 0)) {
            return null; // já chegou, ou a reta se afasta da meta
        }

        $intercepto = $my - $inclinacao * $mx;
        $data = $inicio->addDays((int) round(($goalKg - $intercepto) / $inclinacao));
        if ($data->lessThanOrEqualTo($today) || $data->greaterThan($today->addWeeks(52))) {
            return null;
        }

        return ['date' => $data->toDateString(), 'label' => self::label($data, $today)];
    }

    /** "início/meados/fim de {mês}", com o ano quando o mês se repetiria dentro do horizonte. */
    public static function label(CarbonImmutable $date, CarbonImmutable $today): string
    {
        $parte = $date->day <= 10 ? 'início' : ($date->day <= 20 ? 'meados' : 'fim');
        $ano = $date->year > $today->year && $date->month >= $today->month ? " de {$date->year}" : '';

        return "{$parte} de ".self::MESES[$date->month - 1].$ano;
    }
}
