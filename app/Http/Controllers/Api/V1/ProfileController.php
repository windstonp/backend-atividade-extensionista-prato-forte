<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OnboardingStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\ProfileStepRequest;
use App\Http\Resources\OnboardingResource;
use App\Http\Resources\ProfileResource;
use App\Models\User;
use App\Services\Profile\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): ProfileResource
    {
        /** @var User $user */
        $user = $request->user();

        return new ProfileResource($user->load(ProfileResource::RELATIONS));
    }

    public function updateStep(ProfileStepRequest $request, string $step, ProfileService $profiles): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $profiles->updateStep($user, OnboardingStep::from($step), $request->validated());

        return (new OnboardingResource($user->fresh(OnboardingResource::RELATIONS)))
            ->additional(['meta' => ['plan_effect' => $result['effect']->value, 'plan_id' => $result['plan_id'], 'warnings' => $result['warnings']]])
            ->response();
    }
}
