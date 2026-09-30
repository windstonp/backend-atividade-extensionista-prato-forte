<?php

namespace App\Models;

use App\Enums\ItemSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item de uma refeição do dia.
 *
 * @property ItemSource $source
 */
class DayMealItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['food_id', 'grams', 'replaced_food_id', 'source', 'position'];

    protected function casts(): array
    {
        return ['grams' => 'float', 'source' => ItemSource::class];
    }

    /** @return BelongsTo<Food, $this> */
    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    /**
     * Alimento original da refeição-modelo, quando foi trocado (RN26).

     *

     * @return BelongsTo<Food, $this>
     */
    public function replacedFood(): BelongsTo
    {
        return $this->belongsTo(Food::class, 'replaced_food_id');
    }

    /** @return BelongsTo<DayMeal, $this> */
    public function dayMeal(): BelongsTo
    {
        return $this->belongsTo(DayMeal::class);
    }
}
