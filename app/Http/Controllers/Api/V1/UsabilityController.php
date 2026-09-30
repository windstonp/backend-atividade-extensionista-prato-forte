<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UsabilityResponseRequest;
use App\Models\User;
use App\Services\Validation\UsabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UsabilityController extends Controller
{
    public function status(Request $request, UsabilityService $usability): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $usability->status($user)]);
    }

    public function store(UsabilityResponseRequest $request, UsabilityService $usability): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{sus_answers: list<int>, usefulness: int, liked?: string|null, disliked?: string|null} $dados */
        $dados = $request->validated();
        $usability->respond($user, $dados);

        return response()->json(['data' => ['round' => (string) config('validacao.rodada'), 'responded' => true]], 201);
    }

    public function dismiss(Request $request, UsabilityService $usability): Response
    {
        /** @var User $user */
        $user = $request->user();
        $usability->dismiss($user);

        return response()->noContent();
    }
}
