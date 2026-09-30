<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\WeeklySummary;
use App\Services\Notifications\AwakeWindow;
use App\Services\Notifications\EligibleUsers;
use App\Services\Notifications\NotificationLedger;
use App\Services\Notifications\WeeklySummaryBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/** RF28 — domingo 20:00, para quem marcou ao menos uma refeição na semana. */
class SendWeeklySummary extends Command
{
    protected $signature = 'notifications:weekly-summary';

    protected $description = 'Envia o resumo da semana (domingo à noite)';

    public function handle(WeeklySummaryBuilder $builder, NotificationLedger $ledger): int
    {
        $agora = CarbonImmutable::now();
        $referencia = $agora->format('o-\WW');

        EligibleUsers::for('notify_weekly_summary')->chunkById(200, function (Collection $usuarios) use ($builder, $ledger, $agora, $referencia) {
            /** @var User $user */
            foreach ($usuarios as $user) {
                if (! AwakeWindow::contains((string) $user->profile->wake_time, (string) $user->profile->sleep_time, $agora)) {
                    continue;
                }
                $texto = $builder->body($user, $agora->startOfDay());
                if ($texto !== null && $ledger->claim($user, 'weekly_summary', $referencia)) {
                    $user->notify(new WeeklySummary($texto));
                }
            }
        });

        return self::SUCCESS;
    }
}
