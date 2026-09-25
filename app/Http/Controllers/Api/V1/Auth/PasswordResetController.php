<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /** RN04 — a resposta é a mesma exista ou não a conta (o status do broker é ignorado de propósito). */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(['email' => $request->validated('email')]);

        return response()->json(['message' => 'Se houver uma conta com esse e-mail, enviamos um link.']);
    }

    /** RF04 — troca a senha, derruba todas as sessões e não autentica. */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->setRememberToken(Str::random(60));
                $user->save();
                $user->endSessions();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new DomainException(ErrorCode::InvalidResetToken);
        }

        return response()->json(['message' => 'Senha redefinida.']);
    }
}
