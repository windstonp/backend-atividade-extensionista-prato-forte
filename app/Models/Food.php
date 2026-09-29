<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Alimento do catálogo (valores por 100 g).
 *
 * @property list<string> $aliases
 */
class Food extends Model
{
    /** "food" é incontável no pluralizador do Laravel; a tabela é `foods` (modelo de dados). */
    protected $table = 'foods';

    protected $fillable = [
        'slug', 'name', 'aliases', 'group', 'kcal_per_100g', 'protein_per_100g', 'carbs_per_100g', 'fat_per_100g',
        'typical_portion_g', 'unit_label', 'unit_label_plural', 'unit_grams', 'substitution_note',
        'common_dislike', 'is_staple', 'is_active', 'source',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'kcal_per_100g' => 'float',
            'protein_per_100g' => 'float',
            'carbs_per_100g' => 'float',
            'fat_per_100g' => 'float',
            'typical_portion_g' => 'float',
            'unit_grams' => 'float',
            'common_dislike' => 'boolean',
            'is_staple' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Restrições que excluem este alimento.

     *

     * @return BelongsToMany<Restriction, $this>
     */
    public function restrictions(): BelongsToMany
    {
        return $this->belongsToMany(Restriction::class);
    }

    /** @return BelongsToMany<PantryItem, $this> */
    public function pantryItems(): BelongsToMany
    {
        return $this->belongsToMany(PantryItem::class);
    }
}
