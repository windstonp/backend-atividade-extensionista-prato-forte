<?php

namespace App\Http\Resources;

use App\Models\Food;
use App\Models\PantryItem;
use App\Models\Profile;
use App\Models\Restriction;
use App\Models\User;
use App\Services\Nutrition\GoalWeightResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Perfil de quem concluiu o onboarding (`GET /profile`, `PUT /profile/preferences`).
 *
 * @mixin User — carregue as relações de RELATIONS.
 */
class ProfileResource extends JsonResource
{
    public const RELATIONS = ['profile', 'restrictions', 'pantryItems', 'dislikedFoods', 'latestWeighIn'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Profile $profile */
        $profile = $this->profile;

        return [
            'name' => $this->name,
            'preferred_name' => $profile->preferred_name ?? Str::before($this->name, ' '),
            'email' => $this->email,
            'created_at' => $this->created_at?->toIso8601String(),
            'goal' => $profile->goal,
            'sex' => $profile->sex,
            'age' => $profile->age,
            'height_cm' => $profile->height_cm,
            'start_weight_kg' => (float) $profile->start_weight_kg,
            'current_weight_kg' => $this->currentWeightKg(),
            'goal_weight_kg' => $profile->goal_weight_kg === null ? null : (float) $profile->goal_weight_kg,
            'goal_weight_source' => $profile->goal_weight_source,
            'healthy_weight_range' => app(GoalWeightResolver::class)->healthyRange((int) $profile->height_cm),
            'activity_level' => $profile->activity_level,
            'work_posture' => $profile->work_posture,
            'wake_time' => OnboardingResource::time($profile->wake_time),
            'training_time' => OnboardingResource::time($profile->training_time),
            'sleep_time' => OnboardingResource::time($profile->sleep_time),
            'training_days' => $profile->training_days,
            'lunch_place' => $profile->lunch_place,
            'pantry_items' => $this->pantryItems->map(fn (PantryItem $item) => ['slug' => $item->slug, 'label' => $item->label])->all(),
            'restrictions' => $this->restrictions->map(fn (Restriction $r) => ['slug' => $r->slug, 'label' => $r->label, 'is_allergy' => $r->is_allergy])->all(),
            'other_restrictions' => $profile->other_restrictions,
            'disliked_foods' => $this->dislikedFoods->map(fn (Food $food) => ['id' => $food->id, 'name' => $food->name])->all(),
            'gym' => config('prato.parceiro.gym'),
            'city' => config('prato.parceiro.city'),
        ];
    }
}
