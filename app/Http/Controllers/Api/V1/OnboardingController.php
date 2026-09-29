<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OnboardingResource;
use App\Models\User;
use App\Services\Profile\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function show(Request $request): OnboardingResource
    {
        /** @var User $user */
        $user = $request->user();

        return new OnboardingResource($user->load(OnboardingResource::RELATIONS));
    }

    public function complete(Request $request, OnboardingService $onboarding): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $alreadyDone = $user->profile->isOnboarded();

        $onboarding->complete($user);

        // O plano (e o GeneratePlanJob) chega no Plano 04; até lá, `plan` é null.
        return response()->json(['data' => ['plan' => null]], $alreadyDone ? 200 : 202);
    }
}
