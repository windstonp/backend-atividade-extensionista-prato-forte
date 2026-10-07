<?php

namespace App\Services\Foods;

use App\Models\CustomFood;
use App\Models\Food;
use App\Models\MealEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** RN50 — busca no catálogo ativo (inclusive fora do plano) e nos alimentos próprios; recentes. */
class FoodSearch
{
    /** @return Collection<int, Food|CustomFood> */
    public function search(User $user, string $q, int $limit = 20): Collection
    {
        $term = FoodFilter::normalize($q);
        $uses = $this->uses($user);

        $catalog = Food::where('is_active', true)->get()
            ->map(fn (Food $f) => [$f, $this->rank($term, [$f->name, ...$f->aliases])])
            ->filter(fn (array $pair) => $pair[1] !== null);
        $custom = $user->customFoods()->get()
            ->map(fn (CustomFood $f) => [$f, $this->rank($term, [$f->name])])
            ->filter(fn (array $pair) => $pair[1] !== null);

        return $catalog->concat($custom)
            ->sortBy([
                fn (array $a, array $b) => $a[1] <=> $b[1],
                fn (array $a, array $b) => ($uses[$this->key($b[0])] ?? 0) <=> ($uses[$this->key($a[0])] ?? 0),
                fn (array $a, array $b) => strcmp(FoodFilter::normalize($a[0]->name), FoodFilter::normalize($b[0]->name)),
            ])
            ->take($limit)
            ->map(fn (array $pair) => $pair[0])
            ->values();
    }

    /** @return Collection<int, array{food: Food|CustomFood, last_amount: float}> */
    public function recent(User $user): Collection
    {
        $rows = MealEntry::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('created_at')
            ->get(['food_id', 'custom_food_id', 'amount', 'created_at']);

        return $rows->groupBy(fn (MealEntry $e) => $e->food_id !== null ? "f{$e->food_id}" : "c{$e->custom_food_id}")
            ->sortByDesc(fn ($group) => $group->count())
            ->take(8)
            ->map(function ($group) use ($user) {
                $first = $group->first();
                $food = $first->food_id !== null
                    ? Food::where('is_active', true)->whereKey($first->food_id)->first()
                    : CustomFood::where('user_id', $user->id)->whereKey($first->custom_food_id)->first();

                return $food === null ? null : ['food' => $food, 'last_amount' => (float) $first->amount];
            })
            ->filter()
            ->values();
    }

    /**
     * 0 = começa com o termo (nome ou sinônimo), 1 = contém, null = não bate.
     *
     * @param  list<string>  $names
     */
    private function rank(string $term, array $names): ?int
    {
        $best = null;
        foreach ($names as $name) {
            $n = FoodFilter::normalize((string) $name);
            if (str_starts_with($n, $term)) {
                return 0;
            }
            if (str_contains($n, $term)) {
                $best = 1;
            }
        }

        return $best;
    }

    /** @return array<string, int> quantas vezes cada alimento foi registrado em 30 dias */
    private function uses(User $user): array
    {
        return MealEntry::where('user_id', $user->id)->where('created_at', '>=', now()->subDays(30))
            ->select('food_id', 'custom_food_id', DB::raw('count(*) as n'))->groupBy('food_id', 'custom_food_id')->get()
            ->mapWithKeys(fn ($r) => [$r->food_id !== null ? "f{$r->food_id}" : "c{$r->custom_food_id}" => (int) $r->getAttribute('n')])->all();
    }

    private function key(Food|CustomFood $food): string
    {
        return ($food instanceof Food ? 'f' : 'c').$food->id;
    }
}
