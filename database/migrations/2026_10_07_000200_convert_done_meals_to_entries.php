<?php

use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Services\Nutrition\DayTotals;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** CA44 — "feita" antes da D13 = comeu a sugestão: vira registro igual a cada item. Idempotente. */
return new class extends Migration
{
    public function up(): void
    {
        DayMeal::whereNotNull('done_at')->whereDoesntHave('entries')->with('items.food')->chunkById(200, function ($meals) {
            foreach ($meals as $meal) {
                DB::transaction(function () use ($meal) {
                    foreach ($meal->items as $i => $item) {
                        /** @var DayMealItem $item */
                        $meal->entries()->forceCreate([
                            'user_id' => $meal->user_id, 'food_id' => $item->food_id, 'suggestion_item_id' => $item->id,
                            'name' => $item->food->name, 'measure' => $item->food->measure, 'amount' => $item->grams,
                            'position' => $i + 1, 'created_at' => $meal->done_at, 'updated_at' => $meal->done_at,
                            ...DayTotals::item($item->food, (float) $item->grams),
                        ]);
                    }
                });
            }
        });
    }

    public function down(): void
    {
        // Sem volta: os registros passam a ser a fonte do consumido.
    }
};
