<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Avaliação 👍/👎 de uma resposta do Nutri ou de um plano (RN40). */
class Rating extends Model
{
    protected $fillable = ['user_id', 'rateable_type', 'rateable_id', 'value', 'comment'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{value: string, comment: string|null}
     */
    public function toPublic(): array
    {
        return ['value' => $this->value, 'comment' => $this->comment];
    }
}
