<?php

namespace App\Ai;

use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        'jantar' => [['proteina'], ['carboidrato'], ['vegetal']],
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
     * Um plano válido a partir do prompt: escolhe alimentos permitidos por grupo (cozinha primeiro),
     * ajusta as proteínas para a meta de proteína e os carboidratos/frutas para a meta de kcal.
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
        /** @var list<array{id: int, grupo: string, kcal_100g: float, prot_100g: float, pantry: bool}> $foods */
        $foods = $prompt['alimentos_permitidos'] ?? [];
        usort($foods, fn (array $a, array $b) => [! $a['pantry'], $a['id']] <=> [! $b['pantry'], $b['id']]);
        $targetKcal = (float) ($prompt['metas_diarias']['kcal'] ?? 2000);
        $targetProtein = (float) ($prompt['metas_diarias']['proteina_g'] ?? 100);

        $used = [];
        $plan = [];
        foreach (self::MEALS as $slot => $wanted) {
            $items = [];
            foreach ($wanted as $groups) {
                $candidates = array_filter($foods, fn (array $f) => in_array($f['grupo'], $groups, true) && ! isset($items[$f['id']]));
                if ($candidates === []) {
                    continue;
                }
                usort($candidates, fn (array $a, array $b) => ($used[$a['id']] ?? 0) <=> ($used[$b['id']] ?? 0));
                $chosen = $candidates[0];
                $items[$chosen['id']] = ['food' => $chosen, 'grams' => 100.0];
                $used[$chosen['id']] = ($used[$chosen['id']] ?? 0) + 1;
            }
            if ($items === [] && $foods !== []) {
                $items[$foods[0]['id']] = ['food' => $foods[0], 'grams' => 100.0];
            }
            $plan[$slot] = $items;
        }

        $this->scale($plan, self::PROTEIN_GROUPS, 'prot_100g', $targetProtein * 1.02);
        $this->scale($plan, self::FILL_GROUPS, 'kcal_100g', $targetKcal);

        $meals = [];
        foreach ($plan as $slot => $items) {
            $meals[] = ['slot' => $slot, 'items' => array_values(array_map(
                fn (array $item) => ['food_id' => $item['food']['id'], 'grams' => $item['grams']],
                $items,
            ))];
        }

        return (string) json_encode(['meals' => $meals]);
    }

    /**
     * Escala os itens dos grupos dados para que o total do nutriente chegue ao alvo.
     *
     * @param  array<string, array<int, array{food: array<string, mixed>, grams: float}>>  $plan
     * @param  list<string>  $groups
     */
    private function scale(array &$plan, array $groups, string $key, float $target): void
    {
        $inGroups = 0.0;
        $others = 0.0;
        foreach ($plan as $items) {
            foreach ($items as $item) {
                $amount = (float) $item['food'][$key] * $item['grams'] / 100;
                in_array($item['food']['grupo'], $groups, true) ? $inGroups += $amount : $others += $amount;
            }
        }
        if ($inGroups <= 0) {
            return;
        }

        $factor = max(0.0, $target - $others) / $inGroups;
        foreach ($plan as &$items) {
            foreach ($items as &$item) {
                if (in_array($item['food']['grupo'], $groups, true)) {
                    $item['grams'] = max(5.0, min(450.0, round($item['grams'] * $factor / 5) * 5));
                }
            }
        }
    }
}
