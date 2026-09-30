<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pesagem do dia (RN34).
 *
 * @property Carbon $date
 * @property float $weight_kg
 */
class WeighIn extends Model
{
    protected $fillable = ['date', 'weight_kg'];

    protected function casts(): array
    {
        return ['date' => 'date', 'weight_kg' => 'float'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
