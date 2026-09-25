<?php

namespace App\Models;

use Database\Factories\UserSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    /** @use HasFactory<UserSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'unit_system', 'notify_meal_reminders', 'notify_weekly_summary', 'notify_tips',
        'usability_invite_dismissed_at',
    ];

    // Iguais aos defaults da migration, para o model recém-criado já ter os valores em memória.
    protected $attributes = [
        'unit_system' => 'metric',
        'notify_meal_reminders' => true,
        'notify_weekly_summary' => true,
        'notify_tips' => false,
    ];

    protected function casts(): array
    {
        return [
            'notify_meal_reminders' => 'boolean',
            'notify_weekly_summary' => 'boolean',
            'notify_tips' => 'boolean',
            'usability_invite_dismissed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
