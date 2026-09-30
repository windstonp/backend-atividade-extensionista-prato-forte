<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\NutriTip;
use App\Services\Notifications\AwakeWindow;
use App\Services\Notifications\EligibleUsers;
use App\Services\Notifications\NotificationLedger;
use App\Services\Notifications\TipSelector;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/** RF29 — terça e sexta 18:00; no máximo 2 por semana (CA05). */
class SendNutriTips extends Command
{
    protected $signature = 'notifications:tips';

    protected $description = 'Envia a dica do Nutri quando uma regra se aplica (máximo 2 por semana)';

    public function handle(TipSelector $tips, NotificationLedger $ledger): int
    {
        $agora = CarbonImmutable::now();
        $referencia = $agora->format('o-\WW').':'.$agora->dayOfWeekIso;

        EligibleUsers::for('notify_tips')->chunkById(200, function (Collection $usuarios) use ($tips, $ledger, $agora, $referencia) {
            /** @var User $user */
            foreach ($usuarios as $user) {
                if (! AwakeWindow::contains((string) $user->profile->wake_time, (string) $user->profile->sleep_time, $agora)
                    || $ledger->countSince($user, 'tip', $agora->startOfWeek(CarbonInterface::MONDAY)) >= 2) {
                    continue;
                }
                $dica = $tips->pick($user, $agora->startOfDay());
                if ($dica !== null && $ledger->claim($user, 'tip', $referencia, $dica['rule'])) {
                    $user->notify(new NutriTip($dica['text'], $dica['url']));
                }
            }
        });

        return self::SUCCESS;
    }
}
