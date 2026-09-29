<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PantryItem extends Model
{
    /** Categorias na ordem de exibição, com o título de cada grupo. */
    public const CATEGORIES = [
        'proteinas' => 'Proteínas',
        'carboidratos' => 'Carboidratos',
        'frutas' => 'Frutas',
    ];

    protected $fillable = ['slug', 'label', 'category', 'position'];

    /** @return BelongsToMany<Food, $this> */
    public function foods(): BelongsToMany
    {
        return $this->belongsToMany(Food::class);
    }
}
