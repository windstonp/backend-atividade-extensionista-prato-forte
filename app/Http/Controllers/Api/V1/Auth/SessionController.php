<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use App\Http\Controllers\Api\V1\Auth\Concerns\EndsCurrentSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    use EndsCurrentSession;

    /** RF02 — "lembrar de mim" sempre ligado: o público usa o celular pessoal. */
    public function store(LoginRequest $request): UserResource
    {
        if (! Auth::guard('web')->attempt($request->credentials(), remember: true)) {
            throw new DomainException(ErrorCode::InvalidCredentials);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();

        return new UserResource($user->load('profile'));
    }

    /** RF03 */
    public function destroy(Request $request): Response
    {
        $this->endCurrentSession($request);

        return response()->noContent();
    }
}
