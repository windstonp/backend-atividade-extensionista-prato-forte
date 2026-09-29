<?php

namespace App\Enums;

enum FoodGroup: string
{
    case Proteina = 'proteina';
    case Carboidrato = 'carboidrato';
    case Leguminosa = 'leguminosa';
    case Laticinio = 'laticinio';
    case Fruta = 'fruta';
    case Vegetal = 'vegetal';
    case Gordura = 'gordura';
    case Bebida = 'bebida';
    case Outros = 'outros';
}
