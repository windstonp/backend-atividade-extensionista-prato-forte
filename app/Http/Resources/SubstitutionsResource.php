<?php

namespace App\Http\Resources;

use App\Models\DayMealItem;
use App\Services\Foods\SubstitutionOption;
use App\Services\Nutrition\DayTotals;
use App\Services\Nutrition\PortionFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Folha de troca (`GET /days/{date}/items/{item}/substitutions`).
 *
 * @property array{item: DayMealItem, options: list<SubstitutionOption>, restrictions: list<string>} $resource
 */
class SubstitutionsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $item = $this->resource['item'];
        $formatter = app(PortionFormatter::class);
        $macros = DayTotals::item($item->food, $item->grams);

        return [
            'item' => [
                'id' => $item->id,
                'name' => $item->food->name,
                'amount' => $formatter->forFood($item->food, $item->grams),
                'calories' => $macros['calories'],
                'macros' => ['protein' => $macros['protein'], 'carbs' => $macros['carbs'], 'fat' => $macros['fat']],
            ],
            'options' => array_map(fn (SubstitutionOption $option) => [
                'food_id' => $option->food->id,
                'name' => $option->food->name,
                'grams' => $option->grams,
                'amount' => $formatter->forFood($option->food, $option->grams),
                'calories' => $option->macros['calories'],
                'macros' => ['protein' => $option->macros['protein'], 'carbs' => $option->macros['carbs'], 'fat' => $option->macros['fat']],
                'calorie_delta' => $option->calorieDelta,
                'note' => $option->food->substitution_note,
                'in_pantry' => $option->inPantry,
            ], $this->resource['options']),
            'guarantee' => ['restrictions' => $this->resource['restrictions']],
        ];
    }
}
