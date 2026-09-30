<?php

namespace App\Services\Nutrition;

use App\Enums\MealSlot;

/** RN14 — horários das refeições a partir da rotina. Puro. */
final class MealScheduler
{
    private const DAY = 24 * 60;

    private const LUNCH = 12 * 60 + 30;

    private const MIN_GAP = 90;

    /**
     * Horário de cada refeição, já em ordem de horário.
     *
     * @return array<string, string> slot => "HH:MM"
     */
    public function schedule(string $wake, string $training, string $sleep, bool $hasTrainingDays): array
    {
        $w = $this->minutes($wake);
        $t = $this->minutes($training);
        $s = $this->minutes($sleep);
        if ($s <= $w) {
            $s += self::DAY; // dorme depois da meia-noite
        }
        if ($t < $w) {
            $t += self::DAY;
        }

        if ($hasTrainingDays && $t - $w <= 120) {
            // Treino cedo: pré-treino logo ao acordar, café vira pós-treino.
            $pre = $w + 10;
            $cafe = $t + 75;
        } else {
            $cafe = $w + 40;
            $pre = ! $hasTrainingDays ? 16 * 60 : ($t - 90 >= $cafe + 120 ? $t - 90 : $t - 45);
        }

        $times = [
            MealSlot::Cafe->value => $cafe,
            MealSlot::Lanche->value => (int) (ceil(($cafe + self::LUNCH) / 2 / 30) * 30),
            MealSlot::Almoco->value => self::LUNCH,
            MealSlot::PreTreino->value => $pre,
            MealSlot::Jantar->value => $t % self::DAY >= 16 * 60 ? $t + 90 : $s - 150,
        ];

        $ordered = [];
        foreach ($times as $slot => $minutes) {
            $ordered[] = [$slot, (int) (round($minutes / 5) * 5)];
        }
        $position = array_flip(array_keys($times));
        usort($ordered, fn (array $a, array $b) => [$a[1], $position[$a[0]]] <=> [$b[1], $position[$b[0]]]);

        $result = [];
        $previous = null;
        foreach ($ordered as [$slot, $minutes]) {
            while ($previous !== null && $minutes < $previous + self::MIN_GAP) {
                $minutes += 30; // colisão: empurra para frente
            }
            $minutes = max($w, min($s - 60, $minutes));
            $result[$slot] = $this->format($minutes);
            $previous = $minutes;
        }

        return $result;
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    private function format(int $minutes): string
    {
        $minutes %= self::DAY;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
