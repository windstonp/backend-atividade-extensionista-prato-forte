<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin User — exige a relação `profile` carregada. */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'preferred_name' => $this->profile->preferred_name ?? Str::before($this->name, ' '),
            'onboarding_completed' => $this->profile->isOnboarded(),
            'next_step' => $this->profile->nextStep()?->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'settings' => $this->whenLoaded('settings', fn () => [
                'unit_system' => $this->settings->unit_system,
            ]),
        ];
    }
}
