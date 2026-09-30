<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * Uma mensagem da conversa (`user` ou `assistant`; o `system` nunca é gravado — RN29).
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $role
 * @property string $content
 * @property string|null $follow_up
 * @property list<string>|null $follow_up_suggestions
 * @property array<string, mixed>|null $card
 * @property list<array<string, mixed>>|null $actions
 * @property Carbon|null $actions_resolved_at
 * @property int|null $resolved_action_index
 * @property Carbon $created_at
 */
class NutriMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'role', 'content', 'follow_up', 'follow_up_suggestions', 'card', 'actions',
        'actions_resolved_at', 'resolved_action_index', 'ai_request_id',
    ];

    protected function casts(): array
    {
        return [
            'follow_up_suggestions' => 'array',
            'card' => 'array',
            'actions' => 'array',
            'actions_resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<NutriConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(NutriConversation::class, 'conversation_id');
    }

    /** RN31 — ações só valem uma vez e no dia em que a mensagem foi criada. */
    public function actionsAvailable(): bool
    {
        return ($this->actions ?? []) !== [] && $this->actions_resolved_at === null && $this->created_at->isToday();
    }

    /**
     * @return MorphOne<Rating, $this>
     */
    public function rating(): MorphOne
    {
        return $this->morphOne(Rating::class, 'rateable');
    }
}
