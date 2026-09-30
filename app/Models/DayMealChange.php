<?php

namespace App\Models;

use App\Enums\DayChangeType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alteração de conteúdo do dia, com o antes, para o "Desfazer" (RN27).
 *
 * @property list<array{food_id: int, grams: float, replaced_food_id: int|null, source: string, position: int}> $items_before
 */
class DayMealChange extends Model
{
    public const UPDATED_AT = null;

    public const UNDO_MINUTES = 15;

    protected $fillable = ['user_id', 'date', 'day_meal_id', 'type', 'description', 'items_before', 'undone_at'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'type' => DayChangeType::class, 'items_before' => 'array', 'undone_at' => 'datetime', 'created_at' => 'immutable_datetime'];
    }

    /**
     * A última alteração do dia, ainda não desfeita, feita há no máximo 15 min (RN27).
     *
     * @param  Builder<self>  $query
     */
    public function scopeUndoableFor(Builder $query, User $user, CarbonImmutable $date): void
    {
        $query->where('user_id', $user->id)
            ->whereDate('date', $date)
            ->whereNull('undone_at')
            ->where('created_at', '>=', now()->subMinutes(self::UNDO_MINUTES))
            ->latest('id')
            ->limit(1);
    }

    /** @return BelongsTo<DayMeal, $this> */
    public function dayMeal(): BelongsTo
    {
        return $this->belongsTo(DayMeal::class);
    }
}
