<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * RN28 — uma conversa com o Nutri.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $title
 * @property string|null $summary
 * @property int|null $summarized_message_id
 * @property Carbon|null $last_message_at
 */
class NutriConversation extends Model
{
    protected $fillable = ['title', 'summary', 'summarized_message_id', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    /** @return HasMany<NutriMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(NutriMessage::class, 'conversation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<NutriConversation> $query */
    public function scopeWithMessages(Builder $query): void
    {
        $query->whereNotNull('last_message_at');
    }
}
