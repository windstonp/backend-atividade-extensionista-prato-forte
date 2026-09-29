<?php

namespace App\Enums;

enum Goal: string
{
    case GanharMassa = 'ganhar-massa';
    case PerderGordura = 'perder-gordura';
    case ManterPeso = 'manter-peso';
    case MaisDisposicao = 'mais-disposicao';

    public function label(): string
    {
        return match ($this) {
            self::GanharMassa => 'Ganhar massa magra',
            self::PerderGordura => 'Perder gordura',
            self::ManterPeso => 'Manter o peso',
            self::MaisDisposicao => 'Ter mais disposição',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GanharMassa => 'Comer um pouco acima do gasto, com proteína alta todo dia.',
            self::PerderGordura => 'Déficit leve, mantendo a força nos treinos.',
            self::ManterPeso => 'Organizar os horários e equilibrar o que você já come.',
            self::MaisDisposicao => 'Energia para o treino sem chegar arrastada no fim do dia.',
        };
    }

    /** RN10: só ganhar e perder pedem meta de peso. */
    public function asksGoalWeight(): bool
    {
        return $this === self::GanharMassa || $this === self::PerderGordura;
    }
}
