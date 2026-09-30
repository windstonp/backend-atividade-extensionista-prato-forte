<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Nutri\NutriContextBuilder;
use App\Services\Nutri\SuggestionBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NutriController extends Controller
{
    public function context(Request $request, NutriContextBuilder $context): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => ['lines' => $context->lines($user)]]);
    }

    public function suggestions(Request $request, SuggestionBuilder $suggestions): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $suggestions->questions($user)]);
    }
}
