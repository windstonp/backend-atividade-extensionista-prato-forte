<?php

namespace App\Services\Foods;

use App\Models\Food;

final readonly class SubstitutionOption
{
    /** @param array{calories: int, protein: float, carbs: float, fat: float} $macros */
    public function __construct(
        public Food $food,
        public float $grams,
        public array $macros,
        public int $calorieDelta,
        public bool $inPantry,
    ) {}
}
