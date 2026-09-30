<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET/PUT /settings` (spec 06 §5).
 *
 * @mixin User
 */
class SettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = $this->settings;

        return [
            'unit_system' => $settings->unit_system,
            'notifications' => [
                'meal_reminders' => $settings->notify_meal_reminders,
                'weekly_summary' => $settings->notify_weekly_summary,
                'tips' => $settings->notify_tips,
            ],
            'push' => [
                'vapid_public_key' => config('webpush.vapid.public_key'),
                'subscriptions' => $this->pushSubscriptions()->count(),
            ],
        ];
    }
}
