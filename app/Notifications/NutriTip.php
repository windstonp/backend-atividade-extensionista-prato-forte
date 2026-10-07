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

    /** Conta apagada antes do envio: o job some em vez de virar falha. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly string $text, public readonly string $url)
    {
        $this->onQueue('notifications'); // fila própria: não espera atrás da geração de plano
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)->title('Dica do Nutri')->body($this->text)->tag('dica')->data(['url' => $this->url])
            ->options(['TTL' => 86400, 'urgency' => 'normal']);
    }
}
