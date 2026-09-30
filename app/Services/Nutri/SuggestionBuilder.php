<?php

namespace App\Services\Nutri;

use App\Models\DayMealItem;
use App\Models\User;
use App\Services\Days\DayMaterializer;
use Carbon\CarbonImmutable;

/** Perguntas prontas para o chat (sem IA) e a reserva de continuação do RN45. */
class SuggestionBuilder
{
    public function __construct(private readonly NutriContextBuilder $context, private readonly DayMaterializer $days) {}

    /**
     * @return list<array{id: string, question: string}> até 4
     */
    public function questions(User $user): array
    {
        $profile = $user->profile()->firstOrFail();
        $next = $this->context->nextMeal($user);
        if ($next === null) {
            return [
                ['id' => 'fechar-proteina', 'question' => 'Como fechar o dia se sobrou proteína?'],
                ['id' => 'amanha-cedo', 'question' => 'O que comer amanhã cedo?'],
            ];
        }

        $meal = mb_strtolower($next->name);
        $carb = $next->items->first(fn (DayMealItem $item) => $item->food->group === 'carboidrato');
        $protein = $next->items->sortByDesc(fn (DayMealItem $item) => $item->food->protein_per_100g * $item->grams)->first();
        $pantry = $user->pantryItems()->limit(3)->pluck('label')->map(fn ($l) => mb_strtolower((string) $l))->all();

        $questions = [];
        if ($carb !== null) {
            $questions[] = ['id' => 'trocar-carbo', 'question' => 'Posso trocar o '.mb_strtolower($carb->food->name).' por outra coisa?'];
        }
        if ($protein !== null) {
            $questions[] = ['id' => 'sem-proteina', 'question' => 'Não tenho '.mb_strtolower($protein->food->name).' em casa. O que uso no lugar?'];
        }
        if ($this->days->isTrainingDay($user, CarbonImmutable::today())) {
            $questions[] = ['id' => 'pre-treino', 'question' => 'O que comer antes do treino das '.substr((string) $profile->training_time, 0, 5).'?'];
        }
        if (count($pantry) === 3) {
            $questions[] = ['id' => 'montar', 'question' => "Monte um {$meal} com {$pantry[0]}, {$pantry[1]} e {$pantry[2]}"];
        }

        return array_slice($questions, 0, 4);
    }

    /**
     * RN45 — reserva quando a IA não manda sugestões aproveitáveis.
     *
     * @return list<string> até 3, cada uma ≤ 60
     */
    public function continuations(User $user): array
    {
        $profile = $user->profile()->firstOrFail();
        $next = $this->context->nextMeal($user);
        $options = [];
        if ($next !== null) {
            $options[] = 'E no '.mb_strtolower($next->name).'?';
        }
        if ($this->days->isTrainingDay($user, CarbonImmutable::today())) {
            $options[] = 'O que como antes do treino das '.substr((string) $profile->training_time, 0, 5).'?';
        }
        $options[] = 'Como fecho a proteína de hoje?';

        return array_values(array_filter(array_slice($options, 0, 3), fn (string $q) => mb_strlen($q) <= 60));
    }
}
