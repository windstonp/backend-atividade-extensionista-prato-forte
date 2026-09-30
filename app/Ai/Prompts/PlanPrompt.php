<?php

namespace App\Ai\Prompts;

/** Mensagens da geração de plano (integracao-ia.md §3.1). Mudou o texto? Suba VERSION. */
final class PlanPrompt
{
    public const VERSION = 1;

    public static function system(): string
    {
        return 'Você monta cardápios para pessoas que treinam em uma academia de bairro no Sul do Brasil. '
            .'Use SOMENTE os alimentos da lista fornecida, referenciados pelo `id`. '
            .'Monte 5 refeições (slots `cafe`, `lanche`, `almoco`, `pre-treino`, `jantar`) com comida simples, do dia a dia brasileiro, '
            .'que a pessoa já tem em casa (prefira itens com `pantry: true`). Quantidades em gramas. '
            .'Respeite a meta diária de calorias e proteína. Distribua a proteína ao longo do dia. '
            .'No pré-treino, prefira carboidrato de digestão rápida com pouca gordura. '
            .'Se o almoço é marmita, escolha itens que aguentem a manhã na bolsa. '
            .'Responda APENAS com JSON no formato indicado, sem texto fora do JSON.';
    }

    /** @param array<string, mixed> $inputs pessoa, metas_diarias, horarios, alimentos_permitidos (sem nome nem e-mail) */
    public static function user(array $inputs): string
    {
        return (string) json_encode(
            $inputs + ['formato_resposta' => ['meals' => [['slot' => 'cafe', 'items' => [['food_id' => 0, 'grams' => 0]]]]]],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /** @param list<string> $errors */
    public static function correction(array $errors): string
    {
        return "Corrija estes problemas e responda de novo só com o JSON:\n- ".implode("\n- ", $errors);
    }
}
