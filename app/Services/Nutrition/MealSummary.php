<?php

namespace App\Services\Nutrition;

/** RN15 — resumo da refeição: nomes unidos por vírgula e "e". Puro. */
final class MealSummary
{
    /** @param list<string> $names */
    public static function of(array $names): string
    {
        $parts = array_map(
            fn (string $name, int $i) => $i === 0 ? $name : mb_strtolower(mb_substr($name, 0, 1)).mb_substr($name, 1),
            $names,
            array_keys($names),
        );

        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' e '.$last;
    }
}
