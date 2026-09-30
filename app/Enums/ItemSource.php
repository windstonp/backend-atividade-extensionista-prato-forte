<?php

namespace App\Enums;

/** De onde veio o item do dia: do plano, de uma troca manual ou do Nutri. */
enum ItemSource: string
{
    case Plan = 'plan';
    case Manual = 'manual';
    case Nutri = 'nutri';
}
