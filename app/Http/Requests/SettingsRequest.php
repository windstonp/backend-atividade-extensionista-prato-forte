<?php

namespace App\Http\Requests;

use App\Enums\UnitSystem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** RF26/RF30 — campos parciais. */
class SettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unit_system' => ['sometimes', Rule::enum(UnitSystem::class)],
            'notifications' => ['sometimes', 'array'],
            'notifications.meal_reminders' => ['sometimes', 'boolean'],
            'notifications.weekly_summary' => ['sometimes', 'boolean'],
            'notifications.tips' => ['sometimes', 'boolean'],
        ];
    }
}
