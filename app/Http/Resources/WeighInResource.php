<?php

namespace App\Http\Resources;

use App\Models\WeighIn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WeighIn */
class WeighInResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'date' => $this->date->toDateString(), 'weight_kg' => (float) $this->weight_kg];
    }
}
