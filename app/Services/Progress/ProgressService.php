<?php

namespace App\Services\Progress;

use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Models\User;
use App\Models\WeighIn;
use App\Services\Days\DayMaterializer;
use App\Services\Nutrition\DayTotals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** RF24/RF25 — tudo que a tela de Evolução mostra, num período (RN35–RN37). */
final class ProgressService
{
    /** Dias de cada período (incluindo hoje); `all` não tem limite. */
    public const PERIODOS = ['6w' => 42, '3m' => 91, 'all' => null];

    private const INSIGHT = 'Você fica um pouco abaixo da meta de proteína nos dias sem treino.';

    public function __construct(
        private readonly WeightForecast $forecast,
        private readonly AdherenceCalculator $adherence,
        private readonly DayMaterializer $days,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, string $period, CarbonImmutable $today): array
    {
        $dias = self::PERIODOS[$period];
        $desde = $dias === null ? null : $today->subDays($dias - 1);

        return [
            'period' => $period,
            'weight' => $this->weight($user, $desde, $today),
            'adherence' => $this->adherence($user, $today),
            'averages' => $this->averages($user, $desde, $today),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function weight(User $user, ?CarbonImmutable $desde, CarbonImmutable $today): array
    {
        $pontos = $user->weighIns()
            ->when($desde, fn ($q) => $q->whereDate('date', '>=', $desde))
            ->orderBy('date')
            ->get()
            ->map(fn (WeighIn $w) => ['date' => $w->date->toDateString(), 'weight_kg' => (float) $w->weight_kg])
            ->all();
        $profile = $user->profile;
        $meta = $profile->goal_weight_kg === null ? null : (float) $profile->goal_weight_kg;
        $primeiro = $pontos[0] ?? null;
        $ultimo = $pontos === [] ? null : $pontos[count($pontos) - 1];

        return [
            'start_kg' => $primeiro['weight_kg'] ?? null,
            'current_kg' => $ultimo['weight_kg'] ?? null,
            'goal_kg' => $meta,
            'goal_source' => $meta === null ? null : $profile->goal_weight_source,
            'change_kg' => $ultimo === null ? null : round($ultimo['weight_kg'] - $primeiro['weight_kg'], 1),
            'span_weeks' => $ultimo === null ? null : (int) round(CarbonImmutable::parse($primeiro['date'])->diffInDays(CarbonImmutable::parse($ultimo['date'])) / 7),
            'points' => $pontos,
            'forecast' => $this->forecast->estimate($pontos, $meta, $today),
        ];
    }

    /**
     * @return array{days: list<array{date: string, status: string}>, complete_days: int, streak: int}
     */
    private function adherence(User $user, CarbonImmutable $today): array
    {
        $linhas = DB::table('day_meals')
            ->where('user_id', $user->id)
            ->whereBetween('date', [$today->subDays(AdherenceCalculator::JANELA - 1)->toDateString(), $today->toDateString()])
            ->groupBy('date')
            ->selectRaw('date, count(*) as total, sum(done_at is not null) as done')
            ->get();

        $dias = [];
        foreach ($linhas as $linha) {
            $dias[CarbonImmutable::parse($linha->date)->toDateString()] = ['total' => (int) $linha->total, 'done' => (int) $linha->done];
        }

        return $this->adherence->compute($dias, $today);
    }

    /**
     * RN37 — médias diárias do consumido nos dias com ≥ 1 refeição feita, contra as metas do plano ativo.
     *
     * @return array<string, mixed>
     */
    private function averages(User $user, ?CarbonImmutable $desde, CarbonImmutable $today): array
    {
        $plano = $user->activePlan()->first();
        $porDia = $user->dayMeals()
            ->whereNotNull('done_at')
            ->when($desde, fn ($q) => $q->whereDate('date', '>=', $desde))
            ->whereDate('date', '<=', $today)
            ->with('items.food')
            ->get()
            ->groupBy(fn (DayMeal $meal) => $meal->date->toDateString())
            ->map(fn ($meals) => DayTotals::sum($meals->flatMap(fn (DayMeal $meal) => $meal->items->map(
                fn (DayMealItem $item) => DayTotals::item($item->food, (float) $item->grams),
            ))->values()->all()));

        $metaProteina = $plano?->target_protein_g;
        $contados = $porDia->count();

        return [
            'days_counted' => $contados,
            'protein' => ['avg_g' => $contados === 0 ? null : (int) round($porDia->avg('protein')), 'target_g' => $metaProteina],
            'calories' => ['avg_kcal' => $contados === 0 ? null : (int) round($porDia->avg('calories')), 'target_kcal' => $plano?->target_kcal],
            'insight' => $this->insight($user, $porDia->map(fn (array $t) => $t['protein'])->all(), $metaProteina),
        ];
    }

    /**
     * Proteína nos dias sem treino < 90% da meta e ao menos 10 pontos percentuais abaixo dos dias de treino.
     *
     * @param  array<string, float>  $proteinaPorDia
     */
    private function insight(User $user, array $proteinaPorDia, ?int $meta): ?string
    {
        if (! $meta) {
            return null;
        }
        $treino = [];
        $descanso = [];
        foreach ($proteinaPorDia as $data => $proteina) {
            if ($this->days->isTrainingDay($user, CarbonImmutable::parse($data))) {
                $treino[] = $proteina;
            } else {
                $descanso[] = $proteina;
            }
        }
        if ($treino === [] || $descanso === []) {
            return null;
        }
        $pctTreino = array_sum($treino) / count($treino) / $meta * 100;
        $pctDescanso = array_sum($descanso) / count($descanso) / $meta * 100;

        return $pctDescanso < 90 && $pctTreino - $pctDescanso >= 10 ? self::INSIGHT : null;
    }
}
