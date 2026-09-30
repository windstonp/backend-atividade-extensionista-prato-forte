<?php

namespace App\Services\Foods;

use App\Models\Food;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** RN16 — única porta de entrada de alimentos para geração, trocas e ações do Nutri. */
class FoodFilter
{
    /**
     * Catálogo ativo − restrições − "outras restrições" (nome ou sinônimo) − "não curto".
     *
     * @return Collection<int, Food> chave = id
     */
    public function allowedFor(User $user): Collection
    {
        $terms = array_values(array_filter(array_map(self::normalize(...), $user->profile->other_restrictions)));

        return Food::query()
            ->where('is_active', true)
            ->whereDoesntHave('restrictions', fn ($query) => $query->whereIn('restrictions.id', $user->restrictions()->pluck('restrictions.id')))
            ->whereNotIn('id', $user->dislikedFoods()->pluck('foods.id'))
            ->orderBy('id')
            ->get()
            ->reject(fn (Food $food) => $this->matchesAny($food, $terms))
            ->keyBy('id');
    }

    /**
     * Alimentos ligados aos itens da cozinha do usuário: prioridade, não exclusividade.
     *
     * @return list<int>
     */
    public function pantryFoodIds(User $user): array
    {
        return DB::table('food_pantry_item')
            ->whereIn('pantry_item_id', $user->pantryItems()->pluck('pantry_items.id'))
            ->distinct()
            ->orderBy('food_id')
            ->pluck('food_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function isAllowed(User $user, int $foodId): bool
    {
        return $this->allowedFor($user)->has($foodId);
    }

    public static function normalize(string $text): string
    {
        return Str::lower(Str::ascii(trim($text)));
    }

    /** @param list<string> $terms */
    private function matchesAny(Food $food, array $terms): bool
    {
        if ($terms === []) {
            return false;
        }

        $names = array_map(self::normalize(...), [$food->name, ...$food->aliases]);
        foreach ($terms as $term) {
            foreach ($names as $name) {
                if (str_contains($name, $term)) {
                    return true;
                }
            }
        }

        return false;
    }
}
