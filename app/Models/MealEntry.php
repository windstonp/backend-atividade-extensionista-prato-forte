<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O que o usuário comeu, com retrato dos números do momento (RN49). */
class MealEntry extends Model
{
    protected $fillable = [
        'user_id', 'day_meal_id', 'food_id', 'custom_food_id', 'suggestion_item_id',
        'name', 'measure', 'amount', 'calories', 'protein', 'carbs', 'fat', 'position',
    ];

    protected function casts(): array
    {
        return ['amount' => 'float', 'calories' => 'integer', 'protein' => 'float', 'carbs' => 'float', 'fat' => 'float'];
    }

    /** @return array{calories: int, protein: float, carbs: float, fat: float} */
    public function macros(): array
    {
        return ['calories' => $this->calories, 'protein' => $this->protein, 'carbs' => $this->carbs, 'fat' => $this->fat];
    }

    /** @return BelongsTo<DayMeal, $this> */
    public function dayMeal(): BelongsTo
    {
        return $this->belongsTo(DayMeal::class);
    }

    /** @return BelongsTo<Food, $this> */
    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    /** Inclui apagados: o registro antigo continua mostrando o alimento (RN49). @return BelongsTo<CustomFood, $this> */
    public function customFood(): BelongsTo
    {
        return $this->belongsTo(CustomFood::class)->withTrashed();
    }
}
