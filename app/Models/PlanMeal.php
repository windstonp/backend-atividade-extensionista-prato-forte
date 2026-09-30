<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Refeição-modelo do plano (vale para todos os dias — RN15). */
class PlanMeal extends Model
{
    public $timestamps = false;

    protected $fillable = ['slot', 'name', 'time', 'position'];

    /** @return BelongsTo<MealPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class, 'meal_plan_id');
    }

    /** @return HasMany<PlanMealItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PlanMealItem::class)->orderBy('position');
    }
}
