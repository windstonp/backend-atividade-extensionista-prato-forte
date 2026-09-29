<?php

namespace App\Enums;

enum WorkPosture: string
{
    case Sentada = 'sentada';
    case EmPe = 'em-pe';
    case PesoPesado = 'peso-pesado';

    public function label(): string
    {
        return match ($this) {
            self::Sentada => 'Sentada',
            self::EmPe => 'Em pé',
            self::PesoPesado => 'Peso pesado',
        };
    }

    /** RN13, passo 2: ajuste do trabalho somado ao fator de atividade. */
    public function bonus(): float
    {
        return match ($this) {
            self::Sentada => 0.0,
            self::EmPe => 0.05,
            self::PesoPesado => 0.1,
        };
    }
}
