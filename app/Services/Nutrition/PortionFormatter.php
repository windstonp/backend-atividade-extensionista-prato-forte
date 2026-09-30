<?php

namespace App\Services\Nutrition;

use App\Models\Food;

/** "150 g, mais ou menos 6 colheres de sopa" — gramas e medida caseira (meia em meia). Puro. */
final class PortionFormatter
{
    public function forFood(Food $food, float $grams): string
    {
        return $this->format($grams, $food->unit_label, $food->unit_label_plural, $food->unit_grams);
    }

    public function format(float $grams, ?string $unit, ?string $unitPlural, ?float $unitGrams): string
    {
        $text = $this->number($grams).' g';
        if ($unit === null || $unitGrams === null || $unitGrams <= 0) {
            return $text;
        }

        $count = round($grams / $unitGrams * 2) / 2;
        if ($count < 1) {
            return $text;
        }

        $name = $count == 1.0 ? $unit : ($unitPlural ?? $unit);

        return "{$text}, mais ou menos {$this->number($count)} {$name}";
    }

    private function number(float $value): string
    {
        return floor($value) == $value ? (string) (int) $value : str_replace('.', ',', (string) round($value, 1));
    }
}
