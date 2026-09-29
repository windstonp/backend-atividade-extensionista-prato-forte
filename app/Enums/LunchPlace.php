<?php

namespace App\Enums;

enum LunchPlace: string
{
    case Casa = 'casa';
    case Marmita = 'marmita';
    case Restaurante = 'restaurante';

    public function label(): string
    {
        return match ($this) {
            self::Casa => 'Em casa',
            self::Marmita => 'Marmita no trabalho',
            self::Restaurante => 'Restaurante',
        };
    }
}
