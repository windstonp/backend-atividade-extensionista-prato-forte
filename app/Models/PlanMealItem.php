<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanMealItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['food_id', 'grams', 'position'];

    protected function casts(): array
    {
        return ['grams' => 'float'];
    }

    /** @return BelongsTo<Food, $this> */
    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }
}
