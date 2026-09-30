<?php

namespace App\Services\Validation;

/** SUS: ((Σ ímpares − 5) + (25 − Σ pares)) × 2,5 — 0 a 100 (RN41). Puro. */
final class SusScore
{
    /**
     * @param  list<int>  $answers  10 respostas de 1 a 5, na ordem das afirmações
     */
    public static function of(array $answers): float
    {
        $impares = $answers[0] + $answers[2] + $answers[4] + $answers[6] + $answers[8];
        $pares = $answers[1] + $answers[3] + $answers[5] + $answers[7] + $answers[9];

        return (($impares - 5) + (25 - $pares)) * 2.5;
    }
}
