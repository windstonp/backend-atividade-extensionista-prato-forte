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

    public function __construct(
        public readonly string $slot,
        public readonly string $name,
        public readonly string $time,
        public readonly string $summary,
    ) {}

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
            ->data(['url' => "/dieta/{$this->slot}"]);
    }
}
