<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityLevel;
use App\Enums\ErrorCode;
use App\Enums\Goal;
use App\Enums\Sex;
use App\Enums\WorkPosture;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\MealPlan;
use App\Models\User;
use App\Services\Nutrition\NutritionCalculator;
use App\Services\Plans\PlanService;
use App\Services\Profile\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PlanController extends Controller
{
    /** Prévia das metas no resumo do onboarding (RF08, spec 03). */
    public function previewTargets(Request $request, OnboardingService $onboarding, NutritionCalculator $calculator): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->profile;

        $missing = $onboarding->missingForPreview($profile);
        if ($missing !== []) {
            throw new DomainException(ErrorCode::ValidationError, ['missing_steps' => $missing], 'Complete as etapas anteriores para ver a prévia.');
        }

        $targets = $calculator->dailyTargets(
            Goal::from((string) $profile->goal), Sex::from((string) $profile->sex), (int) $profile->age, (int) $profile->height_cm,
            (float) $user->currentWeightKg(), ActivityLevel::from((string) $profile->activity_level), WorkPosture::from((string) $profile->work_posture),
        );

        return response()->json(['data' => [
            'kcal' => $targets->kcal, 'protein_g' => $targets->proteinG, 'carbs_g' => $targets->carbsG, 'fat_g' => $targets->fatG, 'meals' => 5,
        ]]);
    }

    public function store(Request $request, PlanService $plans): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $plan = $plans->requestGeneration($user);

        return response()->json(['data' => ['id' => $plan->id, 'status' => $plan->status->value]], 202);
    }

    public function show(MealPlan $plan): PlanResource
    {
        Gate::authorize('view', $plan);

        return new PlanResource($plan->load(PlanResource::RELATIONS));
    }

    public function active(Request $request): PlanResource
    {
        /** @var User $user */
        $user = $request->user();
        $plan = $user->activePlan()->with(PlanResource::RELATIONS)->first()
            ?? throw new DomainException(ErrorCode::NoActivePlan);

        return (new PlanResource($plan))->withItems();
    }
}
