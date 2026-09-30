<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Resposta ao questionário de usabilidade (RN41). */
class UsabilityResponse extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'round', 'sus_answers', 'sus_score', 'usefulness', 'liked', 'disliked'];

    protected function casts(): array
    {
        return ['sus_answers' => 'array', 'sus_score' => 'float', 'usefulness' => 'integer'];
    }
}
