<?php

namespace App\Services\Nutri;

use App\Exceptions\DomainException;
use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Models\Food;
use App\Models\User;
use App\Services\Days\DayMaterializer;
use App\Services\Foods\FoodFilter;
use App\Services\Foods\SubstitutionFinder;
use App\Services\Nutrition\DayTotals;
use App\Services\Nutrition\PortionFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/** RN31 — ação da IA só passa validada; números do cartão sempre do catálogo. */
class NutriActionValidator
{
    public function __construct(
        private readonly DayMaterializer $days,
        private readonly FoodFilter $filter,
        private readonly SubstitutionFinder $finder,
        private readonly PortionFormatter $portions,
    ) {}

    /**
     * @param  array<string, mixed>|null  $action
     * @return array{card: array<string, mixed>, actions: list<array<string, mixed>>}|null
     */
    public function validate(User $user, ?array $action): ?array
    {
        if ($action === null) {
            return null;
        }
        $result = match ($action['type'] ?? null) {
            'substituir' => $this->swap($user, $action),
            'aplicar-refeicao' => $this->meal($user, $action),
            default => null,
        };
        if ($result === null) {
            Log::warning('nutri.action_discarded', ['type' => $action['type'] ?? null, 'slot' => $action['slot'] ?? null]);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{card: array<string, mixed>, actions: list<array<string, mixed>>}|null
     */
    private function swap(User $user, array $action): ?array
    {
        $meal = $this->todayMeal($user, $action['slot'] ?? null);
        $item = $meal?->items->first(fn (DayMealItem $i) => $i->food_id === (int) ($action['from_food_id'] ?? 0));
        $to = $this->filter->allowedFor($user)->get((int) ($action['to_food_id'] ?? 0));
        if ($meal === null || $item === null || $to === null) {
            return null;
        }
        $option = $this->finder->optionFor($item->food, (float) $item->grams, $to, $this->filter->pantryFoodIds($user));
        if ($option === null) {
            return null;
        }
        $before = DayTotals::item($item->food, (float) $item->grams);
        $name = mb_strtolower($meal->name);

        return [
            'card' => [
                'type' => 'swap',
                'slot' => $meal->slot,
                'from' => ['food_id' => $item->food_id, 'name' => $item->food->name, 'amount' => $this->portions->forFood($item->food, (float) $item->grams), 'calories' => $before['calories']],
                'to' => ['food_id' => $to->id, 'grams' => $option->grams, 'name' => $to->name, 'amount' => $this->portions->forFood($to, $option->grams), 'calories' => $option->macros['calories']],
                'carbs_before' => $before['carbs'],
                'carbs_after' => $option->macros['carbs'],
                'calorie_delta' => $option->calorieDelta,
            ],
            'actions' => [
                ['index' => 0, 'kind' => 'substituir', 'label' => "Substituir no {$name} de hoje", 'slot' => $meal->slot],
                ['index' => 1, 'kind' => 'outra-opcao', 'label' => 'Ver outras opções'],
                ['index' => 2, 'kind' => 'dispensar', 'label' => 'Agora não'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array{card: array<string, mixed>, actions: list<array<string, mixed>>}|null
     */
    private function meal(User $user, array $action): ?array
    {
        $meal = $this->todayMeal($user, $action['slot'] ?? null);
        $items = is_array($action['items'] ?? null) ? $action['items'] : [];
        if ($meal === null || count($items) < 1 || count($items) > 6) {
            return null;
        }
        $allowed = $this->filter->allowedFor($user);
        $cardItems = [];
        foreach ($items as $raw) {
            $food = is_array($raw) ? $allowed->get((int) ($raw['food_id'] ?? 0)) : null;
            $grams = is_array($raw) && is_numeric($raw['grams'] ?? null) ? (float) $raw['grams'] : 0.0;
            if (! $food instanceof Food || $grams < 5 || $grams > 600) {
                return null; // RN31: 5–600 g como a IA mandou, antes de arredondar
            }
            $grams = max(5.0, round($grams / 5) * 5);
            $macros = DayTotals::item($food, $grams);
            $cardItems[] = ['food_id' => $food->id, 'grams' => $grams, 'name' => $food->name, 'amount' => $this->portions->forFood($food, $grams), 'calories' => $macros['calories'], 'macros' => $macros];
        }
        $total = DayTotals::sum(array_column($cardItems, 'macros'));
        $original = DayTotals::sum($meal->items->map(fn (DayMealItem $i) => DayTotals::item($i->food, (float) $i->grams))->all());
        $gap = (int) round($original['protein'] - $total['protein']);
        $name = mb_strtolower($meal->name);

        return [
            'card' => [
                'type' => 'meal',
                'slot' => $meal->slot,
                'title' => $meal->name,
                'time' => substr((string) $meal->time, 0, 5),
                'calories' => $total['calories'],
                'macros' => ['protein' => $total['protein'], 'carbs' => $total['carbs'], 'fat' => $total['fat']],
                'items' => array_map(fn (array $i) => array_diff_key($i, ['macros' => true]), $cardItems),
                'warning' => $gap > 5 ? "Fica {$gap} g de proteína abaixo do {$name} original." : null,
            ],
            'actions' => [
                ['index' => 0, 'kind' => 'aplicar-refeicao', 'label' => "Aplicar no {$name} de hoje", 'slot' => $meal->slot],
                ['index' => 1, 'kind' => 'outra-opcao', 'label' => 'Gerar outra opção'],
                ['index' => 2, 'kind' => 'dispensar', 'label' => 'Agora não'],
            ],
        ];
    }

    private function todayMeal(User $user, mixed $slot): ?DayMeal
    {
        if (! is_string($slot)) {
            return null;
        }
        try {
            return $this->days->meals($user, CarbonImmutable::today())->firstWhere('slot', $slot);
        } catch (DomainException) {
            return null;
        }
    }
}
