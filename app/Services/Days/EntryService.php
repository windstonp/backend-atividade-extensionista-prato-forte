<?php

namespace App\Services\Days;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use App\Models\CustomFood;
use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Models\Food;
use App\Models\MealEntry;
use App\Models\User;
use App\Services\Nutrition\DayTotals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Única porta de escrita do registro alimentar (RN46, RN49, RN23: hoje e ontem). */
class EntryService
{
    public function __construct(private readonly DayMaterializer $days) {}

    public function assertRegistrable(CarbonImmutable $date): void
    {
        if (! $date->isToday() && ! $date->isYesterday()) {
            throw new DomainException(ErrorCode::DayNotEditable);
        }
    }

    /** @param list<array{suggestion_item_id?: int, food_id?: int, custom_food_id?: int, amount?: float}> $entries */
    public function add(User $user, CarbonImmutable $date, string $slot, array $entries): void
    {
        $this->assertRegistrable($date);
        $meal = $this->days->mealsForWriting($user, $date)->firstWhere('slot', $slot) ?? throw new NotFoundHttpException;

        DB::transaction(function () use ($user, $meal, $entries) {
            $meal = DayMeal::lockForUpdate()->findOrFail($meal->id);
            $position = (int) $meal->entries()->max('position');
            foreach ($entries as $entry) {
                $meal->entries()->create(['user_id' => $user->id, 'position' => ++$position, ...$this->resolve($user, $meal, $entry)]);
            }
            $this->syncDone($meal);
        });
    }

    public function update(User $user, CarbonImmutable $date, int $entryId, float $amount): void
    {
        $this->assertRegistrable($date);
        $entry = $this->entry($user, $date, $entryId);
        $snapshot = match (true) {
            $entry->food_id !== null => $this->fromFood(Food::findOrFail($entry->food_id), $amount),
            default => $this->fromCustom(CustomFood::withTrashed()->findOrFail($entry->custom_food_id), $amount),
        };

        $entry->update($snapshot);
    }

    public function remove(User $user, CarbonImmutable $date, int $entryId): void
    {
        $this->assertRegistrable($date);
        $entry = $this->entry($user, $date, $entryId);

        DB::transaction(function () use ($entry) {
            $meal = $entry->dayMeal()->firstOrFail();
            $entry->delete();
            $this->syncDone($meal);
        });
    }

    /**
     * @param  array{suggestion_item_id?: int, food_id?: int, custom_food_id?: int, amount?: float}  $entry
     * @return array<string, mixed>
     */
    private function resolve(User $user, DayMeal $meal, array $entry): array
    {
        if (isset($entry['suggestion_item_id'])) {
            $item = DayMealItem::with(['food', 'dayMeal'])->find($entry['suggestion_item_id']);
            if ($item === null || $item->dayMeal->user_id !== $user->id) {
                throw new NotFoundHttpException;
            }
            if ($item->day_meal_id !== $meal->id) {
                throw ValidationException::withMessages(['entries' => 'Esse alimento é de outra refeição.']);
            }
            if ($meal->entries()->where('suggestion_item_id', $item->id)->exists()) {
                throw new DomainException(ErrorCode::AlreadyRegistered);
            }

            return ['suggestion_item_id' => $item->id, 'food_id' => $item->food_id,
                ...$this->fromFood($item->food, (float) ($entry['amount'] ?? $item->grams))];
        }

        if (isset($entry['custom_food_id'])) {
            $food = CustomFood::where('user_id', $user->id)->find($entry['custom_food_id']) ?? throw new NotFoundHttpException;

            return ['custom_food_id' => $food->id, ...$this->fromCustom($food, (float) $entry['amount'])];
        }

        $food = Food::where('is_active', true)->find($entry['food_id'] ?? 0)
            ?? throw ValidationException::withMessages(['entries' => 'Esse alimento não está no catálogo.']);

        return ['food_id' => $food->id, ...$this->fromFood($food, (float) $entry['amount'])];
    }

    /** @return array<string, mixed> RN49 — retrato */
    private function fromFood(Food $food, float $amount): array
    {
        return ['name' => $food->name, 'measure' => $food->measure, 'amount' => $amount,
            ...DayTotals::portion($food->kcal_per_100g, $food->protein_per_100g, $food->carbs_per_100g, $food->fat_per_100g, $amount)];
    }

    /** @return array<string, mixed> RN49 — retrato */
    private function fromCustom(CustomFood $food, float $amount): array
    {
        return ['name' => $food->name, 'measure' => $food->measure, 'amount' => $amount,
            ...DayTotals::portion($food->kcal_per_100, $food->protein_per_100, $food->carbs_per_100, $food->fat_per_100, $amount)];
    }

    private function entry(User $user, CarbonImmutable $date, int $entryId): MealEntry
    {
        $entry = MealEntry::with('dayMeal')->find($entryId);
        if ($entry === null || $entry->user_id !== $user->id || ! $entry->dayMeal->date->isSameDay($date)) {
            throw new NotFoundHttpException;
        }

        return $entry;
    }

    /** RN46 — done_at = horário do primeiro registro; sem registro, null. */
    private function syncDone(DayMeal $meal): void
    {
        $first = $meal->entries()->min('created_at');
        $meal->update(['done_at' => $first]);
    }
}
