<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** RF29 — dica curta; toque abre a tela relacionada. */
class NutriTip extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $text, public readonly string $url) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)->title('Dica do Nutri')->body($this->text)->tag('dica')->data(['url' => $this->url]);
    }
}
