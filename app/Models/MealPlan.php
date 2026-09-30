<?php

namespace App\Models;

use App\Enums\PlanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * Plano alimentar gerado (RN18–RN20).
 *
 * @property PlanStatus $status
 * @property array<string, mixed> $inputs
 * @property Carbon|null $ready_at
 */
class MealPlan extends Model
{
    protected $fillable = [
        'status', 'is_active', 'target_kcal', 'target_protein_g', 'target_carbs_g', 'target_fat_g',
        'inputs', 'attempts', 'failure_reason', 'ready_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlanStatus::class,
            'is_active' => 'boolean',
            'inputs' => 'array',
            'ready_at' => 'datetime',
        ];
    }

    /** `active_user_id` = `user_id` só no plano ativo; o UNIQUE garante um ativo por usuário (RN20). */
    protected static function booted(): void
    {
        static::saving(function (MealPlan $plan) {
            $plan->active_user_id = $plan->is_active ? $plan->user_id : null;
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PlanMeal, $this> */
    public function meals(): HasMany
    {
        return $this->hasMany(PlanMeal::class)->orderBy('position');
    }

    /**
     * @return MorphOne<Rating, $this>
     */
    public function rating(): MorphOne
    {
        return $this->morphOne(Rating::class, 'rateable');
    }
}
