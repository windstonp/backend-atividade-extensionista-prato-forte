<?php

namespace App\Console\Commands;

use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Models\User;
use App\Notifications\MealReminder;
use App\Services\Days\DayMaterializer;
use App\Services\Notifications\AwakeWindow;
use App\Services\Notifications\EligibleUsers;
use App\Services\Notifications\NotificationLedger;
use App\Services\Nutrition\MealSummary;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/** RF27 — a cada minuto: refeições de hoje, não feitas, que começam daqui a 15 minutos. */
class SendMealReminders extends Command
{
    protected $signature = 'notifications:meal-reminders';

    protected $description = 'Envia o lembrete 15 minutos antes de cada refeição de hoje ainda não feita';

    public function handle(DayMaterializer $days, NotificationLedger $ledger): int
    {
        $agora = CarbonImmutable::now()->startOfMinute();
        $alvo = $agora->addMinutes(15)->format('H:i');
        $hoje = CarbonImmutable::today();

        EligibleUsers::for('notify_meal_reminders')->chunkById(200, function (Collection $usuarios) use ($days, $ledger, $agora, $alvo, $hoje) {
            /** @var User $user */
            foreach ($usuarios as $user) {
                $profile = $user->profile;
                if (! AwakeWindow::contains((string) $profile->wake_time, (string) $profile->sleep_time, $agora)) {
                    continue;
                }
                $refeicao = $this->mealAt($user, $days, $hoje, $alvo);
                if ($refeicao === null || $refeicao->isDone()) {
                    continue;
                }
                if ($ledger->claim($user, 'meal_reminder', "{$hoje->toDateString()}:{$refeicao->slot}")) {
                    $user->notify(new MealReminder(
                        $refeicao->slot,
                        $refeicao->name,
                        $alvo,
                        MealSummary::of($refeicao->items->map(fn (DayMealItem $item) => $item->food->name)->values()->all()),
                    ));
                }
            }
        });

        return self::SUCCESS;
    }

    /** A refeição de hoje nesse horário: a gravada, ou a prévia do plano ativo (sem gravar o dia). */
    private function mealAt(User $user, DayMaterializer $days, CarbonImmutable $hoje, string $hora): ?DayMeal
    {
        $gravadas = $user->dayMeals()->whereDate('date', $hoje)->with('items.food')->get();
        if ($gravadas->isEmpty()) {
            $plano = $user->activePlan()->with('meals.items.food')->first();
            if ($plano === null) {
                return null;
            }
            $gravadas = $days->build($user, $plano, $hoje, save: false);
        }

        return $gravadas->first(fn (DayMeal $meal) => substr((string) $meal->time, 0, 5) === $hora);
    }
}
