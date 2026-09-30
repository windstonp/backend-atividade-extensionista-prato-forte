<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** RN38 — onboarding concluído, aviso ligado e ao menos uma inscrição Web Push. */
final class EligibleUsers
{
    /**
     * @param  'notify_meal_reminders'|'notify_weekly_summary'|'notify_tips'  $setting
     * @return Builder<User>
     */
    public static function for(string $setting): Builder
    {
        return User::query()
            ->whereHas('profile', fn ($q) => $q->whereNotNull('onboarding_completed_at'))
            ->whereHas('settings', fn ($q) => $q->where($setting, true))
            ->whereHas('pushSubscriptions')
            ->with('profile');
    }
}
