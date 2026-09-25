<?php

namespace App\Models;

use App\Enums\OnboardingStep;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<string> $completed_steps
 * @property list<int> $training_days
 * @property list<string> $other_restrictions
 */
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'preferred_name', 'goal', 'sex', 'age', 'height_cm', 'start_weight_kg', 'goal_weight_kg',
        'goal_weight_source', 'activity_level', 'work_posture', 'wake_time', 'training_time',
        'sleep_time', 'training_days', 'lunch_place', 'other_restrictions', 'completed_steps',
        'onboarding_completed_at',
    ];

    protected $attributes = [
        'training_days' => '[]',
        'other_restrictions' => '[]',
        'completed_steps' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'start_weight_kg' => 'decimal:1',
            'goal_weight_kg' => 'decimal:1',
            'training_days' => 'array',
            'other_restrictions' => 'array',
            'completed_steps' => 'array',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOnboarded(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    /** Primeira etapa ainda não salva; `resumo` se todas foram salvas; `null` se concluído. */
    public function nextStep(): ?OnboardingStep
    {
        if ($this->isOnboarded()) {
            return null;
        }

        foreach (OnboardingStep::cases() as $step) {
            if (! in_array($step->value, $this->completed_steps, true)) {
                return $step;
            }
        }

        return OnboardingStep::Resumo;
    }
}
