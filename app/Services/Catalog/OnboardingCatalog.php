<?php

namespace App\Services\Catalog;

use App\Enums\ActivityLevel;
use App\Enums\Goal;
use App\Enums\LunchPlace;
use App\Enums\WorkPosture;
use App\Models\Food;
use App\Models\PantryItem;
use App\Models\Restriction;
use Illuminate\Support\Collection;

/** Opções de todas as etapas do onboarding e de Preferências (`GET /catalog/onboarding`). */
class OnboardingCatalog
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'goals' => array_map(fn (Goal $goal) => ['value' => $goal->value, 'label' => $goal->label(), 'description' => $goal->description()], Goal::cases()),
            'activity_levels' => array_map(fn (ActivityLevel $level) => ['value' => $level->value, 'label' => $level->label(), 'description' => $level->description()], ActivityLevel::cases()),
            'work_postures' => array_map(fn (WorkPosture $posture) => ['value' => $posture->value, 'label' => $posture->label()], WorkPosture::cases()),
            'restrictions' => Restriction::orderBy('position')->get()
                ->map(fn (Restriction $r) => ['slug' => $r->slug, 'label' => $r->label, 'is_allergy' => $r->is_allergy])->all(),
            'pantry' => PantryItem::orderBy('position')->get()->groupBy('category')
                ->map(fn (Collection $items, string $category) => [
                    'category' => $category,
                    'label' => PantryItem::CATEGORIES[$category] ?? $category,
                    'items' => $items->map(fn (PantryItem $item) => ['slug' => $item->slug, 'label' => $item->label])->values()->all(),
                ])->values()->all(),
            'dislike_options' => Food::where('common_dislike', true)->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Food $food) => ['id' => $food->id, 'name' => $food->name])->all(),
            'lunch_places' => array_map(fn (LunchPlace $place) => ['value' => $place->value, 'label' => $place->label()], LunchPlace::cases()),
        ];
    }
}
