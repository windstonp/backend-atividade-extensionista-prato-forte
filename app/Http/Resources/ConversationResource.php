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
        // A lista já traz a contagem e a última mensagem (sem N+1); show/store calculam aqui.
        $attrs = $this->resource->getAttributes();
        $last = array_key_exists('last_content', $attrs) ? $attrs['last_content'] : $this->resource->messages()->latest('id')->value('content');
        $count = array_key_exists('messages_count', $attrs) ? (int) $attrs['messages_count'] : $this->resource->messages()->count();

        return [
            'id' => $this->resource->id,
            'title' => $this->resource->title,
            'preview' => $last === null ? null : Str::limit((string) $last, 120, '…'),
            'message_count' => $count,
            'last_message_at' => $this->resource->last_message_at?->toIso8601String(),
        ];
    }
}
