<?php

namespace App\Enums;

/** RN39 — preferência de exibição; a API continua métrica. */
enum UnitSystem: string
{
    case Metric = 'metric';
    case Imperial = 'imperial';
}
