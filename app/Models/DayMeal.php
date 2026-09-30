<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Refeição de uma data concreta (RN22).
 *
 * @property CarbonImmutable $date
 */
class DayMeal extends Model
{
    protected $fillable = ['user_id', 'date', 'meal_plan_id', 'slot', 'name', 'time', 'note', 'position', 'done_at'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'done_at' => 'datetime'];
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    /** @return HasMany<DayMealItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DayMealItem::class)->orderBy('position');
    }

    /** @return BelongsTo<MealPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class, 'meal_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
