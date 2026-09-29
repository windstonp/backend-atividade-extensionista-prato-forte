<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** RN07 — rotas do app só depois do onboarding. */
class EnsureOnboardingCompleted
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->profile;

        if (! $profile->isOnboarded()) {
            throw new DomainException(ErrorCode::OnboardingIncomplete, ['next_step' => $profile->nextStep()?->value]);
        }

        return $next($request);
    }
}
