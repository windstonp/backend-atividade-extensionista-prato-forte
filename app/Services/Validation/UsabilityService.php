<?php

namespace App\Services\Validation;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use App\Models\UsabilityResponse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/** RN41 — convite, resposta por rodada e "Agora não". */
final class UsabilityService
{
    /**
     * @return array{round: string, responded: bool, invite: bool}
     */
    public function status(User $user): array
    {
        $rodada = (string) config('validacao.rodada');
        $respondeu = UsabilityResponse::where(['user_id' => $user->id, 'round' => $rodada])->exists();

        return ['round' => $rodada, 'responded' => $respondeu, 'invite' => ! $respondeu && ! $this->dismissed($user) && $this->eligible($user)];
    }

    /**
     * @param  array{sus_answers: list<int>, usefulness: int, liked?: string|null, disliked?: string|null}  $data
     */
    public function respond(User $user, array $data): void
    {
        $rodada = (string) config('validacao.rodada');
        if (UsabilityResponse::where(['user_id' => $user->id, 'round' => $rodada])->exists()) {
            throw new DomainException(ErrorCode::AlreadyResponded);
        }
        try {
            UsabilityResponse::create([
                'user_id' => $user->id,
                'round' => $rodada,
                'sus_answers' => array_map('intval', $data['sus_answers']),
                'sus_score' => SusScore::of(array_map('intval', $data['sus_answers'])),
                'usefulness' => $data['usefulness'],
                'liked' => $data['liked'] ?? null,
                'disliked' => $data['disliked'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DomainException(ErrorCode::AlreadyResponded); // o outro envio chegou primeiro
        }
    }

    public function dismiss(User $user): void
    {
        $user->settings()->update(['usability_invite_dismissed_at' => now()]);
    }

    private function dismissed(User $user): bool
    {
        $quando = CarbonImmutable::make($user->settings?->usability_invite_dismissed_at);

        return $quando !== null && $quando->greaterThanOrEqualTo(CarbonImmutable::parse((string) config('validacao.inicio')));
    }

    /** Onboarding concluído há ≥ 7 dias OU ≥ 10 refeições marcadas. */
    private function eligible(User $user): bool
    {
        $concluido = CarbonImmutable::make($user->profile?->onboarding_completed_at);
        if ($concluido !== null && $concluido->lessThanOrEqualTo(now()->subDays(7))) {
            return true;
        }

        return $user->dayMeals()->whereNotNull('done_at')->count() >= 10;
    }
}
