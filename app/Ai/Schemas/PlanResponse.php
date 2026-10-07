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
        $json = self::unwrap(self::decode($content));
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
    /**
     * Modelos menores às vezes devolvem a lista solta (`[{slot…}]`) ou dentro de outra chave
     * (`{"plano": {"meals": …}}`): os dois viram `{"meals": …}`.
     *
     * @param  array<mixed>  $json
     * @return array<mixed>
     */
    private static function unwrap(array $json): array
    {
        if (self::isMealList($json)) {
            return ['meals' => $json];
        }
        if (! isset($json['meals']) && count($json) === 1) {
            $inner = reset($json);
            if (is_array($inner) && (isset($inner['meals']) || self::isMealList($inner))) {
                return self::unwrap($inner);
            }
        }

        return $json;
    }

    /**
     * Lista não vazia de objetos com `slot` (uma lista qualquer não vira plano).
     *
     * @param  array<mixed>  $json
     */
    private static function isMealList(array $json): bool
    {
        return $json !== [] && array_is_list($json) && is_array($json[0]) && isset($json[0]['slot']);
    }

    /** @return array<mixed> */
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
