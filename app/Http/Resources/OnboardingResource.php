<?php

namespace App\Http\Resources;

use App\Models\Profile;
use App\Models\User;
use App\Services\Nutrition\GoalWeightResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Respostas do onboarding (`GET /onboarding`, `PATCH /profile/steps/{step}`).
 *
 * @mixin User — carregue as relações de RELATIONS.
 */
class OnboardingResource extends JsonResource
{
    public const RELATIONS = ['profile', 'restrictions', 'pantryItems', 'latestWeighIn'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Profile $profile */
        $profile = $this->profile;

        return [
            'completed' => $profile->isOnboarded(),
            'completed_steps' => $profile->completed_steps,
            'next_step' => $profile->nextStep()?->value,
            'answers' => [
                'goal' => $profile->goal,
                'preferred_name' => $profile->preferred_name ?? Str::before($this->name, ' '),
                'age' => $profile->age,
                'height_cm' => $profile->height_cm,
                'weight_kg' => $this->currentWeightKg(),
                'sex' => $profile->sex,
                'goal_weight_kg' => $profile->goal_weight_kg === null ? null : (float) $profile->goal_weight_kg,
                'goal_weight_source' => $profile->goal_weight_source,
                'activity_level' => $profile->activity_level,
                'work_posture' => $profile->work_posture,
                'pantry_items' => $this->pantryItems->pluck('slug')->all(),
                'restrictions' => $this->restrictions->pluck('slug')->all(),
                'other_restrictions' => $profile->other_restrictions,
                'wake_time' => self::time($profile->wake_time),
                'training_time' => self::time($profile->training_time),
                'sleep_time' => self::time($profile->sleep_time),
                'training_days' => $profile->training_days,
                'lunch_place' => $profile->lunch_place,
            ],
            'healthy_weight_range' => $profile->height_cm === null ? null : app(GoalWeightResolver::class)->healthyRange((int) $profile->height_cm),
        ];
    }

    /** Coluna `time` ("06:20:00") → "06:20". */
    public static function time(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 5);
    }
}
