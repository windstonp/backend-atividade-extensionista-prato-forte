<?php

namespace App\Services\Nutri;

use App\Models\Food;
use App\Models\User;
use App\Services\Foods\FoodFilter;

/** RN45 — só passam sugestões curtas, novas e sem alimento proibido. */
class FollowUpSanitizer
{
    public function __construct(private readonly FoodFilter $filter) {}

    /**
     * @param  list<string>  $suggestions
     * @return list<string> até 3
     */
    public function clean(array $suggestions, string $question, User $user): array
    {
        $allowed = $this->filter->allowedFor($user)->keys()->all();
        $forbidden = Food::query()->whereNotIn('id', $allowed)->get()
            ->flatMap(fn (Food $food) => [$food->name, ...$food->aliases])
            ->map(fn (string $name) => FoodFilter::normalize($name))
            ->filter(fn (string $name) => mb_strlen($name) >= 4)
            ->unique()->all();

        $seen = [FoodFilter::normalize($question) => true];
        $kept = [];
        foreach ($suggestions as $suggestion) {
            $text = trim($suggestion);
            $key = FoodFilter::normalize($text);
            if ($text === '' || mb_strlen($text) > 60 || isset($seen[$key]) || $this->mentions($key, $forbidden)) {
                continue;
            }
            $seen[$key] = true;
            $kept[] = $text;
        }

        return array_slice($kept, 0, 3);
    }

    /** @param list<string> $forbidden nomes normalizados */
    private function mentions(string $text, array $forbidden): bool
    {
        foreach ($forbidden as $name) {
            // "castanha-do-para" também pega "castanha": compara pela primeira palavra do nome
            $head = explode(' ', str_replace('-', ' ', $name))[0];
            if (str_contains($text, $name) || (mb_strlen($head) >= 5 && str_contains($text, $head))) {
                return true;
            }
        }

        return false;
    }
}
