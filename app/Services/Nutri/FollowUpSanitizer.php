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
        $forbidden = $this->forbiddenPhrases($user);
        $seen = [FoodFilter::normalize($question) => true];
        $kept = [];
        foreach ($suggestions as $suggestion) {
            $text = trim($suggestion);
            $key = FoodFilter::normalize($text);
            $length = mb_strlen($text);
            if ($length < 3 || $length > 60 || isset($seen[$key]) || $this->mentions(self::words($text), $forbidden)) {
                continue;
            }
            $seen[$key] = true;
            $kept[] = $text;
        }

        return array_slice($kept, 0, 3);
    }

    /**
     * Nomes e sinônimos dos alimentos proibidos e as "outras restrições", em palavras no singular.
     * Nome composto também barra pela primeira palavra ("castanha-do-pará" → "castanha"),
     * a menos que ela seja palavra de um alimento permitido ("arroz" de "arroz com…" continua livre).
     *
     * @return list<list<string>>
     */
    private function forbiddenPhrases(User $user): array
    {
        $allowed = $this->filter->allowedFor($user);
        $allowedWords = $allowed->flatMap(fn (Food $food) => [$food->name, ...$food->aliases])
            ->flatMap(fn (string $name) => self::words($name))->flip()->all();

        $names = Food::query()->whereNotIn('id', $allowed->keys())->get()
            ->flatMap(fn (Food $food) => [$food->name, ...$food->aliases])
            ->merge($user->profile->other_restrictions);

        $phrases = [];
        foreach ($names as $name) {
            $words = self::words((string) $name);
            if ($words === []) {
                continue;
            }
            $phrases[implode(' ', $words)] = $words;
            if (count($words) > 1 && mb_strlen($words[0]) >= 5 && ! isset($allowedWords[$words[0]])) {
                $phrases[$words[0]] = [$words[0]];
            }
        }

        return array_values($phrases);
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        $plain = (string) preg_replace('/[^a-z0-9]+/', ' ', FoodFilter::normalize($text));

        return array_values(array_filter(explode(' ', FoodFilter::singular(trim($plain))), fn (string $w) => $w !== ''));
    }

    /**
     * @param  list<string>  $words
     * @param  list<list<string>>  $phrases
     */
    private function mentions(array $words, array $phrases): bool
    {
        $haystack = ' '.implode(' ', $words).' ';
        foreach ($phrases as $phrase) {
            if (str_contains($haystack, ' '.implode(' ', $phrase).' ')) {
                return true;
            }
        }

        return false;
    }
}
