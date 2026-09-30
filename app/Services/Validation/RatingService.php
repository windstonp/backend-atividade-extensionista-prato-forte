<?php

namespace App\Services\Validation;

use App\Models\MealPlan;
use App\Models\NutriMessage;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** RN40/RN43 — só respostas do Nutri e planos da própria pessoa; o resto é 404. */
final class RatingService
{
    public function rate(User $user, string $type, int $id, string $value, ?string $comment): Rating
    {
        $this->assertOwned($user, $type, $id);

        return Rating::updateOrCreate(
            ['user_id' => $user->id, 'rateable_type' => $type, 'rateable_id' => $id],
            ['value' => $value, 'comment' => $value === 'down' ? $comment : null],
        );
    }

    public function remove(User $user, string $type, int $id): void
    {
        Rating::where(['user_id' => $user->id, 'rateable_type' => $type, 'rateable_id' => $id])->delete();
    }

    private function assertOwned(User $user, string $type, int $id): void
    {
        $existe = match ($type) {
            'nutri_message' => NutriMessage::whereKey($id)->where('role', 'assistant')
                ->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id))->exists(),
            'meal_plan' => MealPlan::whereKey($id)->where('user_id', $user->id)->exists(),
            default => false,
        };
        if (! $existe) {
            throw new ModelNotFoundException;
        }
    }
}
