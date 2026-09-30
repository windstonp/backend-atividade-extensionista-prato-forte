<?php

namespace App\Enums;

/** Efeito de uma mudança do perfil sobre o plano (RN21). */
enum PlanEffect: string
{
    case None = 'none';
    case RegenerationSuggested = 'regeneration_suggested';
    case RegenerationStarted = 'regeneration_started';
    case TimesUpdated = 'times_updated';
}
