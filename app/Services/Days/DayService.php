<?php

namespace App\Services\Days;

use App\Enums\DayChangeType;
use App\Enums\ErrorCode;
use App\Enums\ItemSource;
use App\Exceptions\DomainException;
use App\Models\DayMeal;
use App\Models\DayMealChange;
use App\Models\DayMealItem;
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

    /** RF13 — marcar ou desmarcar uma refeição. */
    public function setDone(User $user, CarbonImmutable $date, string $slot, bool $done): void
    {
        $this->assertEditable($date);
        $meal = $this->days->meals($user, $date)->firstWhere('slot', $slot) ?? throw new NotFoundHttpException;

        $meal->update(['done_at' => $done ? ($meal->done_at ?? now()) : null]);
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

    /** RN27 — volta a última alteração de conteúdo (até 15 min), um passo por vez. */
    public function undo(User $user, CarbonImmutable $date): void
    {
        $this->assertEditable($date);
        $change = DayMealChange::undoableFor($user, $date)->first() ?? throw new DomainException(ErrorCode::NothingToUndo);

        $allowed = $this->filter->allowedFor($user);

        DB::transaction(function () use ($change, $allowed) {
            $meal = $change->dayMeal()->firstOrFail();
            $meal->items()->delete();
            foreach ($change->items_before as $item) {
                if ($allowed->has($item['food_id'])) { // RN17: o que ficou proibido depois da troca não volta
                    $meal->items()->create($item);
                }
            }
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
