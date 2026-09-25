<?php

namespace App\Enums;

/** Tabela de `specs/00-fundacao/api-convencoes-e-erros.md` §4. O front decide pelo código, nunca pelo texto. */
enum ErrorCode: string
{
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case OnboardingIncomplete = 'ONBOARDING_INCOMPLETE';
    case NoActivePlan = 'NO_ACTIVE_PLAN';
    case PlanAlreadyGenerating = 'PLAN_ALREADY_GENERATING';
    case DayNotEditable = 'DAY_NOT_EDITABLE';
    case NothingToUndo = 'NOTHING_TO_UNDO';
    case MealAlreadyDone = 'MEAL_ALREADY_DONE';
    case ActionAlreadyApplied = 'ACTION_ALREADY_APPLIED';
    case ActionExpired = 'ACTION_EXPIRED';
    case SubstitutionNotAllowed = 'SUBSTITUTION_NOT_ALLOWED';
    case AlreadyResponded = 'ALREADY_RESPONDED';
    case ValidationError = 'VALIDATION_ERROR';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case InvalidResetToken = 'INVALID_RESET_TOKEN';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case AiUnavailable = 'AI_UNAVAILABLE';
    case ServerError = 'SERVER_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::Unauthenticated => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::ValidationError, self::InvalidCredentials, self::InvalidResetToken => 422,
            self::TooManyRequests => 429,
            self::ServerError => 500,
            self::AiUnavailable => 503,
            default => 409,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::Unauthenticated => 'Sua sessão expirou. Entre de novo.',
            self::Forbidden => 'Não deu para concluir. Recarregue a página e tente de novo.',
            self::NotFound => 'Não encontramos o que você procurou.',
            self::OnboardingIncomplete => 'Termine de montar seu perfil para continuar.',
            self::NoActivePlan => 'Seu plano ainda não está pronto.',
            self::PlanAlreadyGenerating => 'Seu plano já está sendo montado.',
            self::DayNotEditable => 'Esse dia não pode mais ser alterado.',
            self::NothingToUndo => 'Não há nada para desfazer.',
            self::MealAlreadyDone => 'Essa refeição já foi marcada como feita.',
            self::ActionAlreadyApplied => 'Essa sugestão já foi aplicada.',
            self::ActionExpired => 'Essa sugestão era para outro dia.',
            self::SubstitutionNotAllowed => 'Essa troca não está disponível. Veja as opções de novo.',
            self::AlreadyResponded => 'Você já respondeu. Obrigado!',
            self::ValidationError => 'Confira os campos destacados.',
            self::InvalidCredentials => 'E-mail ou senha incorretos.',
            self::InvalidResetToken => 'Esse link expirou. Peça outro.',
            self::TooManyRequests => 'Muitas tentativas seguidas. Tente de novo em alguns segundos.',
            self::AiUnavailable => 'O Nutri não respondeu agora. Tente de novo.',
            self::ServerError => 'Algo deu errado do nosso lado. Tente de novo.',
        };
    }
}
