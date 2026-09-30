<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Inscrição Web Push deste navegador (spec 06 §5). */
class PushSubscriptionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->isMethod('DELETE')) {
            return ['endpoint' => ['required', 'string', 'max:500']];
        }

        return [
            'endpoint' => ['required', 'url', 'max:500', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['sometimes', 'in:aesgcm,aes128gcm'],
        ];
    }
}
