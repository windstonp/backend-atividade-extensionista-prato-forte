<?php

namespace App\Ai;

use App\Ai\Prompts\PlanPrompt;
use App\Models\Food;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Assert;

/**
 * IA determinística para testes, E2E e demonstração (integracao-ia.md §6).
 * Roteiros por propósito; sem roteiro, o plano é montado com a lista permitida do próprio prompt.
 */
final class FakeAiClient implements AiClient
{
    /** Grupos que cada refeição tenta ter, em ordem. */
    private const MEALS = [
        'cafe' => [['proteina', 'laticinio'], ['carboidrato'], ['fruta']],
        'lanche' => [['fruta'], ['laticinio', 'proteina', 'leguminosa']],
        'almoco' => [['carboidrato'], ['leguminosa'], ['proteina'], ['vegetal']],
        'pre-treino' => [['carboidrato'], ['fruta']],
        'jantar' => [['proteina'], ['carboidrato'], ['leguminosa'], ['vegetal']],
    ];

    private const PROTEIN_GROUPS = ['proteina', 'laticinio', 'leguminosa'];

    private const FILL_GROUPS = ['carboidrato', 'fruta'];

    /** @var array<string, list<string|AiUnavailableException>> */
    private array $queued = [];

    /** @var list<array{messages: list<array{role: string, content: string}>, options: AiOptions}> */
    private array $sent = [];

    /** @param list<string> $failFirstPlanFor e-mails cuja primeira geração de plano falha (E2E-07) */
    public function __construct(private readonly array $failFirstPlanFor = []) {}

    public function queue(string $purpose, string $content): void
    {
        $this->queued[$purpose][] = $content;
    }

    public function failNext(string $purpose): void
    {
        $this->queued[$purpose][] = new AiUnavailableException('Falha roteirizada.');
    }

    public function sentCount(string $purpose): int
    {
        return count(array_filter($this->sent, fn (array $call) => $call['options']->purpose === $purpose));
    }

    /** @param (callable(list<array{role: string, content: string}>, AiOptions): mixed)|null $check */
    public function assertSent(string $purpose, ?callable $check = null): void
    {
        $calls = array_values(array_filter($this->sent, fn (array $call) => $call['options']->purpose === $purpose));
        Assert::assertNotEmpty($calls, "Nenhuma chamada à IA com o propósito {$purpose}.");
        foreach ($calls as $call) {
            if ($check !== null) {
                $check($call['messages'], $call['options']);
            }
        }
    }

    public function chat(array $messages, AiOptions $options): AiResult
    {
        $this->sent[] = ['messages' => $messages, 'options' => $options];

        $next = isset($this->queued[$options->purpose]) ? array_shift($this->queued[$options->purpose]) : null;
        if ($next instanceof AiUnavailableException) {
            throw $next;
        }
        if (is_string($next)) {
            return new AiResult($next, null, null, 1);
        }

        if ($options->purpose === 'plan') {
            if ($this->mustFailPlan($options->userId)) {
                throw new AiUnavailableException('Falha roteirizada (AI_FAKE_FAIL_PLAN_FOR).');
            }

            return new AiResult($this->defaultPlan($messages), null, null, 1);
        }

        if ($options->purpose === 'chat') {
            return new AiResult($this->defaultChat($messages), null, null, 1);
        }
        if ($options->purpose === 'summary') {
            return new AiResult($this->defaultSummary($messages), null, null, 1);
        }

        throw new LogicException("FakeAiClient sem roteiro para '{$options->purpose}'.");
    }

    /** E2E-07: a primeira geração de plano destas contas falha; a segunda passa. */
    private function mustFailPlan(?int $userId): bool
    {
        if ($userId === null || $this->failFirstPlanFor === []) {
            return false;
        }
        $email = User::whereKey($userId)->value('email');

        return in_array($email, $this->failFirstPlanFor, true) && DB::table('meal_plans')->where('user_id', $userId)->count() === 1;
    }

    /**
     * Um plano plausível a partir do prompt, refeição por refeição: cada uma recebe sua parte das kcal
     * (`distribuicao_kcal`) e da proteína; as porções ficam entre 0,5× e 2,5× a de costume (`porcao_g`) e,
     * quando não basta, entra mais um alimento do grupo. Almoço e jantar levam carboidrato de prato.
     *
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function defaultPlan(array $messages): string
    {
        $prompt = [];
        foreach ($messages as $message) {
            if ($message['role'] === 'user') {
                $prompt = json_decode($message['content'], true) ?: [];
                break;
            }
        }
        /** @var list<array{id: int, grupo: string, kcal_100g: float, prot_100g: float, pantry: bool, porcao_g?: float|null}> $foods */
        $foods = $prompt['alimentos_permitidos'] ?? [];
        usort($foods, fn (array $a, array $b) => [! $a['pantry'], $a['id']] <=> [! $b['pantry'], $b['id']]);
        $targetKcal = (float) ($prompt['metas_diarias']['kcal'] ?? 2000);
        $targetProtein = (float) ($prompt['metas_diarias']['proteina_g'] ?? 100);
        /** @var array<string, float> $shares */
        $shares = $prompt['distribuicao_kcal'] ?? PlanPrompt::DISTRIBUICAO_KCAL;

        $used = [];
        $meals = [];
        foreach (self::MEALS as $slot => $wanted) {
            $pick = function (array $groups, array $items) use ($foods, $slot, &$used): ?array {
                $grupos = array_count_values(array_map(fn (array $i) => $i['food']['grupo'], $items));
                $candidates = array_filter($foods, fn (array $f) => in_array($f['grupo'], $groups, true) && ! isset($items[$f['id']])
                    && ($f['grupo'] !== 'carboidrato' || ($grupos['carboidrato'] ?? 0) < 2)); // no máximo 2 carboidratos no prato
                // Carboidrato de prato no almoço e no jantar (arroz, batata: porção ≥ 100 g); de café no café e no pré-treino (pão, tapioca: < 100 g).
                $doMomento = match ($slot) {
                    'almoco', 'jantar' => fn (array $f) => $f['grupo'] !== 'carboidrato' || self::portion($f) >= 100,
                    'cafe', 'pre-treino' => fn (array $f) => $f['grupo'] !== 'carboidrato' || self::portion($f) < 100,
                    default => fn () => true,
                };
                $candidates = array_filter($candidates, $doMomento) ?: $candidates;
                if ($candidates === []) {
                    return null;
                }
                // Primeiro um grupo que ainda não está no prato; depois o alimento menos repetido no dia.
                usort($candidates, fn (array $a, array $b) => [isset($grupos[$a['grupo']]), $used[$a['id']] ?? 0] <=> [isset($grupos[$b['grupo']]), $used[$b['id']] ?? 0]);
                $used[$candidates[0]['id']] = ($used[$candidates[0]['id']] ?? 0) + 1;

                return $candidates[0];
            };

            /** @var array<int, array{food: array<string, mixed>, grams: float}> $items */
            $items = [];
            foreach ($wanted as $groups) {
                if ($food = $pick($groups, $items)) {
                    $items[$food['id']] = ['food' => $food, 'grams' => self::portion($food)];
                }
            }
            if ($items === [] && $foods !== []) {
                $items[$foods[0]['id']] = ['food' => $foods[0], 'grams' => self::portion($foods[0])];
            }

            $share = (float) ($shares[$slot] ?? 0.2);
            $this->fillMeal($items, self::PROTEIN_GROUPS, 'prot_100g', $targetProtein * 1.05 * $share, $pick);
            // No almoço e no jantar as calorias crescem no arroz e no feijão, não só num carboidrato.
            $fill = in_array($slot, ['almoco', 'jantar'], true) ? [...self::FILL_GROUPS, 'leguminosa'] : self::FILL_GROUPS;
            $this->fillMeal($items, $fill, 'kcal_100g', $targetKcal * $share, $pick);

            $meals[] = ['slot' => $slot, 'items' => array_values(array_map(
                fn (array $item) => ['food_id' => $item['food']['id'], 'grams' => $item['grams']],
                $items,
            ))];
        }

        return (string) json_encode(['meals' => $meals]);
    }

    /**
     * Porção de costume do alimento no prompt (100 g quando não vier).
     *
     * @param  array<string, mixed>  $food
     */
    private static function portion(array $food): float
    {
        return (float) ($food['porcao_g'] ?? 0) > 0 ? (float) $food['porcao_g'] : 100.0;
    }

    /**
     * Ajusta os itens dos grupos dados para o total do nutriente na refeição chegar ao alvo, com cada porção
     * entre 0,5× e 2,5× a de costume. Se ainda faltar, acrescenta outro alimento do grupo (até 6 itens).
     *
     * @param  array<int, array{food: array<string, mixed>, grams: float}>  $items
     * @param  list<string>  $groups
     */
    private function fillMeal(array &$items, array $groups, string $key, float $target, Closure $pick): void
    {
        $amount = fn (array $item) => (float) $item['food'][$key] * $item['grams'] / 100;
        for ($tentativa = 0; $tentativa < 3; $tentativa++) {
            $inGroups = 0.0;
            $others = 0.0;
            foreach ($items as $item) {
                in_array($item['food']['grupo'], $groups, true) ? $inGroups += $amount($item) : $others += $amount($item);
            }
            if ($inGroups > 0) {
                $factor = max(0.0, $target - $others) / $inGroups;
                foreach ($items as &$item) {
                    if (in_array($item['food']['grupo'], $groups, true)) {
                        $portion = self::portion($item['food']);
                        $item['grams'] = max(5.0, round(min(2.5 * $portion, max(0.5 * $portion, $item['grams'] * $factor)) / 5) * 5);
                    }
                }
                unset($item);
            }

            $total = array_sum(array_map($amount, $items));
            /** @var array<string, mixed>|null $extra */
            // Só falta de verdade (porções no teto) puxa outro alimento; o arredondamento a 5 g fica para o ajuste do dia.
            $extra = $total >= 0.9 * $target || count($items) >= 6 ? null : $pick($groups, $items);
            if ($extra === null) {
                return;
            }
            $items[(int) $extra['id']] = ['food' => $extra, 'grams' => self::portion($extra)];
        }
    }

    /**
     * Os cenários do `askNutri` do mock (integracao-ia.md §6), com `food_id` reais do contexto.
     *
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function defaultChat(array $messages): string
    {
        $context = [];
        foreach ($messages as $message) {
            if ($message['role'] === 'system' && str_starts_with($message['content'], 'Contexto de agora (JSON): ')) {
                $context = json_decode(substr($message['content'], strlen('Contexto de agora (JSON): ')), true) ?: [];
            }
        }
        $question = Str::lower(Str::ascii((string) end($messages)['content']));
        /** @var list<array{id: int, nome: string, grupo: string}> $allowed */
        $allowed = $context['alimentos_permitidos'] ?? [];
        /** @var list<array{slot: string, feita: bool, itens: list<array{food_id: int, nome: string}>}> $meals */
        $meals = $context['refeicoes_hoje'] ?? [];
        $groupOf = array_column($allowed, 'grupo', 'id');

        $swap = function (string $group, string $prefer) use ($allowed, $meals, $groupOf): ?array {
            foreach ($meals as $meal) {
                foreach ($meal['feita'] ? [] : $meal['itens'] as $item) {
                    if (($groupOf[$item['food_id']] ?? null) !== $group) {
                        continue;
                    }
                    $options = array_values(array_filter($allowed, fn (array $f) => $f['grupo'] === $group && $f['id'] !== $item['food_id']));
                    usort($options, fn (array $a, array $b) => [! str_contains(Str::lower(Str::ascii($a['nome'])), $prefer), $a['id']] <=> [! str_contains(Str::lower(Str::ascii($b['nome'])), $prefer), $b['id']]);

                    return $options === [] ? null : ['type' => 'substituir', 'slot' => $meal['slot'], 'from_food_id' => $item['food_id'], 'to_food_id' => $options[0]['id']];
                }
            }

            return null;
        };
        $first = fn (string $group, string $prefer = '') => collect($allowed)->where('grupo', $group)
            ->sortBy(fn (array $f) => [! str_contains(Str::lower(Str::ascii($f['nome'])), $prefer), $f['id']])->first();

        $answer = match (true) {
            str_contains($question, 'castanha') => $this->nutsAnswer($allowed, $swap('gordura', '')),
            str_contains($question, 'arroz') || str_contains($question, 'batata') => [
                'reply' => 'Pode. A batata-doce entra no lugar do arroz com carboidrato parecido e mais fibra.',
                'follow_up' => 'A batata-doce segura a fome por mais tempo até o treino.',
                'suggestions' => ['E no jantar, o que como?', 'Por que a batata segura mais a fome?'],
                'action' => $swap('carboidrato', 'batata'),
            ],
            str_contains($question, 'frango') => [
                'reply' => ($troca = $swap('proteina', 'ovo')) !== null
                    ? 'Sem frango, '.Str::lower((string) (array_column($allowed, 'nome', 'id')[$troca['to_food_id']] ?? 'outra proteína')).' cobre a proteína desse prato.'
                    : 'Sem frango, dá para usar outra proteína do seu plano.',
                'suggestions' => ['Quanto de proteína falta hoje?'],
                'action' => $troca,
            ],
            str_contains($question, 'jantar') || str_contains($question, 'ovo') || str_contains($question, 'brocolis') => [
                'reply' => 'Montei um jantar leve com o que costuma ter em casa.',
                'suggestions' => ['E se eu treinar à noite?', 'Posso trocar o brócolis?'],
                'action' => ['type' => 'aplicar-refeicao', 'slot' => 'jantar', 'items' => array_values(array_filter([
                    ($p = $first('proteina', 'ovo')) ? ['food_id' => $p['id'], 'grams' => 100] : null,
                    ($v = $first('vegetal', 'brocolis')) ? ['food_id' => $v['id'], 'grams' => 80] : null,
                    ($c = $first('carboidrato')) ? ['food_id' => $c['id'], 'grams' => 100] : null,
                ]))],
            ],
            str_contains($question, 'treino') => [
                'reply' => 'Uma hora antes do treino, prefira um carboidrato leve, como banana ou batata-doce.',
                'suggestions' => ['E depois do treino?'],
                'action' => null,
            ],
            default => [
                'reply' => 'Ainda não sei responder isso por aqui. Pergunte sobre as refeições de hoje.',
                'suggestions' => [],
                'action' => null,
            ],
        };

        return (string) json_encode($answer + ['follow_up' => null], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Castanha de caju só aparece para quem pode comer (RN17 vale também para o texto da IA falsa).
     *
     * @param  list<array{id: int, nome: string, grupo: string}>  $allowed
     * @param  array<string, mixed>|null  $fatSwap
     * @return array<string, mixed>
     */
    private function nutsAnswer(array $allowed, ?array $fatSwap): array
    {
        $caju = Food::where('slug', 'castanha-de-caju')->first();
        if ($caju === null || ! in_array($caju->id, array_column($allowed, 'id'), true)) {
            return ['reply' => 'Com a sua restrição, castanha fica de fora do seu plano. Posso sugerir outro lanche.', 'suggestions' => ['Monte um lanche para mim'], 'action' => null];
        }

        return [
            'reply' => 'Dá para pôr um punhado de castanha de caju no lanche.',
            'suggestions' => ['E no lanche da tarde?'],
            'action' => $fatSwap === null ? null : ['to_food_id' => $caju->id] + $fatSwap,
        ];
    }

    /** @param list<array{role: string, content: string}> $messages */
    private function defaultSummary(array $messages): string
    {
        $questions = array_column(array_filter($messages, fn (array $m) => $m['role'] === 'user'), 'content');

        return Str::limit('Conversou sobre: '.implode('; ', $questions), 600, '…');
    }
}
