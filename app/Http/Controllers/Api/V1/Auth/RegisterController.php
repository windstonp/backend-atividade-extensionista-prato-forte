<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\Account\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, AccountService $accounts): JsonResponse
    {
        $user = $accounts->register($request->validated());

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        return (new UserResource($user))->response()->setStatusCode(201);
    }
}
