<?php

namespace App\Services\Days;

use App\Models\DayMeal;
use App\Models\DayMealChange;
use App\Models\MealPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final readonly class DayView
{
    /** @param Collection<int, DayMeal> $meals em ordem de horário */
    public function __construct(
        public CarbonImmutable $date,
        public Collection $meals,
        public ?MealPlan $plan,
        public bool $materialized,
        public bool $isTrainingDay,
        public ?DayMealChange $lastChange,
    ) {}
}
