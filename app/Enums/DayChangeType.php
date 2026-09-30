<?php

namespace App\Enums;

/** Alteração de conteúdo do dia que pode ser desfeita (RN27). */
enum DayChangeType: string
{
    case Swap = 'swap';
    case ApplyMeal = 'apply_meal';
}
