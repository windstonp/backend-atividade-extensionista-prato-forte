<?php

namespace App\Http\Resources;

use App\Models\NutriMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property NutriMessage $resource */
class NutriMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $m = $this->resource;
        $base = ['id' => $m->id, 'role' => $m->role, 'content' => $m->content, 'created_at' => $m->created_at->toIso8601String()];
        if ($m->role !== 'assistant') {
            return $base;
        }
        $available = $m->actionsAvailable();

        return $base + [
            'follow_up' => $m->follow_up,
            'follow_up_suggestions' => $m->follow_up_suggestions ?? [],
            'card' => $m->card,
            'actions' => $available ? $m->actions : [],
            'actions_available' => $available,
            'rating' => null, // Plano 08
        ];
    }
}
