<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** RF28 — "Sua semana no Prato Forte"; toque abre a Evolução. */
class WeeklySummary extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $body) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)->title('Sua semana no Prato Forte')->body($this->body)->tag('resumo-semana')->data(['url' => '/evolucao'])
            ->options(['TTL' => 86400, 'urgency' => 'normal']);
    }
}
