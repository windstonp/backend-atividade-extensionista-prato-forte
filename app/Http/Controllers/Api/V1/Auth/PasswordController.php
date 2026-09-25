<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PasswordController extends Controller
{
    /** RF05 / RN05 — as outras sessões caem; a atual continua. */
    public function __invoke(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->update(['password' => $request->validated('password')]);
        $user->endSessions(except: $request->session()->getId());

        return response()->json(['message' => 'Senha trocada.']);
    }
}
