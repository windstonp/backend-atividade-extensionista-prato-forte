<?php

namespace Database\Seeders;

use App\Models\Restriction;
use Illuminate\Database\Seeder;

/** As 6 restrições do mock (`lib/labels.ts`), com as alergias marcadas. Idempotente. */
class RestrictionSeeder extends Seeder
{
    private const RESTRICTIONS = [
        ['lactose', 'Intolerância a lactose', false],
        ['gluten', 'Glúten', false],
        ['castanhas', 'Amendoim e castanhas', true],
        ['frutos-do-mar', 'Frutos do mar', true],
        ['sem-carne', 'Não como carne', false],
        ['sem-animal', 'Não como nada de origem animal', false],
    ];

    public function run(): void
    {
        foreach (self::RESTRICTIONS as $i => [$slug, $label, $isAllergy]) {
            Restriction::updateOrCreate(['slug' => $slug], ['label' => $label, 'is_allergy' => $isAllergy, 'position' => $i + 1]);
        }
    }
}
