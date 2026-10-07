<?php

namespace App\Services\Notifications;

use App\Models\DayMeal;
use App\Models\User;
use App\Services\Nutrition\DayTotals;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** RF28 — o corpo do resumo de domingo (semana ISO de segunda a domingo). */
final class WeeklySummaryBuilder
{
    public function body(User $user, CarbonImmutable $sunday): ?string
    {
        $segunda = $sunday->startOfWeek(CarbonInterface::MONDAY); // explícito: o locale pt_BR pode começar no domingo
        $refeicoes = $user->dayMeals()
            ->whereBetween('date', [$segunda->toDateString(), $sunday->toDateString()])
            ->with('entries')
            ->get()
            ->groupBy(fn (DayMeal $meal) => $meal->date->toDateString());

        $comFeita = $refeicoes->filter(fn ($dia) => $dia->contains(fn (DayMeal $meal) => $meal->isDone()));
        if ($comFeita->isEmpty()) {
            return null;
        }

        $completos = $refeicoes->filter(fn ($dia) => $dia->every(fn (DayMeal $meal) => $meal->isDone()))->count();
        $proteina = (int) round($comFeita->avg(fn ($dia) => DayTotals::sum($dia->filter(fn (DayMeal $meal) => $meal->isDone())
            ->map(fn (DayMeal $meal) => $meal->consumed())->values()->all())['protein'])); // RN24 (D13): o que foi registrado

        $texto = "{$completos} de 7 dias completos · média de {$proteina} g de proteína";
        $variacao = $this->weightChange($user, $segunda, $sunday);

        return $variacao === null ? $texto : "{$texto} · peso {$variacao} kg";
    }

    /** Última pesagem da semana − última antes dela, com sinal e uma casa ("+0,4"). */
    private function weightChange(User $user, CarbonImmutable $segunda, CarbonImmutable $sunday): ?string
    {
        $naSemana = $user->weighIns()->whereBetween('date', [$segunda->toDateString(), $sunday->toDateString()])->orderByDesc('date')->value('weight_kg');
        $antes = $user->weighIns()->where('date', '<', $segunda->toDateString())->orderByDesc('date')->value('weight_kg');
        if ($naSemana === null || $antes === null) {
            return null;
        }
        $diferenca = round((float) $naSemana - (float) $antes, 1);

        return ($diferenca > 0 ? '+' : ($diferenca < 0 ? '−' : '')).number_format(abs($diferenca), 1, ',', '');
    }
}
