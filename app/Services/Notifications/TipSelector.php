<?php

namespace App\Services\Notifications;

use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Models\User;
use App\Services\Nutrition\DayTotals;
use App\Services\Progress\AdherenceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RF29 — a primeira regra de dica que se aplica e não saiu nos últimos 14 dias. Sem IA.
 * "Últimos 7 dias" = de hoje − 7 a ontem.
 */
final class TipSelector
{
    public function __construct(
        private readonly NotificationLedger $ledger,
        private readonly AdherenceCalculator $adherence,
    ) {}

    /**
     * @return array{rule: string, text: string, url: string}|null
     */
    public function pick(User $user, CarbonImmutable $today): ?array
    {
        $desde = $today->subDays(7);
        $refeicoes = $user->dayMeals()
            ->whereBetween('date', [$desde->toDateString(), $today->subDay()->toDateString()])
            ->with('items.food')
            ->get();

        $candidatas = [
            fn () => $this->protein($user, $refeicoes),
            fn () => $this->skippedMeal($refeicoes),
            fn () => $this->noWeighIn($user, $today),
            fn () => $this->streak($user, $today),
        ];
        foreach ($candidatas as $candidata) {
            $dica = $candidata();
            if ($dica !== null && ! $this->ledger->ruleSentSince($user, $dica['rule'], $today->subDays(14))) {
                return $dica;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, DayMeal>  $refeicoes
     * @return array{rule: string, text: string, url: string}|null
     */
    private function protein(User $user, $refeicoes): ?array
    {
        $meta = $user->activePlan()->value('target_protein_g');
        $porDia = $refeicoes->filter(fn (DayMeal $meal) => $meal->isDone())
            ->groupBy(fn (DayMeal $meal) => $meal->date->toDateString())
            ->map(fn ($meals) => DayTotals::sum($meals->flatMap(fn (DayMeal $meal) => $meal->items->map(
                fn (DayMealItem $item) => DayTotals::item($item->food, (float) $item->grams),
            ))->values()->all())['protein']);
        if (! $meta || $porDia->isEmpty() || $porDia->avg() >= 0.9 * $meta) {
            return null;
        }

        return ['rule' => 'proteina', 'text' => 'Faltou proteína nesta semana. Um ovo a mais no café já ajuda.', 'url' => '/nutri'];
    }

    /**
     * @param  Collection<int, DayMeal>  $refeicoes
     * @return array{rule: string, text: string, url: string}|null
     */
    private function skippedMeal($refeicoes): ?array
    {
        $esquecida = $refeicoes->reject(fn (DayMeal $meal) => $meal->isDone())
            ->groupBy('slot')
            ->map(fn ($meals) => ['name' => $meals->first()->name, 'n' => $meals->count()])
            ->filter(fn (array $slot) => $slot['n'] >= 3)
            ->sortByDesc('n')
            ->first();
        if ($esquecida === null) {
            return null;
        }
        $nome = mb_strtolower($esquecida['name']);

        return ['rule' => 'refeicao-esquecida', 'text' => "O {$nome} ficou de fora {$esquecida['n']} vezes esta semana. Quer pedir ao Nutri uma opção mais prática?", 'url' => '/nutri'];
    }

    /**
     * @return array{rule: string, text: string, url: string}|null
     */
    private function noWeighIn(User $user, CarbonImmutable $today): ?array
    {
        if ($user->profile->goal_weight_kg === null) {
            return null;
        }
        $ultima = $user->weighIns()->max('date');
        if ($ultima !== null && CarbonImmutable::parse($ultima)->greaterThan($today->subDays(7))) {
            return null;
        }

        return ['rule' => 'sem-pesagem', 'text' => 'Faz uma semana sem pesagem. Amanhã cedo, antes do café?', 'url' => '/evolucao/peso'];
    }

    /**
     * @return array{rule: string, text: string, url: string}|null
     */
    private function streak(User $user, CarbonImmutable $today): ?array
    {
        $dias = [];
        foreach (DB::table('day_meals')->where('user_id', $user->id)
            ->where('date', '>=', $today->subDays(400)->toDateString())->where('date', '<=', $today->toDateString())
            ->groupBy('date')->selectRaw('date, count(*) as total, sum(done_at is not null) as done')->get() as $linha) {
            $dias[CarbonImmutable::parse($linha->date)->toDateString()] = ['total' => (int) $linha->total, 'done' => (int) $linha->done];
        }
        $n = $this->adherence->compute($dias, $today)['streak'];

        return $n >= 5 ? ['rule' => 'sequencia', 'text' => "{$n} dias seguidos com tudo feito. Segue assim!", 'url' => '/evolucao'] : null;
    }
}
