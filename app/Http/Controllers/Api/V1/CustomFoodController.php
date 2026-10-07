<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FoodResultResource;
use App\Models\User;
use App\Services\Foods\CustomFoodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** RF35/RF37 — alimento próprio. */
class CustomFoodController extends Controller
{
    /** @return array<string, list<string>> */
    private function rules(bool $partial): array
    {
        $r = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$r, 'string', 'min:2', 'max:60', 'not_regex:/^\s*$/'],
            'measure' => [$r, 'in:g,ml'],
            'per_100' => [$r, 'array'],
            'per_100.calories' => [$r, 'numeric', 'between:0,900'],
            'per_100.protein' => [$r, 'numeric', 'between:0,100'],
            'per_100.carbs' => [$r, 'numeric', 'between:0,100'],
            'per_100.fat' => [$r, 'numeric', 'between:0,100'],
        ];
    }

    /** @var array<string, string> */
    private const MESSAGES = [
        'name.*' => 'Dê um nome de 2 a 60 letras.',
        'measure.*' => 'Escolha sólido (g) ou líquido (ml).',
        'per_100.calories.*' => 'Calorias entre 0 e 900 por 100.',
        'per_100.*.*' => 'Use um valor entre 0 e 100 g.',
    ];

    public function store(Request $request, CustomFoodService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $food = $service->create($user, $request->validate($this->rules(false), self::MESSAGES));

        return (new FoodResultResource(['food' => $food, 'conflicts' => []]))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $customFood, CustomFoodService $service): FoodResultResource
    {
        /** @var User $user */
        $user = $request->user();

        return new FoodResultResource(['food' => $service->update($user, $customFood, $request->validate($this->rules(true), self::MESSAGES)), 'conflicts' => []]);
    }

    public function destroy(Request $request, int $customFood, CustomFoodService $service): Response
    {
        /** @var User $user */
        $user = $request->user();
        $service->delete($user, $customFood);

        return response()->noContent();
    }
}
