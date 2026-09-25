<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** RF04 — enviada na hora (sem fila), para não depender do worker em recuperação de acesso. */
class ResetPasswordNotification extends Notification
{
    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Prato Forte — crie uma senha nova')
            ->greeting('Oi, '.Str::before($notifiable->name, ' ').'!')
            ->line('Recebemos um pedido para trocar a senha da sua conta no Prato Forte.')
            ->action('Criar senha nova', $this->url($notifiable))
            ->line('O link vale por 60 minutos e só pode ser usado uma vez.')
            ->line('Se não foi você, é só ignorar este e-mail: sua senha continua a mesma.')
            ->salutation('Equipe Prato Forte');
    }

    public function url(User $notifiable): string
    {
        return rtrim((string) config('prato.frontend_url'), '/').'/senha/redefinir?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);
    }
}
