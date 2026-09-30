<?php

namespace App\Http\Resources;

use App\Models\NutriConversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @property NutriConversation $resource */
class ConversationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $last = $this->resource->messages()->latest('id')->value('content');

        return [
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'preview' => $last === null ? null : Str::limit((string) $last, 120, '…'),
            'message_count' => $this->resource->messages()->count(),
            'last_message_at' => $this->resource->last_message_at?->toIso8601String(),
        ];
    }
}
