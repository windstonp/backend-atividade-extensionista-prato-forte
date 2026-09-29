<?php

namespace App\Services\Nutrition;

final readonly class DailyTargets
{
    public function __construct(
        public int $kcal,
        public int $proteinG,
        public int $carbsG,
        public int $fatG,
    ) {}
}
