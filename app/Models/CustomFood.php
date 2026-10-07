<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Alimento cadastrado pelo usuário (RN50): valores por 100 g ou 100 ml; nunca entra em FoodFilter. */
class CustomFood extends Model
{
    use SoftDeletes;

    /** "food" é incontável no pluralizador do Laravel (como em Food). */
    protected $table = 'custom_foods';

    protected $fillable = ['user_id', 'name', 'name_normalized', 'measure', 'kcal_per_100', 'protein_per_100', 'carbs_per_100', 'fat_per_100'];

    protected function casts(): array
    {
        return ['kcal_per_100' => 'float', 'protein_per_100' => 'float', 'carbs_per_100' => 'float', 'fat_per_100' => 'float'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
