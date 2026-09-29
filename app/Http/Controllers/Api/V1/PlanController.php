<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityLevel;
use App\Enums\ErrorCode;
use App\Enums\Goal;
use App\Enums\Sex;
use App\Enums\WorkPosture;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Nutrition\NutritionCalculator;
use App\Services\Profile\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
