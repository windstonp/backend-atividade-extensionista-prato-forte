<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** RF27 — "{Refeição} às {hora}" / "{resumo}"; toque abre a refeição. */
class MealReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /** Conta apagada antes do envio: o job some em vez de virar falha. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly string $slot,
        public readonly string $name,
        public readonly string $time,
        public readonly string $summary,
    ) {
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
        return (new WebPushMessage)
            ->title("{$this->name} às {$this->time}")
            ->body($this->summary)
            ->tag("refeicao-{$this->slot}")
            ->data(['url' => "/dieta/{$this->slot}"])
            ->options(['TTL' => 900, 'urgency' => 'high']);
    }
}
