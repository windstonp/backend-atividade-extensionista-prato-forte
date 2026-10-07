<?php

namespace Database\Seeders;

use App\Models\Food;
use App\Models\PantryItem;
use App\Models\Restriction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;
use SplFileObject;

/**
 * Alimentos de `database/data/foods.csv` (valores por 100 g; fonte em cada linha — pendência P5).
 * Idempotente: atualiza pelo slug e ressincroniza as restrições e os itens de cozinha.
 * Rode depois de RestrictionSeeder e PantryItemSeeder (use CatalogSeeder).
 */
class FoodSeeder extends Seeder
{
    public function run(): void
    {
        $restrictions = Restriction::pluck('id', 'slug');
        $pantry = PantryItem::pluck('id', 'slug');

        foreach ($this->rows() as $row) {
            $food = Food::updateOrCreate(['slug' => $row['slug']], [
                'name' => $row['name'],
                'aliases' => $this->list($row['aliases']),
                'group' => $row['group'],
                'kcal_per_100g' => $row['kcal'],
                'protein_per_100g' => $row['protein'],
                'carbs_per_100g' => $row['carbs'],
                'fat_per_100g' => $row['fat'],
                'typical_portion_g' => $row['portion_g'],
                'unit_label' => $row['unit'] !== '' ? $row['unit'] : null,
                'unit_label_plural' => $row['unit_plural'] !== '' ? $row['unit_plural'] : null,
                'unit_grams' => $row['unit_g'] !== '' ? $row['unit_g'] : null,
                'substitution_note' => $row['note'] !== '' ? $row['note'] : null,
                'common_dislike' => $row['dislike'] === '1',
                'is_staple' => $row['staple'] === '1',
                'is_active' => true,
                'source' => $row['source'],
                'measure' => ($row['measure'] ?? 'g') === 'ml' ? 'ml' : 'g',
                'in_plans' => ($row['in_plans'] ?? '1') === '1',
            ]);

            $food->restrictions()->sync($this->ids($restrictions, $this->list($row['restrictions']), $food->slug));
            $food->pantryItems()->sync($this->ids($pantry, $this->list($row['pantry']), $food->slug));
        }
    }

    /** @return list<array<string, string>> */
    private function rows(): array
    {
        $file = new SplFileObject(database_path('data/foods.csv'));
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);

        $header = null;
        $rows = [];
        foreach ($file as $line) {
            /** @var list<string> $line */
            if ($header === null) {
                $header = $line;

                continue;
            }
            $rows[] = array_combine($header, $line);
        }

        return $rows;
    }

    /**
     * "a|b" → ["a", "b"]

     *

     * @return list<string>
     */
    private function list(string $value): array
    {
        return array_values(array_filter(explode('|', $value), fn (string $item) => $item !== ''));
    }

    /**
     * @param  Collection<string, int>  $known
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function ids(Collection $known, array $slugs, string $food): array
    {
        return array_map(
            fn (string $slug) => $known[$slug] ?? throw new RuntimeException("foods.csv: '{$slug}' desconhecido em {$food}"),
            $slugs,
        );
    }
}
