<?php

namespace App\Ai\Schemas;

/** Contrato da resposta da geração (integracao-ia.md §3.2). */
final class PlanResponse
{
    /**
     * @return list<array{slot: string, items: list<array{food_id: int, grams: float}>}>
     *
     * @throws InvalidAiResponse
     */
    public static function parse(string $content): array
    {
        $json = self::decode($content);
        if (! isset($json['meals']) || ! is_array($json['meals'])) {
            throw new InvalidAiResponse('A resposta precisa ter a lista "meals".');
        }

        $meals = [];
        foreach ($json['meals'] as $i => $meal) {
            if (! is_array($meal) || ! isset($meal['slot']) || ! is_string($meal['slot'])) {
                throw new InvalidAiResponse("A refeição {$i} precisa de um slot em texto.");
            }
            $items = [];
            foreach (is_array($meal['items'] ?? null) ? $meal['items'] : [] as $item) {
                if (! is_array($item) || ! is_numeric($item['food_id'] ?? null) || ! is_numeric($item['grams'] ?? null)) {
                    throw new InvalidAiResponse("Cada item de {$meal['slot']} precisa de food_id e grams numéricos.");
                }
                $items[] = ['food_id' => (int) $item['food_id'], 'grams' => (float) $item['grams']];
            }
            $meals[] = ['slot' => $meal['slot'], 'items' => $items];
        }

        return $meals;
    }

    /** @return array<string, mixed> */
    private static function decode(string $content): array
    {
        $json = json_decode($content, true);
        if (! is_array($json)) {
            // Texto em volta (```json …``` ou explicação): pega do primeiro "{" ao último "}".
            $start = strpos($content, '{');
            $end = strrpos($content, '}');
            $json = $start !== false && $end !== false ? json_decode(substr($content, $start, $end - $start + 1), true) : null;
        }
        if (! is_array($json)) {
            throw new InvalidAiResponse('A resposta não é um JSON válido.');
        }

        return $json;
    }
}
