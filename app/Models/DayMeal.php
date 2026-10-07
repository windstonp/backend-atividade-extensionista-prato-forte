<?php

namespace App\Models;

use App\Services\Nutrition\DayTotals;
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

    /** RN46 — feita = tem registro; done_at é mantido pelo EntryService. */
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

    /**
     * O que foi comido (RN46).
     *
     * @return HasMany<MealEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(MealEntry::class)->orderBy('position');
    }

    /**
     * Consumido = soma dos retratos dos registros (RN24, RN49).
     *
     * @return array{calories: int, protein: float, carbs: float, fat: float}
     */
    public function consumed(): array
    {
        return DayTotals::sum($this->entries->map(fn (MealEntry $entry) => $entry->macros())->values()->all());
    }

    /**
     * Meta da refeição = soma da sugestão (RN48).
     *
     * @return array{calories: int, protein: float, carbs: float, fat: float}
     */
    public function target(): array
    {
        return DayTotals::sum($this->items->map(fn (DayMealItem $item) => DayTotals::item($item->food, (float) $item->grams))->values()->all());
    }
}
