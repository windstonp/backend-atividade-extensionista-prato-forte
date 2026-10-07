<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FoodResultResource;
use App\Models\CustomFood;
use App\Models\Food;
use App\Models\User;
use App\Services\Foods\FoodFilter;
use App\Services\Foods\FoodSearch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** RF33 — buscar no catálogo e nos alimentos próprios; recentes. */
class FoodController extends Controller
{
    public function index(Request $request, FoodSearch $search, FoodFilter $filter): AnonymousResourceCollection
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:60'], 'limit' => ['integer', 'between:1,20']],
            ['q.*' => 'Digite pelo menos 2 letras.']);
        /** @var User $user */
        $user = $request->user();
        $foods = $search->search($user, $data['q'], (int) ($data['limit'] ?? 20));

        return FoodResultResource::collection($this->withConflicts($user, $foods->map(fn ($f) => ['food' => $f])->all(), $filter));
    }

    public function recent(Request $request, FoodSearch $search, FoodFilter $filter): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return FoodResultResource::collection($this->withConflicts($user, $search->recent($user)->all(), $filter));
    }

    /**
     * @param  array<int, array{food: Food|CustomFood, last_amount?: float}>  $rows
     * @return list<array{food: Food|CustomFood, last_amount?: float, conflicts: list<string>}>
     */
    private function withConflicts(User $user, array $rows, FoodFilter $filter): array
    {
        $catalog = collect($rows)->pluck('food')->filter(fn ($f) => $f instanceof Food)->keyBy('id');
        $conflicts = $filter->conflictsFor($user, $catalog);

        return array_values(array_map(fn (array $row) => [...$row, 'conflicts' => $row['food'] instanceof Food ? ($conflicts[$row['food']->id] ?? []) : []], $rows));
    }
}
