<?php

namespace App\Services\Nutrition;

use App\Models\Food;

/** "150 g, mais ou menos 6 colheres de sopa" (ou "200 ml, …") — quantidade e medida caseira (meia em meia). Puro. */
final class PortionFormatter
{
    public function forFood(Food $food, float $amount): string
    {
        return $this->format($amount, $food->unit_label, $food->unit_label_plural, $food->unit_grams, $food->measure ?? 'g');
    }

    /** Quantidade na medida do alimento (g ou ml — RN47) e a medida caseira. */
    public function format(float $amount, ?string $unit, ?string $unitPlural, ?float $unitGrams, string $measure = 'g'): string
    {
        $text = $this->number($amount).' '.$measure;
        if ($unit === null || $unitGrams === null || $unitGrams <= 0) {
            return $text;
        }

        $count = round($amount / $unitGrams * 2) / 2;
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
