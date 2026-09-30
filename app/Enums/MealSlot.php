<?php

namespace App\Enums;

/** As 5 refeições do plano (RN15). A ordem dos cases é a ordem padrão do dia. */
enum MealSlot: string
{
    case Cafe = 'cafe';
    case Lanche = 'lanche';
    case Almoco = 'almoco';
    case PreTreino = 'pre-treino';
    case Jantar = 'jantar';

    public function label(): string
    {
        return match ($this) {
            self::Cafe => 'Café da manhã',
            self::Lanche => 'Lanche da manhã',
            self::Almoco => 'Almoço',
            self::PreTreino => 'Pré-treino',
            self::Jantar => 'Jantar',
        };
    }
}
