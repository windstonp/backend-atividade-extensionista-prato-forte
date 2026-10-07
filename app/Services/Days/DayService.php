<?php

namespace App\Services\Days;

use App\Enums\DayChangeType;
use App\Enums\ErrorCode;
use App\Enums\ItemSource;
use App\Exceptions\DomainException;
use App\Models\DayMeal;
use App\Models\DayMealChange;
use App\Models\DayMealItem;
use App\Models\MealEntry;
use App\Models\User;
use App\Services\Foods\FoodFilter;
use App\Services\Foods\SubstitutionFinder;
use App\Services\Foods\SubstitutionOption;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Única porta de escrita do dia (RN23, RN26, RN27). */
class DayService
{
    public function __construct(
        private readonly DayMaterializer $days,
        private readonly FoodFilter $filter,
        private readonly SubstitutionFinder $finder,
    ) {}

    /** RN23 — só o dia de hoje (São Paulo) muda. */
    public function assertEditable(CarbonImmutable $date): void
    {
        if (! $date->isToday()) {
            throw new DomainException(ErrorCode::DayNotEditable);
        }
    }

    /** Item pedido na rota: precisa ser do usuário e da data (RN43 — senão, 404). */
    public function item(User $user, CarbonImmutable $date, int $itemId): DayMealItem
    {
        $item = DayMealItem::with(['food', 'dayMeal'])->find($itemId);
        if ($item === null || $item->dayMeal->user_id !== $user->id || ! $item->dayMeal->date->isSameDay($date)) {
            throw new NotFoundHttpException;
        }

        return $item;
    }

    /**
     * RF14 — opções de troca e a garantia com todas as restrições da pessoa.
     *
     * @return array{item: DayMealItem, options: list<SubstitutionOption>, restrictions: list<string>}
     */
    public function substitutions(User $user, CarbonImmutable $date, int $itemId): array
    {
        $this->assertEditable($date);
        $item = $this->item($user, $date, $itemId);

        return [
            'item' => $item,
            'options' => $this->options($user, $item),
            'restrictions' => [...$user->restrictions()->pluck('label')->all(), ...$user->profile->other_restrictions],
        ];
    }

    /** RN26 — troca só por uma das opções válidas agora (RN17 garantido pelo FoodFilter). */
    public function swap(User $user, CarbonImmutable $date, int $itemId, int $foodId): void
    {
        $this->assertEditable($date);
        $item = $this->item($user, $date, $itemId);
        if (MealEntry::where('suggestion_item_id', $item->id)->exists()) {
            throw new DomainException(ErrorCode::SuggestionAlreadyRegistered); // RN26 (D13): já comido não se troca
        }
        $option = collect($this->options($user, $item))->first(fn (SubstitutionOption $o) => $o->food->id === $foodId)
            ?? throw new DomainException(ErrorCode::SubstitutionNotAllowed);

        DB::transaction(function () use ($user, $date, $item, $option) {
            DayMealChange::create([
                'user_id' => $user->id,
                'date' => $date,
                'day_meal_id' => $item->day_meal_id,
                'type' => DayChangeType::Swap,
                'description' => $item->food->name.' trocado por '.mb_strtolower(mb_substr($option->food->name, 0, 1)).mb_substr($option->food->name, 1),
                'items_before' => $this->snapshot($item->dayMeal),
            ]);

            $item->update([
                'food_id' => $option->food->id,
                'grams' => $option->grams,
                'source' => ItemSource::Manual,
                'replaced_food_id' => $item->replaced_food_id ?? $item->food_id,
            ]);
        });
    }

    /** RN31 — troca um alimento da refeição de hoje pelo que o Nutri mostrou (mesmas regras da troca manual). */
    public function replaceFood(User $user, CarbonImmutable $date, string $slot, int $fromFoodId, int $toFoodId, float $grams, ItemSource $source): void
    {
        $meal = $this->editableMeal($user, $date, $slot);
        $item = $meal->items()->with('food')->where('food_id', $fromFoodId)->first();
        $to = $this->filter->allowedFor($user)->get($toFoodId);
        if ($item === null || $to === null) {
            throw new DomainException(ErrorCode::SubstitutionNotAllowed);
        }

        DB::transaction(function () use ($user, $date, $meal, $item, $to, $grams, $source) {
            DayMealChange::create([
                'user_id' => $user->id, 'date' => $date, 'day_meal_id' => $meal->id, 'type' => DayChangeType::Swap,
                'description' => $item->food->name.' trocado por '.mb_strtolower(mb_substr($to->name, 0, 1)).mb_substr($to->name, 1),
                'items_before' => $this->snapshot($meal),
            ]);
            $item->update(['food_id' => $to->id, 'grams' => $grams, 'source' => $source, 'replaced_food_id' => $item->replaced_food_id ?? $item->food_id]);
        });
    }

    /**
     * RN31 — troca a refeição inteira de hoje.
     *
     * @param  list<array{food_id: int, grams: float}>  $items
     */
    public function applyMeal(User $user, CarbonImmutable $date, string $slot, array $items, ItemSource $source): void
    {
        $meal = $this->editableMeal($user, $date, $slot);
        $allowed = $this->filter->allowedFor($user);
        foreach ($items as $item) {
            if (! $allowed->has($item['food_id'])) {
                throw new DomainException(ErrorCode::SubstitutionNotAllowed);
            }
        }

        DB::transaction(function () use ($user, $date, $meal, $items, $source) {
            DayMealChange::create([
                'user_id' => $user->id, 'date' => $date, 'day_meal_id' => $meal->id, 'type' => DayChangeType::ApplyMeal,
                'description' => $meal->name.' trocado pelo Nutri',
                'items_before' => $this->snapshot($meal),
            ]);
            $meal->items()->delete();
            foreach ($items as $i => $item) {
                $meal->items()->create(['food_id' => $item['food_id'], 'grams' => $item['grams'], 'source' => $source, 'position' => $i + 1]);
            }
        });
    }

    /** Refeição de hoje cuja sugestão ainda pode mudar inteira (RN23; com registro não troca — RN26). */
    private function editableMeal(User $user, CarbonImmutable $date, string $slot): DayMeal
    {
        $this->assertEditable($date);
        $meal = $this->days->meals($user, $date)->firstWhere('slot', $slot) ?? throw new NotFoundHttpException;
        if ($meal->isDone()) {
            throw new DomainException(ErrorCode::MealAlreadyDone, [], 'Você já registrou o que comeu nesse '.mb_strtolower($meal->name).'.');
        }

        return $meal;
    }

    /** RN27 — volta a última alteração de conteúdo (até 15 min), um passo por vez. */
    public function undo(User $user, CarbonImmutable $date): void
    {
        $this->assertEditable($date);
        $change = DayMealChange::undoableFor($user, $date)->first() ?? throw new DomainException(ErrorCode::NothingToUndo);

        $allowed = $this->filter->allowedFor($user);

        DB::transaction(function () use ($change, $allowed) {
            $meal = $change->dayMeal()->firstOrFail();
            // Linha a linha, pela posição: quem não mudou fica, e os registros seguem ligados à sugestão (RN27, D13).
            $rows = $meal->items()->get()->keyBy('position');
            $kept = [];
            foreach ($change->items_before as $item) {
                if (! $allowed->has($item['food_id'])) {
                    continue; // RN17: o que ficou proibido depois da troca não volta
                }
                $row = $rows->get($item['position']);
                $row !== null ? $row->update($item) : $meal->items()->create($item);
                $kept[] = $item['position'];
            }
            $meal->items()->whereNotIn('position', $kept)->delete();
            $change->update(['undone_at' => now()]);
        });
    }

    /**
     * Itens da refeição como estavam — o "antes" de uma alteração.
     *
     * @return list<array{food_id: int, grams: float, replaced_food_id: int|null, source: string, position: int}>
     */
    public function snapshot(DayMeal $meal): array
    {
        return $meal->items()->get()->map(fn (DayMealItem $item) => [
            'food_id' => $item->food_id,
            'grams' => $item->grams,
            'replaced_food_id' => $item->replaced_food_id,
            'source' => $item->source->value,
            'position' => $item->position,
        ])->values()->all();
    }

    /** @return list<SubstitutionOption> */
    private function options(User $user, DayMealItem $item): array
    {
        return $this->finder->find($item->food, $item->grams, $this->filter->allowedFor($user), $this->filter->pantryFoodIds($user));
    }
}
