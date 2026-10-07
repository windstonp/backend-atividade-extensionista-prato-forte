<?php

namespace App\Http\Resources;

use App\Models\CustomFood;
use App\Models\Food;
use App\Services\Nutrition\PortionFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Resultado da busca/recentes/cadastro (spec 09 §5). @property array{food: Food|CustomFood, conflicts: list<string>, last_amount?: float|null} $resource */
class FoodResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $food = $this->resource['food'];
        $catalog = $food instanceof Food;
        $data = [
            'id' => $food->id,
            'kind' => $catalog ? 'catalog' : 'custom',
            'name' => $food->name,
            'measure' => $food->measure,
            'group' => $catalog ? $food->group : null,
            'per_100' => $catalog
                ? ['calories' => $food->kcal_per_100g, 'protein' => $food->protein_per_100g, 'carbs' => $food->carbs_per_100g, 'fat' => $food->fat_per_100g]
                : ['calories' => $food->kcal_per_100, 'protein' => $food->protein_per_100, 'carbs' => $food->carbs_per_100, 'fat' => $food->fat_per_100],
            'portion' => $catalog ? ['amount' => $food->typical_portion_g, 'text' => app(PortionFormatter::class)->forFood($food, $food->typical_portion_g)] : null,
            'household' => $catalog && $food->unit_label !== null && $food->unit_grams !== null
                ? ['label' => $food->unit_label, 'label_plural' => $food->unit_label_plural, 'amount' => $food->unit_grams] : null,
            'conflicts' => $this->resource['conflicts'],
        ];
        if (array_key_exists('last_amount', $this->resource)) {
            $data['last_amount'] = $this->resource['last_amount'];
        }

        return $data;
    }
}
