<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Http\Resources\SettingsResource;
use App\Models\User;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(Request $request): SettingsResource
    {
        /** @var User $user */
        $user = $request->user();

        return new SettingsResource($user->load('settings'));
    }

    public function update(SettingsRequest $request): SettingsResource
    {
        /** @var User $user */
        $user = $request->user();
        $avisos = $request->validated('notifications', []);
        $mudancas = array_filter([
            'unit_system' => $request->validated('unit_system'),
            'notify_meal_reminders' => $avisos['meal_reminders'] ?? null,
            'notify_weekly_summary' => $avisos['weekly_summary'] ?? null,
            'notify_tips' => $avisos['tips'] ?? null,
        ], fn ($valor) => $valor !== null);
        $user->settings()->update($mudancas);

        return new SettingsResource($user->load('settings'));
    }
}
