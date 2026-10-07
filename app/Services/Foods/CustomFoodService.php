<?php

namespace App\Services\Foods;

use App\Models\CustomFood;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** RF35/RF37 — alimento próprio: privado, sem limite, editável, exclusão lógica (RN50). */
class CustomFoodService
{
    /** @param array{name: string, measure: string, per_100: array{calories: float, protein: float, carbs: float, fat: float}} $data */
    public function create(User $user, array $data): CustomFood
    {
        $this->assertUniqueName($user, $data['name'], null);
        $this->assertCoherent($data['per_100']);

        return $user->customFoods()->create($this->columns($data));
    }

    /** @param array{name?: string, measure?: string, per_100?: array<string, float>} $data */
    public function update(User $user, int $id, array $data): CustomFood
    {
        $food = $user->customFoods()->find($id) ?? throw new NotFoundHttpException;
        $merged = [
            'name' => $data['name'] ?? $food->name,
            'measure' => $data['measure'] ?? $food->measure,
            'per_100' => array_merge(
                ['calories' => $food->kcal_per_100, 'protein' => $food->protein_per_100, 'carbs' => $food->carbs_per_100, 'fat' => $food->fat_per_100],
                $data['per_100'] ?? [],
            ),
        ];
        $this->assertUniqueName($user, $merged['name'], $food->id);
        $this->assertCoherent($merged['per_100']);
        $food->update($this->columns($merged));

        return $food;
    }

    public function delete(User $user, int $id): void
    {
        ($user->customFoods()->find($id) ?? throw new NotFoundHttpException)->delete();
    }

    /** @param array{calories: float, protein: float, carbs: float, fat: float} $per100 */
    private function assertCoherent(array $per100): void
    {
        if ($per100['protein'] + $per100['carbs'] + $per100['fat'] > 100) {
            throw ValidationException::withMessages(['per_100' => 'Proteína, carboidrato e gordura somam mais de 100 g.']);
        }
        if (4 * $per100['protein'] + 4 * $per100['carbs'] + 9 * $per100['fat'] > $per100['calories'] * 1.25 + 20) {
            throw ValidationException::withMessages(['per_100.calories' => 'Os números não batem: confira as calorias.']);
        }
    }

    private function assertUniqueName(User $user, string $name, ?int $except): void
    {
        $exists = $user->customFoods()->where('name_normalized', FoodFilter::normalize($name))
            ->when($except, fn ($q) => $q->where('id', '!=', $except))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => 'Você já cadastrou um alimento com esse nome.']);
        }
    }

    /**
     * @param  array{name: string, measure: string, per_100: array{calories: float, protein: float, carbs: float, fat: float}}  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        return [
            'name' => trim($data['name']), 'name_normalized' => FoodFilter::normalize($data['name']), 'measure' => $data['measure'],
            'kcal_per_100' => $data['per_100']['calories'], 'protein_per_100' => $data['per_100']['protein'],
            'carbs_per_100' => $data['per_100']['carbs'], 'fat_per_100' => $data['per_100']['fat'],
        ];
    }
}
