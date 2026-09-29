<?php

namespace App\Services\Nutrition;

use App\Enums\GoalWeightSource;

final readonly class GoalWeight
{
    public function __construct(public float $kg, public GoalWeightSource $source) {}
}
