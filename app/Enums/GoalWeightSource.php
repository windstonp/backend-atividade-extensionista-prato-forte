<?php

namespace App\Enums;

/** De onde veio a meta de peso (RN10). */
enum GoalWeightSource: string
{
    case User = 'user';
    case Suggested = 'suggested';
    case Auto = 'auto';
}
