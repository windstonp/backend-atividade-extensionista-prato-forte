<?php

namespace App\Enums;

/** Etapas do onboarding na ordem do fluxo (RN08). */
enum OnboardingStep: string
{
    case Objetivo = 'objetivo';
    case Dados = 'dados';
    case Atividade = 'atividade';
    case Preferencias = 'preferencias';
    case Restricoes = 'restricoes';
    case Rotina = 'rotina';
    case Resumo = 'resumo';
}
