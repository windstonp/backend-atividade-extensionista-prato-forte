<?php

namespace App\Services\Nutri;

use App\Enums\Goal;
use App\Exceptions\DomainException;
use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Models\Food;
use App\Models\MealEntry;
use App\Models\User;
use App\Services\Days\DayMaterializer;
use App\Services\Foods\FoodFilter;
use App\Services\Nutrition\DayTotals;
use App\Services\Nutrition\MealSummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** RN29 — o que o Nutri sabe agora. Um só lugar para a versão da IA e a da tela (card "O que estou olhando agora"). */
class NutriContextBuilder
{
    public function __construct(private readonly DayMaterializer $days, private readonly FoodFilter $filter) {}

    /** @return array<string, mixed> */
    public function forAi(User $user): array
    {
        $profile = $user->profile()->firstOrFail();
        $user->setRelation('profile', $profile);
        $meals = $this->todayMeals($user);
        $totals = $this->totals($meals);

        return [
            'nome' => $profile->preferred_name,
            'objetivo' => Goal::from((string) $profile->goal)->label(),
            'meta_de_peso_kg' => $profile->goal_weight_kg === null ? null : (float) $profile->goal_weight_kg,
            'metas_do_dia' => $totals['planned'],
            'restante_do_dia' => $totals['remaining'],
            'refeicoes_hoje' => $meals->map(fn (DayMeal $meal) => [
                'slot' => $meal->slot,
                'nome' => $meal->name,
                'horario' => substr((string) $meal->time, 0, 5),
                'feita' => $meal->isDone(),
                'sugestao' => $meal->items->map(fn (DayMealItem $item) => [
                    'food_id' => $item->food_id, 'nome' => $item->food->name, 'gramas' => (float) $item->grams,
                ])->values()->all(),
                'comido' => $meal->entries->map(fn (MealEntry $entry) => [
                    'nome' => $entry->name, 'quantidade' => $entry->amount, 'medida' => $entry->measure, 'kcal' => $entry->calories,
                ])->values()->all(),
            ])->values()->all(),
            'alergias' => $user->restrictions()->where('is_allergy', true)->pluck('label')->all(),
            'restricoes' => [...$user->restrictions()->where('is_allergy', false)->pluck('label')->all(), ...$profile->other_restrictions],
            'cozinha' => $user->pantryItems()->pluck('label')->all(),
            'almoco' => $profile->lunch_place,
            'treino' => substr((string) $profile->training_time, 0, 5),
            'treina_hoje' => $this->days->isTrainingDay($user, CarbonImmutable::today()),
            'alimentos_permitidos' => $this->filter->allowedFor($user)
                ->map(fn (Food $food) => ['id' => $food->id, 'nome' => $food->name, 'grupo' => $food->group])->values()->all(),
        ];
    }

    /** @return list<array{text: string, tone: string}> */
    public function lines(User $user): array
    {
        $profile = $user->profile()->firstOrFail();
        $user->setRelation('profile', $profile);
        $meals = $this->todayMeals($user);
        $next = $meals->first(fn (DayMeal $meal) => ! $meal->isDone());
        $lines = [];

        if ($next !== null) {
            $names = $next->items->map(fn (DayMealItem $item) => $item->food->name)->all();
            $lines[] = ['text' => 'Seu '.mb_strtolower($next->name).' das '.substr((string) $next->time, 0, 5).', com '.mb_strtolower(MealSummary::of($names)), 'tone' => 'gema'];
        }
        if ($meals->isNotEmpty()) {
            $remaining = $this->totals($meals)['remaining'];
            $lines[] = ['text' => number_format($remaining['calories'], 0, ',', '.').' kcal e '.round($remaining['protein']).' g de proteína ainda no plano de hoje', 'tone' => 'gema'];
        }
        foreach ($user->restrictions()->where('is_allergy', true)->pluck('label') as $label) {
            $lines[] = ['text' => 'Sua alergia a '.mb_strtolower((string) $label), 'tone' => 'alerta'];
        }
        $goal = 'Seu objetivo de '.mb_strtolower(Goal::from((string) $profile->goal)->label());
        if ($profile->goal_weight_kg !== null) {
            $goal .= ', com meta de '.rtrim(rtrim(number_format((float) $profile->goal_weight_kg, 1, ',', ''), '0'), ',').' kg';
        }
        $lines[] = ['text' => $goal, 'tone' => 'mata'];

        return $lines;
    }

    /** A próxima refeição de hoje, ou nada (sem plano ou tudo feito). */
    public function nextMeal(User $user): ?DayMeal
    {
        return $this->todayMeals($user)->first(fn (DayMeal $meal) => ! $meal->isDone());
    }

    /** @return Collection<int, DayMeal> sem plano ativo: vazio (o Nutri conversa mesmo assim) */
    private function todayMeals(User $user): Collection
    {
        try {
            return $this->days->meals($user, CarbonImmutable::today());
        } catch (DomainException) {
            return collect();
        }
    }

    /**
     * @param  Collection<int, DayMeal>  $meals
     * @return array{planned: array<string, int|float>, remaining: array<string, int|float>}
     */
    private function totals(Collection $meals): array
    {
        $planned = DayTotals::sum($meals->map(fn (DayMeal $meal) => $meal->target())->all());
        $consumed = DayTotals::sum($meals->map(fn (DayMeal $meal) => $meal->consumed())->all()); // RN24 (D13)

        return ['planned' => $planned, 'remaining' => DayTotals::remaining($planned, $consumed)];
    }
}
