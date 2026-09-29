<?php

namespace App\Enums;

enum ActivityLevel: string
{
    case Parado = 'parado';
    case Leve = 'leve';
    case Moderado = 'moderado';
    case Intenso = 'intenso';

    public function label(): string
    {
        return match ($this) {
            self::Parado => 'Quase não treino',
            self::Leve => '1 ou 2 vezes na semana',
            self::Moderado => '3 ou 4 vezes na semana',
            self::Intenso => '5 ou 6 vezes na semana',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Parado => 'Menos de um treino por semana.',
            self::Leve => 'Musculação leve ou caminhada.',
            self::Moderado => 'O ritmo da maior parte do pessoal da Zfit.',
            self::Intenso => 'Treino puxado quase todo dia.',
        };
    }

    /** RN13, passo 2. */
    public function factor(): float
    {
        return match ($this) {
            self::Parado => 1.2,
            self::Leve => 1.375,
            self::Moderado => 1.55,
            self::Intenso => 1.725,
        };
    }
}
