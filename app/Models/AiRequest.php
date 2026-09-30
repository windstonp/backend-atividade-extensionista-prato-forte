<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma chamada à IA, sem conteúdo (RN44): custo e relatório de validação. */
class AiRequest extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'purpose', 'model', 'prompt_tokens', 'completion_tokens', 'duration_ms', 'status', 'error_code'];
}
