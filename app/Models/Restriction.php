<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Restriction extends Model
{
    protected $fillable = ['slug', 'label', 'is_allergy', 'position'];

    protected function casts(): array
    {
        return ['is_allergy' => 'boolean'];
    }

    /**
     * Alimentos que esta restrição exclui.

     *

     * @return BelongsToMany<Food, $this>
     */
    public function foods(): BelongsToMany
    {
        return $this->belongsToMany(Food::class);
    }
}
