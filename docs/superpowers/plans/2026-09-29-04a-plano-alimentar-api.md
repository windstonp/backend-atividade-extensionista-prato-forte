# Plano 04A — Plano alimentar: API (geração com IA, dia, trocas) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar no backend o plano alimentar completo: geração híbrida validada com IA (D6), ativação, dia materializado, marcar refeição, trocas com desfazer e os efeitos de mudanças do perfil (RN21) — com `FakeAiClient` para testes, E2E e demonstração.

**Architecture:** Cálculos em classes puras (`MealScheduler`, `DayTotals`, `PortionFormatter`, `SubstitutionFinder`, `PortionAdjuster`, `PlanValidator`) testadas por tabela. A IA fica atrás de `AiClient` (OpenAI-compatível ou falsa), sempre embrulhada pelo `LoggingAiClient` (RN44). `PlanService` pede e ativa planos; `GeneratePlanJob` → `PlanGenerator` roda o pipeline (prompt → IA → parse → ajuste → validação → 2ª tentativa → persistência). `DayMaterializer` copia o plano para o dia; `DayService` é a única porta de escrita do dia (RN23). O front (telas) é o Plano 04B.

**Tech Stack:** Laravel 12, PHP 8.3, MySQL 8 (coluna gerada para o plano ativo), fila `database` (worker `queue` novo no compose), Pest 3, `Http::fake`, Larastan 6.

**Spec:** `specs/03-plano-alimentar/spec.md` e `specs/00-fundacao/integracao-ia.md` (§2, §3, §6), com `regras-de-negocio.md` (RN13–RN27, RN43, RN44), `modelo-de-dados.md` §4, §5, §9 (`ai_requests`) e `02-onboarding-perfil/spec.md` (RN21, `POST /onboarding/complete`). Caminhos relativos ao repo **backend**. Roteiro: `docs/superpowers/plans/2026-09-24-00-roteiro.md`.

**Onde rodar:** `/home/alvez/atividade-extensionista/backend`, `docker compose run --rm api …`. Branch `plano-04a-plano-alimentar` saindo de `plano-03-onboarding` (empilhada; nada foi integrado ainda).

## Decisões deste plano (rulings sobre a spec)

1. **O Plano 04 vira 04A (API) + 04B (telas).** A spec 03 tem geração com IA, dia, semana, trocas e cinco telas; num plano só seriam 20+ tarefas. Cada metade termina testada. O roteiro é atualizado na Task 11.
2. **Falha forçada no E2E (E2E-07):** em vez de `AI_FAKE_FAIL_FIRST_PLAN=true` (que derrubaria a primeira geração de *qualquer* usuário no worker), `AI_FAKE_FAIL_PLAN_FOR=<e-mails>` faz o `FakeAiClient` falhar a **primeira** geração só daquelas contas — determinístico entre processos.
3. **Pedido de plano concorrente por mudança de restrição (RN21 🟡 "enfileirado após ele"):** a mudança de restrição sempre cria um plano novo, mesmo com outro gerando; ao ficar pronto, um plano só é ativado se for o **mais novo** do usuário — um mais antigo que termine depois vira `failed` com `SUPERSEDED`. Assim uma restrição nova nunca é perdida.
4. **`leguminosa` troca pelo macro proteína e `bebida` por kcal** (RN25 não lista esses grupos).
5. **`ai_requests.status`**: o logger grava `ok`/`error`/`timeout`; `invalid` (resposta fora do contrato) fica sem uso até alguém precisar dessa métrica.
6. **Onde a IA é chamada, o `inputs` do plano guarda o snapshot** (perfil, metas, horários, ids permitidos) — sem nome nem e-mail (minimização).
7. **Porções em múltiplos de 5 g** (ajuste de RN18, trocas de RN25): combina com medida caseira e deixa os números do cardápio legíveis.

## Global Constraints

- RN14 horários: café = acorda + 40 min; almoço 12:30; lanche = meio entre café e almoço, para cima à meia hora; pré-treino = treino − 1h30 (ou − 45 min se cair antes de café + 2 h; sem dias de treino: 16:00); jantar = treino + 1h30 se o treino começa às 16:00 ou depois, senão dorme − 2h30; treino cedo (até 2 h depois de acordar): pré-treino = acorda + 10 min e café = treino + 1h15; nada antes de acordar nem depois de dorme − 1 h; colisão (< 1h30) empurra em passos de 30 min; tudo a 5 min.
- RN15: sempre 5 refeições, ordenadas por horário; nomes "Café da manhã", "Lanche da manhã", "Almoço", "Pré-treino", "Jantar"; sem treino no dia, `pre-treino` aparece como "Lanche da tarde"; jantar depois do treino tem a nota "Depois do treino das {H}h".
- RN16 permitidos = catálogo ativo − restrições − "outras restrições" (nome/sinônimo sem acento, minúsculo) − "não curto"; cozinha dá prioridade, não exclusividade.
- RN17: nenhum alimento fora de RN16 é persistido em plano, dia ou troca.
- RN18: JSON no contrato; 5 slots sem repetição; 1–6 itens; itens permitidos com 5–600 g; depois do ajuste (escala uniforme se desvio ≤ 25%), kcal ±10% da meta e proteína ≥ 90%; 1 nova tentativa com a lista de erros; depois, `failed` `AI_INVALID_RESPONSE`.
- RN19: um plano `pending`/`generating` por usuário (409 `PLAN_ALREADY_GENERATING`, `details.plan_id`); 5 gerações por dia em `POST /plans` (429); `generating` há mais de 3 min → `failed` `TIMEOUT`.
- RN20: plano novo pronto vira o único ativo; hoje, refeições não feitas vêm do novo plano, feitas ficam.
- RN22–RN27 conforme a spec (hoje materializa uma vez; futuro até +6 é prévia; passado até −90 é histórico; só hoje é editável; totais kcal inteiro/macros 1 casa; trocas até 4 opções ±35% kcal, 0,5–2× porção típica; desfazer a última alteração em até 15 min).
- RN43: recurso de outro usuário → **404**.
- RN44: nenhum conteúdo de prompt/resposta em log ou banco; chave da IA só no ambiente (`AI_API_KEY`), nunca no repo.
- Toda tabela nova com `user_id` entra no dataset de `tests/Feature/Auth/DeleteAccountTest.php`.
- Mensagens de erro via `ErrorCode` (formato único do Plano 01).

## Review Focus

1. **Alergia nunca chega ao prato** — IA devolve castanha na 1ª tentativa, alimento que só aparece por sinônimo em "outras restrições" ("camarão" em `aliases`), troca pedida com `food_id` forjado → nada disso é gravado (Tasks 2, 5, 9: `AllergyInvariantTest`).
2. **Duas gerações ao mesmo tempo** (toque duplo em "Refazer", restrição mudando durante uma geração) → um só plano gerando, ou o mais novo vence; nunca dois ativos (Tasks 5, 6, 10).
3. **Virada do dia e fuso** (23:59 → 00:00 em São Paulo; servidor em UTC) → "hoje" é sempre o de São Paulo; ontem vira somente leitura (Task 7, `DayTest` com `travelTo`).
4. **Materializar duas vezes** (duas abas pedindo `/days/today` juntas) → uma linha por slot, sem 500 (Task 7).
5. **Desfazer fora de ordem** (trocar A, trocar B, desfazer, desfazer; ou desfazer depois de 16 min) → cada desfazer volta exatamente um passo, e o fora de prazo responde `NOTHING_TO_UNDO` (Task 9).

---
### Task 1: Enums do plano e cálculos puros do dia — `MealScheduler` (RN14), `DayTotals` (RN24), `PortionFormatter`

**Files:**
- Create: `app/Enums/{MealSlot,PlanStatus,ItemSource,DayChangeType,PlanEffect}.php`
- Create: `app/Services/Nutrition/{MealScheduler,DayTotals,PortionFormatter}.php`
- Test: `tests/Unit/Nutrition/{MealSchedulerTest,DayTotalsTest,PortionFormatterTest}.php`

**Interfaces:**
- Produces:
  - `MealSlot` (`Cafe='cafe'`, `Lanche='lanche'`, `Almoco='almoco'`, `PreTreino='pre-treino'`, `Jantar='jantar'`; `label()`), `PlanStatus` (`Pending`, `Generating`, `Ready`, `Failed`; `isBusy()`), `ItemSource` (`Plan`, `Manual`, `Nutri`), `DayChangeType` (`Swap='swap'`, `ApplyMeal='apply_meal'`), `PlanEffect` (`None='none'`, `RegenerationSuggested='regeneration_suggested'`, `RegenerationStarted='regeneration_started'`, `TimesUpdated='times_updated'`).
  - `MealScheduler::schedule(string $wake, string $training, string $sleep, bool $hasTrainingDays): array<string, string>` — slot → "HH:MM", já em ordem de horário.
  - `DayTotals::item(Food, float $grams): Macros` (`array{calories: int, protein: float, carbs: float, fat: float}`), `DayTotals::sum(list<Macros>): Macros`, `DayTotals::remaining(Macros $planned, Macros $consumed): Macros`.
  - `PortionFormatter::format(float $grams, ?string $unit, ?string $unitPlural, ?float $unitGrams): string` e `forFood(Food, float $grams): string`.

- [ ] **Step 1: Branch e testes que devem falhar**

```bash
cd /home/alvez/atividade-extensionista/backend
git switch plano-03-onboarding && git switch -c plano-04a-plano-alimentar
```

`tests/Unit/Nutrition/MealSchedulerTest.php`:
```php
<?php

use App\Services\Nutrition\MealScheduler;

it('calcula os horários das refeições (RN14)', function (string $wake, string $training, string $sleep, bool $days, array $expected) {
    expect((new MealScheduler)->schedule($wake, $training, $sleep, $days))->toBe($expected);
})->with([
    'mock: acorda 06:20, treina 19:00 (CA04)' => ['06:20', '19:00', '23:00', true,
        ['cafe' => '07:00', 'lanche' => '10:00', 'almoco' => '12:30', 'pre-treino' => '17:30', 'jantar' => '20:30']],
    'treino cedo: pré-treino ao acordar e café depois do treino' => ['05:30', '06:30', '22:00', true,
        ['pre-treino' => '05:40', 'cafe' => '07:45', 'lanche' => '10:30', 'almoco' => '12:30', 'jantar' => '19:30']],
    'sem dias de treino: pré-treino às 16:00' => ['06:20', '19:00', '23:00', false,
        ['cafe' => '07:00', 'lanche' => '10:00', 'almoco' => '12:30', 'pre-treino' => '16:00', 'jantar' => '20:30']],
    'pré-treino perto do café e colisões empurradas de 30 em 30 min' => ['09:00', '12:00', '23:30', true,
        ['cafe' => '09:40', 'pre-treino' => '11:15', 'lanche' => '13:00', 'almoco' => '14:30', 'jantar' => '21:00']],
    'dorme depois da meia-noite' => ['10:00', '23:00', '01:30', true,
        ['cafe' => '10:40', 'lanche' => '12:30', 'almoco' => '14:00', 'pre-treino' => '21:30', 'jantar' => '00:30']],
    'arredonda a 5 min' => ['06:23', '18:10', '22:40', true,
        ['cafe' => '07:05', 'lanche' => '10:00', 'almoco' => '12:30', 'pre-treino' => '16:40', 'jantar' => '19:40']],
]);
```

`tests/Unit/Nutrition/DayTotalsTest.php`:
```php
<?php

use App\Models\Food;
use App\Services\Nutrition\DayTotals;

function food(float $kcal, float $protein, float $carbs, float $fat): Food
{
    return new Food(['kcal_per_100g' => $kcal, 'protein_per_100g' => $protein, 'carbs_per_100g' => $carbs, 'fat_per_100g' => $fat]);
}

it('calcula o item pela porção: kcal inteira, macros com 1 casa (RN24)', function () {
    expect(DayTotals::item(food(159, 32.0, 0.0, 2.5), 120))->toBe(['calories' => 191, 'protein' => 38.4, 'carbs' => 0.0, 'fat' => 3.0])
        ->and(DayTotals::item(food(128, 2.5, 28.1, 0.2), 100))->toBe(['calories' => 128, 'protein' => 2.5, 'carbs' => 28.1, 'fat' => 0.2]);
});

it('soma partes e calcula o restante sem ficar negativo', function () {
    $planejado = DayTotals::sum([
        ['calories' => 191, 'protein' => 38.4, 'carbs' => 0.0, 'fat' => 3.0],
        ['calories' => 128, 'protein' => 2.5, 'carbs' => 28.1, 'fat' => 0.2],
    ]);
    $consumido = ['calories' => 400, 'protein' => 10.0, 'carbs' => 30.0, 'fat' => 1.0];

    expect($planejado)->toBe(['calories' => 319, 'protein' => 40.9, 'carbs' => 28.1, 'fat' => 3.2])
        ->and(DayTotals::remaining($planejado, $consumido))->toBe(['calories' => 0, 'protein' => 30.9, 'carbs' => 0.0, 'fat' => 2.2])
        ->and(DayTotals::sum([]))->toBe(['calories' => 0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0]);
});
```

`tests/Unit/Nutrition/PortionFormatterTest.php`:
```php
<?php

use App\Services\Nutrition\PortionFormatter;

it('escreve a porção em gramas e em medida caseira', function (float $grams, ?string $unit, ?string $plural, ?float $unitGrams, string $expected) {
    expect((new PortionFormatter)->format($grams, $unit, $plural, $unitGrams))->toBe($expected);
})->with([
    'plural' => [150, 'colher de sopa', 'colheres de sopa', 25, '150 g, mais ou menos 6 colheres de sopa'],
    'singular' => [60, 'unidade', 'unidades', 60, '60 g, mais ou menos 1 unidade'],
    'meia medida' => [90, 'unidade', 'unidades', 60, '90 g, mais ou menos 1,5 unidades'],
    'menos de uma medida: só gramas' => [20, 'unidade', 'unidades', 60, '20 g'],
    'sem medida caseira' => [100, null, null, null, '100 g'],
    'gramas quebradas' => [58.5, null, null, null, '58,5 g'],
]);
```

Run: `docker compose run --rm api php artisan test tests/Unit/Nutrition`
Expected: FAIL — `Class "App\Services\Nutrition\MealScheduler" not found` (e os outros dois).

- [ ] **Step 2: Implementar**

`app/Enums/MealSlot.php`:
```php
<?php

namespace App\Enums;

/** As 5 refeições do plano (RN15). A ordem dos cases é a ordem padrão do dia. */
enum MealSlot: string
{
    case Cafe = 'cafe';
    case Lanche = 'lanche';
    case Almoco = 'almoco';
    case PreTreino = 'pre-treino';
    case Jantar = 'jantar';

    public function label(): string
    {
        return match ($this) {
            self::Cafe => 'Café da manhã',
            self::Lanche => 'Lanche da manhã',
            self::Almoco => 'Almoço',
            self::PreTreino => 'Pré-treino',
            self::Jantar => 'Jantar',
        };
    }
}
```

`app/Enums/PlanStatus.php`:
```php
<?php

namespace App\Enums;

enum PlanStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';

    /** Ainda vai virar `ready` ou `failed` (RN19: só um por usuário). */
    public function isBusy(): bool
    {
        return $this === self::Pending || $this === self::Generating;
    }
}
```

`app/Enums/ItemSource.php`:
```php
<?php

namespace App\Enums;

/** De onde veio o item do dia: do plano, de uma troca manual ou do Nutri. */
enum ItemSource: string
{
    case Plan = 'plan';
    case Manual = 'manual';
    case Nutri = 'nutri';
}
```

`app/Enums/DayChangeType.php`:
```php
<?php

namespace App\Enums;

/** Alteração de conteúdo do dia que pode ser desfeita (RN27). */
enum DayChangeType: string
{
    case Swap = 'swap';
    case ApplyMeal = 'apply_meal';
}
```

`app/Enums/PlanEffect.php`:
```php
<?php

namespace App\Enums;

/** Efeito de uma mudança do perfil sobre o plano (RN21). */
enum PlanEffect: string
{
    case None = 'none';
    case RegenerationSuggested = 'regeneration_suggested';
    case RegenerationStarted = 'regeneration_started';
    case TimesUpdated = 'times_updated';
}
```

`app/Services/Nutrition/MealScheduler.php`:
```php
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
```

`app/Services/Nutrition/DayTotals.php`:
```php
<?php

namespace App\Services\Nutrition;

use App\Models\Food;

/**
 * RN24 — macros de itens, refeições e do dia. Puro (só lê os valores do alimento).
 *
 * @phpstan-type Macros array{calories: int, protein: float, carbs: float, fat: float}
 */
final class DayTotals
{
    /**
     * Macros de uma porção: kcal inteira, macros com 1 casa.
     *
     * @return Macros
     */
    public static function item(Food $food, float $grams): array
    {
        $factor = $grams / 100;

        return [
            'calories' => (int) round($food->kcal_per_100g * $factor),
            'protein' => round($food->protein_per_100g * $factor, 1),
            'carbs' => round($food->carbs_per_100g * $factor, 1),
            'fat' => round($food->fat_per_100g * $factor, 1),
        ];
    }

    /**
     * @param  list<Macros>  $parts
     * @return Macros
     */
    public static function sum(array $parts): array
    {
        $total = ['calories' => 0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0];
        foreach ($parts as $part) {
            $total['calories'] += $part['calories'];
            $total['protein'] += $part['protein'];
            $total['carbs'] += $part['carbs'];
            $total['fat'] += $part['fat'];
        }

        return self::rounded($total);
    }

    /**
     * Restante = max(0, planejado − consumido).
     *
     * @param  Macros  $planned
     * @param  Macros  $consumed
     * @return Macros
     */
    public static function remaining(array $planned, array $consumed): array
    {
        return self::rounded([
            'calories' => max(0, $planned['calories'] - $consumed['calories']),
            'protein' => max(0.0, $planned['protein'] - $consumed['protein']),
            'carbs' => max(0.0, $planned['carbs'] - $consumed['carbs']),
            'fat' => max(0.0, $planned['fat'] - $consumed['fat']),
        ]);
    }

    /**
     * @param  array{calories: int, protein: float, carbs: float, fat: float}  $macros
     * @return Macros
     */
    private static function rounded(array $macros): array
    {
        return [
            'calories' => $macros['calories'],
            'protein' => round($macros['protein'], 1),
            'carbs' => round($macros['carbs'], 1),
            'fat' => round($macros['fat'], 1),
        ];
    }
}
```

`app/Services/Nutrition/PortionFormatter.php`:
```php
<?php

namespace App\Services\Nutrition;

use App\Models\Food;

/** "150 g, mais ou menos 6 colheres de sopa" — gramas e medida caseira (meia em meia). Puro. */
final class PortionFormatter
{
    public function forFood(Food $food, float $grams): string
    {
        return $this->format($grams, $food->unit_label, $food->unit_label_plural, $food->unit_grams);
    }

    public function format(float $grams, ?string $unit, ?string $unitPlural, ?float $unitGrams): string
    {
        $text = $this->number($grams).' g';
        if ($unit === null || $unitGrams === null || $unitGrams <= 0) {
            return $text;
        }

        $count = round($grams / $unitGrams * 2) / 2;
        if ($count < 1) {
            return $text;
        }

        $name = $count == 1.0 ? $unit : ($unitPlural ?? $unit);

        return "{$text}, mais ou menos {$this->number($count)} {$name}";
    }

    private function number(float $value): string
    {
        return floor($value) == $value ? (string) (int) $value : str_replace('.', ',', (string) round($value, 1));
    }
}
```

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Unit/Nutrition`
Expected: PASS.

- [ ] **Step 4: Lint e commit**

Run: `docker compose run --rm --no-deps api composer lint`
Expected: OK.

```bash
git add -A && git commit -m "feat(plano): horários das refeições (RN14), totais do dia (RN24) e porção caseira

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 2: Alimentos permitidos (`FoodFilter`, RN16) e opções de troca (`SubstitutionFinder`, RN25)

**Files:**
- Create: `app/Services/Foods/{FoodFilter,SubstitutionFinder,SubstitutionOption}.php`
- Test: `tests/Feature/Foods/FoodFilterTest.php`, `tests/Feature/Foods/SubstitutionFinderTest.php`

**Interfaces:**
- Consumes: catálogo e relações do usuário (Plano 03); `DayTotals` (Task 1).
- Produces:
  - `FoodFilter::allowedFor(User): Collection<int, Food>` (chave = id), `pantryFoodIds(User): list<int>`, `isAllowed(User, int $foodId): bool`, `static normalize(string): string` (minúsculas, sem acento).
  - `SubstitutionFinder::find(Food $original, float $grams, Collection<int, Food> $allowed, list<int> $pantryFoodIds): list<SubstitutionOption>`.
  - `final readonly class SubstitutionOption { Food $food; float $grams; array $macros; int $calorieDelta; bool $inPantry }`.

- [ ] **Step 1: Testes que devem falhar**

`tests/Feature/Foods/FoodFilterTest.php`:
```php
<?php

use App\Models\Food;
use App\Models\PantryItem;
use App\Models\Restriction;
use App\Models\User;
use App\Services\Foods\FoodFilter;

beforeEach(fn () => seedCatalog());

function slugsPermitidos(User $user): array
{
    return app(FoodFilter::class)->allowedFor($user)->pluck('slug')->all();
}

it('tira os alimentos das restrições marcadas (RN16)', function () {
    $user = User::factory()->onboarded()->create();
    $user->restrictions()->attach(Restriction::where('slug', 'castanhas')->sole());

    expect(slugsPermitidos($user))
        ->not->toContain('amendoim-torrado', 'pasta-de-amendoim', 'castanha-do-para', 'castanha-de-caju')
        ->toContain('arroz-branco-cozido', 'frango-grelhado');
});

it('tira pelo nome ou sinônimo de "outras restrições", sem ligar para acento e maiúscula', function () {
    $user = User::factory()->onboarded()->create();
    $user->profile->update(['other_restrictions' => ['Camarao', 'MANDIOCA']]);

    expect(slugsPermitidos($user))->not->toContain('camarao-cozido', 'aipim-cozido')->toContain('batata-cozida');
});

it('tira o que a pessoa prefere não ver', function () {
    $user = User::factory()->onboarded()->create();
    $user->dislikedFoods()->attach(Food::where('slug', 'jilo')->sole());

    expect(slugsPermitidos($user))->not->toContain('jilo')->toContain('beterraba');
});

it('"nada de origem animal" tira ovo, leite e mel e mantém o tofu', function () {
    $user = User::factory()->onboarded()->create();
    $user->restrictions()->attach(Restriction::where('slug', 'sem-animal')->sole());

    expect(slugsPermitidos($user))->not->toContain('ovos-cozidos', 'leite-integral', 'mel', 'frango-grelhado')->toContain('tofu', 'feijao-carioca');
});

it('dá os alimentos ligados à cozinha, para priorizar', function () {
    $user = User::factory()->onboarded()->create();
    $user->pantryItems()->attach(PantryItem::where('slug', 'arroz-e-feijao')->sole());

    $slugs = Food::whereIn('id', app(FoodFilter::class)->pantryFoodIds($user))->orderBy('slug')->pluck('slug')->all();

    expect($slugs)->toBe(['arroz-branco-cozido', 'arroz-integral', 'feijao-carioca', 'feijao-preto']);
});

it('responde se um alimento pode aparecer', function () {
    $user = User::factory()->onboarded()->create();
    $user->restrictions()->attach(Restriction::where('slug', 'castanhas')->sole());
    $filtro = app(FoodFilter::class);

    expect($filtro->isAllowed($user, Food::where('slug', 'castanha-de-caju')->value('id')))->toBeFalse()
        ->and($filtro->isAllowed($user, Food::where('slug', 'banana')->value('id')))->toBeTrue();
});
```

`tests/Feature/Foods/SubstitutionFinderTest.php`:
```php
<?php

use App\Models\Food;
use App\Services\Foods\SubstitutionFinder;
use App\Services\Foods\SubstitutionOption;

beforeEach(fn () => seedCatalog());

/** @return array<int, Food> */
function catalogo(array $sem = []): Illuminate\Support\Collection
{
    return Food::whereNotIn('slug', $sem)->orderBy('id')->get()->keyBy('id');
}

function resumo(array $opcoes): array
{
    return array_map(fn (SubstitutionOption $o) => [$o->food->slug, $o->grams, $o->calorieDelta, $o->inPantry], $opcoes);
}

it('troca o arroz por carboidratos, com porção pelo carboidrato e a cozinha primeiro (RN25)', function () {
    $arroz = Food::where('slug', 'arroz-branco-cozido')->sole();
    $cozinha = Food::whereIn('slug', ['batata-doce-cozida', 'tapioca'])->pluck('id')->all();

    $opcoes = (new SubstitutionFinder)->find($arroz, 150, catalogo(), $cozinha);

    // arroz 150 g: 192 kcal, 42,2 g de carboidrato
    expect(resumo($opcoes))->toBe([
        ['batata-doce-cozida', 230.0, -15, true],
        ['tapioca', 70.0, -24, true],
        ['cuscuz-de-milho', 165.0, -6, false],
        ['arroz-integral', 165.0, 13, false],
    ]);
});

it('troca proteína pela proteína e nunca oferece o que foi filtrado (CA08)', function () {
    $frango = Food::where('slug', 'frango-grelhado')->sole();

    $opcoes = (new SubstitutionFinder)->find($frango, 120, catalogo(sem: ['camarao-cozido']), []);

    expect(resumo($opcoes))->toBe([
        ['frango-desfiado', 120.0, 5, false],
        ['peixe-assado', 145.0, -14, false],
        ['patinho-moido', 105.0, 39, false],
        ['tofu', 200.0, -39, false],
    ]);
});

it('fruta troca por kcal e devolve no máximo 4', function () {
    $banana = Food::where('slug', 'banana')->sole();

    $opcoes = (new SubstitutionFinder)->find($banana, 60, catalogo(), []);

    expect($opcoes)->toHaveCount(4)
        ->and(array_map(fn (SubstitutionOption $o) => $o->food->group, $opcoes))->each->toBe('fruta')
        ->and(array_map(fn (SubstitutionOption $o) => abs($o->calorieDelta) <= 0.35 * 59, $opcoes))->each->toBeTrue();
});

it('lista vazia quando não há candidato do grupo', function () {
    $cafe = Food::where('slug', 'cafe-sem-acucar')->sole();

    expect((new SubstitutionFinder)->find($cafe, 100, catalogo(), []))->toBe([]);
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Foods`
Expected: FAIL — `Class "App\Services\Foods\FoodFilter" not found`.

- [ ] **Step 2: Implementar**

`app/Services/Foods/FoodFilter.php`:
```php
<?php

namespace App\Services\Foods;

use App\Models\Food;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** RN16 — única porta de entrada de alimentos para geração, trocas e ações do Nutri. */
class FoodFilter
{
    /**
     * Catálogo ativo − restrições − "outras restrições" (nome ou sinônimo) − "não curto".
     *
     * @return Collection<int, Food> chave = id
     */
    public function allowedFor(User $user): Collection
    {
        $terms = array_values(array_filter(array_map(self::normalize(...), $user->profile->other_restrictions)));

        return Food::query()
            ->where('is_active', true)
            ->whereDoesntHave('restrictions', fn ($query) => $query->whereIn('restrictions.id', $user->restrictions()->pluck('restrictions.id')))
            ->whereNotIn('id', $user->dislikedFoods()->pluck('foods.id'))
            ->orderBy('id')
            ->get()
            ->reject(fn (Food $food) => $this->matchesAny($food, $terms))
            ->keyBy('id');
    }

    /**
     * Alimentos ligados aos itens da cozinha do usuário: prioridade, não exclusividade.
     *
     * @return list<int>
     */
    public function pantryFoodIds(User $user): array
    {
        return DB::table('food_pantry_item')
            ->whereIn('pantry_item_id', $user->pantryItems()->pluck('pantry_items.id'))
            ->distinct()
            ->orderBy('food_id')
            ->pluck('food_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function isAllowed(User $user, int $foodId): bool
    {
        return $this->allowedFor($user)->has($foodId);
    }

    public static function normalize(string $text): string
    {
        return Str::lower(Str::ascii(trim($text)));
    }

    /** @param list<string> $terms */
    private function matchesAny(Food $food, array $terms): bool
    {
        if ($terms === []) {
            return false;
        }

        $names = array_map(self::normalize(...), [$food->name, ...$food->aliases]);
        foreach ($terms as $term) {
            foreach ($names as $name) {
                if (str_contains($name, $term)) {
                    return true;
                }
            }
        }

        return false;
    }
}
```

`app/Services/Foods/SubstitutionOption.php`:
```php
<?php

namespace App\Services\Foods;

use App\Models\Food;

final readonly class SubstitutionOption
{
    /** @param array{calories: int, protein: float, carbs: float, fat: float} $macros */
    public function __construct(
        public Food $food,
        public float $grams,
        public array $macros,
        public int $calorieDelta,
        public bool $inPantry,
    ) {}
}
```

`app/Services/Foods/SubstitutionFinder.php`:
```php
<?php

namespace App\Services\Foods;

use App\Models\Food;
use App\Services\Nutrition\DayTotals;
use Illuminate\Support\Collection;

/** RN25 — opções de troca de um item. Puro sobre as coleções recebidas. */
final class SubstitutionFinder
{
    private const MAX_OPTIONS = 4;

    private const MAX_CALORIE_DIFF = 0.35;

    /**
     * Mesmo grupo, porção equivalente pelo macro principal (0,5–2× a porção típica, a 5 g),
     * ±35% das kcal; cozinha primeiro, depois menor diferença de kcal; até 4.
     *
     * @param  Collection<int, Food>  $allowed  alimentos permitidos (FoodFilter)
     * @param  list<int>  $pantryFoodIds
     * @return list<SubstitutionOption>
     */
    public function find(Food $original, float $grams, Collection $allowed, array $pantryFoodIds): array
    {
        $before = DayTotals::item($original, $grams);
        $macro = $this->mainMacro($original->group);
        $target = $macro === 'calories' ? (float) $before['calories'] : $before[$macro];

        $options = [];
        foreach ($allowed as $candidate) {
            if ($candidate->id === $original->id || $candidate->group !== $original->group) {
                continue;
            }
            $per100 = $this->per100($candidate, $macro);
            if ($per100 <= 0) {
                continue;
            }

            $portion = min(max($target / $per100 * 100, 0.5 * $candidate->typical_portion_g), 2 * $candidate->typical_portion_g);
            $portion = max(5.0, min(600.0, round($portion / 5) * 5));
            $after = DayTotals::item($candidate, $portion);
            $delta = $after['calories'] - $before['calories'];
            if (abs($delta) > self::MAX_CALORIE_DIFF * $before['calories']) {
                continue;
            }

            $options[] = new SubstitutionOption($candidate, (float) $portion, $after, $delta, in_array($candidate->id, $pantryFoodIds, true));
        }

        usort($options, fn (SubstitutionOption $a, SubstitutionOption $b) => [! $a->inPantry, abs($a->calorieDelta), $a->food->name]
            <=> [! $b->inPantry, abs($b->calorieDelta), $b->food->name]);

        return array_slice($options, 0, self::MAX_OPTIONS);
    }

    /** Carboidrato troca por carboidrato; proteínas, laticínios e leguminosas por proteína; gorduras por gordura; o resto por kcal. */
    private function mainMacro(string $group): string
    {
        return match ($group) {
            'carboidrato' => 'carbs',
            'proteina', 'laticinio', 'leguminosa' => 'protein',
            'gordura' => 'fat',
            default => 'calories',
        };
    }

    private function per100(Food $food, string $macro): float
    {
        return match ($macro) {
            'carbs' => $food->carbs_per_100g,
            'protein' => $food->protein_per_100g,
            'fat' => $food->fat_per_100g,
            default => $food->kcal_per_100g,
        };
    }
}
```

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Feature/Foods`
Expected: PASS.

- [ ] **Step 4: Lint e commit**

Run: `docker compose run --rm --no-deps api composer lint`
Expected: OK.

```bash
git add -A && git commit -m "feat(alimentos): filtro de alimentos permitidos (RN16) e opções de troca (RN25)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 3: Camada de IA — `AiClient`, cliente OpenAI-compatível, `FakeAiClient` e log sem conteúdo (RN44)

**Files:**
- Create: `app/Ai/{AiClient,AiOptions,AiResult,AiUnavailableException,OpenAiCompatibleClient,FakeAiClient,LoggingAiClient}.php`
- Create: `database/migrations/2026_09_30_000100_create_ai_requests_table.php`, `app/Models/AiRequest.php`
- Modify: `config/services.php`, `app/Providers/AppServiceProvider.php`, `phpunit.xml`, `.env.example`, `tests/Pest.php`, `tests/Feature/Auth/DeleteAccountTest.php`
- Test: `tests/Feature/Ai/{OpenAiCompatibleClientTest,LoggingAiClientTest,FakeAiClientTest}.php`

**Interfaces:**
- Produces:
  - `interface AiClient { chat(list<array{role: string, content: string}> $messages, AiOptions $options): AiResult }`.
  - `final readonly class AiOptions(string $purpose, string $model, int $maxTokens, bool $json = false, float $temperature = 0.4, ?int $userId = null)`; `final readonly class AiResult(string $content, ?int $promptTokens, ?int $completionTokens, int $durationMs)`.
  - `AiUnavailableException` (rede, 5xx, timeout, sem chave) — `bool $timeout`.
  - `FakeAiClient`: `queue(purpose, content)`, `failNext(purpose)`, `assertSent(purpose, ?callable)`, `sentCount(purpose)`; sem roteiro, `plan` devolve um plano montado a partir da lista permitida do prompt; `AI_FAKE_FAIL_PLAN_FOR` (e-mails) falha a 1ª geração daquelas contas.
  - Binding: `AiClient` → `LoggingAiClient(OpenAiCompatibleClient | FakeAiClient)` por `config('services.ai.driver')` (`openai` | `fake`, padrão `fake`); `FakeAiClient` é singleton. Helper de teste `fakeAi(): FakeAiClient`.
  - Tabela `ai_requests` (sem conteúdo) e model `AiRequest`.

- [ ] **Step 1: Configuração e testes que devem falhar**

`config/services.php` — acrescentar antes do `];` final:
```php
    // IA generativa (integracao-ia.md). A chave só existe no ambiente (RN44); sem driver, usa a falsa.
    'ai' => [
        'driver' => env('AI_DRIVER', 'fake'),
        'base_url' => env('AI_BASE_URL', 'https://api.aimlapi.com/v1'),
        'key' => env('AI_API_KEY'),
        'model_plan' => env('AI_MODEL_PLAN', 'gpt-4o-mini'),
        'model_chat' => env('AI_MODEL_CHAT', 'gpt-4o-mini'),
        'timeout' => (int) env('AI_TIMEOUT', 60),
        // Só E2E: e-mails cuja primeira geração de plano falha (E2E-07).
        'fake_fail_plan_for' => array_values(array_filter(explode(',', (string) env('AI_FAKE_FAIL_PLAN_FOR', '')))),
    ],
```

`.env.example` — acrescentar ao fim:
```
# IA: "fake" (padrão, sem custo) ou "openai" (API compatível, ex.: aimlapi.com). A chave nunca vai para o repositório.
AI_DRIVER=fake
AI_BASE_URL=https://api.aimlapi.com/v1
AI_API_KEY=
AI_MODEL_PLAN=gpt-4o-mini
AI_MODEL_CHAT=gpt-4o-mini
```

`phpunit.xml` — dentro de `<php>`, junto dos outros `<env>`:
```xml
        <env name="AI_DRIVER" value="fake"/>
```

`tests/Pest.php` — acrescentar ao fim:
```php
/** A IA falsa usada em todos os testes (roteiros com queue()/failNext()). */
function fakeAi(): \App\Ai\FakeAiClient
{
    return app(\App\Ai\FakeAiClient::class);
}
```

`tests/Feature/Auth/DeleteAccountTest.php` — acrescentar ao dataset `'tabelas do usuário'`:
```php
    'ai_requests' => ['ai_requests', 'user_id'],
```
e, no teste de CA08, junto das outras linhas criadas:
```php
    \App\Models\AiRequest::create(['user_id' => $user->id, 'purpose' => 'plan', 'model' => 'fake', 'duration_ms' => 1, 'status' => 'ok']);
```

`tests/Feature/Ai/OpenAiCompatibleClientTest.php`:
```php
<?php

use App\Ai\AiOptions;
use App\Ai\AiUnavailableException;
use App\Ai\OpenAiCompatibleClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

$options = fn () => new AiOptions(purpose: 'plan', model: 'gpt-x', maxTokens: 100, json: true);

it('envia no formato OpenAI, pede JSON e devolve o conteúdo com os tokens', function () use ($options) {
    Http::fake(['ia.test/*' => Http::response([
        'choices' => [['message' => ['content' => '{"ok":true}']]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ])]);

    $result = (new OpenAiCompatibleClient('https://ia.test/v1', 'chave-secreta', 30))->chat([['role' => 'user', 'content' => 'oi']], $options());

    expect($result->content)->toBe('{"ok":true}')->and($result->promptTokens)->toBe(10)->and($result->completionTokens)->toBe(5);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://ia.test/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer chave-secreta')
        && $request['model'] === 'gpt-x'
        && $request['max_tokens'] === 100
        && $request['response_format'] === ['type' => 'json_object']);
});

it('resposta 5xx vira AiUnavailableException', function () use ($options) {
    Http::fake(['ia.test/*' => Http::response('fora do ar', 503)]);

    (new OpenAiCompatibleClient('https://ia.test/v1', 'chave', 30))->chat([], $options());
})->throws(AiUnavailableException::class);

it('sem conexão (depois das novas tentativas) vira AiUnavailableException', function () use ($options) {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    (new OpenAiCompatibleClient('https://ia.test/v1', 'chave', 30))->chat([], $options());
})->throws(AiUnavailableException::class);

it('sem chave configurada nem tenta chamar', function () use ($options) {
    Http::fake();

    expect(fn () => (new OpenAiCompatibleClient('https://ia.test/v1', null, 30))->chat([], $options()))
        ->toThrow(AiUnavailableException::class);
    Http::assertNothingSent();
});
```

`tests/Feature/Ai/LoggingAiClientTest.php`:
```php
<?php

use App\Ai\AiClient;
use App\Ai\AiOptions;
use App\Ai\AiUnavailableException;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('registra a chamada sem guardar conteúdo (RN44)', function () {
    $user = User::factory()->create();
    fakeAi()->queue('plan', '{"meals":[]}');

    app(AiClient::class)->chat([['role' => 'user', 'content' => 'segredo de saúde']], new AiOptions('plan', 'modelo-x', 100, userId: $user->id));

    expect(AiRequest::sole()->only(['user_id', 'purpose', 'model', 'status']))
        ->toBe(['user_id' => $user->id, 'purpose' => 'plan', 'model' => 'modelo-x', 'status' => 'ok'])
        ->and(Schema::getColumnListing('ai_requests'))->not->toContain('content', 'messages', 'prompt', 'response');
});

it('registra a falha e repassa a exceção', function () {
    fakeAi()->failNext('plan');

    expect(fn () => app(AiClient::class)->chat([], new AiOptions('plan', 'modelo-x', 100)))->toThrow(AiUnavailableException::class)
        ->and(AiRequest::sole()->status)->toBe('error');
});
```

`tests/Feature/Ai/FakeAiClientTest.php`:
```php
<?php

use App\Ai\AiOptions;
use App\Ai\AiUnavailableException;

it('responde os roteiros na ordem e registra o que recebeu', function () {
    fakeAi()->queue('plan', 'primeira');
    fakeAi()->queue('plan', 'segunda');
    $opcoes = new AiOptions('plan', 'fake', 100);

    expect(fakeAi()->chat([['role' => 'user', 'content' => 'a']], $opcoes)->content)->toBe('primeira')
        ->and(fakeAi()->chat([['role' => 'user', 'content' => 'b']], $opcoes)->content)->toBe('segunda')
        ->and(fakeAi()->sentCount('plan'))->toBe(2);
    fakeAi()->assertSent('plan', fn (array $mensagens) => expect($mensagens[0]['role'])->toBe('user'));
});

it('falha quando roteirizado', function () {
    fakeAi()->failNext('plan');

    fakeAi()->chat([], new AiOptions('plan', 'fake', 100));
})->throws(AiUnavailableException::class);

it('sem roteiro, reclama de propósito que não sabe responder', function () {
    fakeAi()->chat([], new AiOptions('summary', 'fake', 100));
})->throws(LogicException::class);
```

Run: `docker compose run --rm api php artisan test tests/Feature/Ai`
Expected: FAIL — `Class "App\Ai\AiOptions" not found`.

- [ ] **Step 2: Implementar**

`app/Ai/AiClient.php`:
```php
<?php

namespace App\Ai;

/** Porta única para a IA generativa (integracao-ia.md §2). */
interface AiClient
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     *
     * @throws AiUnavailableException
     */
    public function chat(array $messages, AiOptions $options): AiResult;
}
```

`app/Ai/AiOptions.php`:
```php
<?php

namespace App\Ai;

final readonly class AiOptions
{
    /** @param string $purpose `plan` | `chat` | `summary` (vai para ai_requests) */
    public function __construct(
        public string $purpose,
        public string $model,
        public int $maxTokens,
        public bool $json = false,
        public float $temperature = 0.4,
        public ?int $userId = null,
    ) {}
}
```

`app/Ai/AiResult.php`:
```php
<?php

namespace App\Ai;

final readonly class AiResult
{
    public function __construct(
        public string $content,
        public ?int $promptTokens,
        public ?int $completionTokens,
        public int $durationMs,
    ) {}
}
```

`app/Ai/AiUnavailableException.php`:
```php
<?php

namespace App\Ai;

use RuntimeException;
use Throwable;

/** IA fora do ar, lenta demais ou sem configuração. Vira `AI_UNAVAILABLE` para quem chama. */
final class AiUnavailableException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $timeout = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
```

`app/Ai/OpenAiCompatibleClient.php`:
```php
<?php

namespace App\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/** `POST {base}/chat/completions` (OpenAI, aimlapi.com…). Sem streaming no MVP. */
final class OpenAiCompatibleClient implements AiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeoutSeconds,
    ) {}

    public function chat(array $messages, AiOptions $options): AiResult
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new AiUnavailableException('AI_API_KEY não configurada.');
        }

        $body = [
            'model' => $options->model,
            'messages' => $messages,
            'max_tokens' => $options->maxTokens,
            'temperature' => $options->temperature,
        ];
        if ($options->json) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $start = hrtime(true);
        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->retry(2, 500, fn (Throwable $e) => $e instanceof ConnectionException, throw: false)
                ->post(rtrim($this->baseUrl, '/').'/chat/completions', $body);
        } catch (ConnectionException $e) {
            throw new AiUnavailableException('Sem conexão com a IA.', str_contains($e->getMessage(), 'timed out'), $e);
        }

        if (! $response->successful()) {
            throw new AiUnavailableException("A IA respondeu {$response->status()}.");
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw new AiUnavailableException('A IA respondeu sem conteúdo.');
        }

        return new AiResult(
            $content,
            is_int($response->json('usage.prompt_tokens')) ? $response->json('usage.prompt_tokens') : null,
            is_int($response->json('usage.completion_tokens')) ? $response->json('usage.completion_tokens') : null,
            (int) ((hrtime(true) - $start) / 1_000_000),
        );
    }
}
```

`database/migrations/2026_09_30_000100_create_ai_requests_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RN44: só metadados. Nada de prompt nem de resposta.
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('purpose', 10);
            $table->string('model', 80);
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('status', 10);
            $table->string('error_code', 40)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at');
            $table->index(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
```

`app/Models/AiRequest.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Uma chamada à IA, sem conteúdo (RN44): custo e relatório de validação. */
class AiRequest extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'purpose', 'model', 'prompt_tokens', 'completion_tokens', 'duration_ms', 'status', 'error_code'];
}
```

`app/Ai/LoggingAiClient.php`:
```php
<?php

namespace App\Ai;

use App\Models\AiRequest;

/** Embrulha qualquer AiClient e grava `ai_requests` (sem conteúdo — RN44). */
final class LoggingAiClient implements AiClient
{
    public function __construct(private readonly AiClient $inner) {}

    public function chat(array $messages, AiOptions $options): AiResult
    {
        $start = hrtime(true);
        try {
            $result = $this->inner->chat($messages, $options);
        } catch (AiUnavailableException $e) {
            $this->log($options, $e->timeout ? 'timeout' : 'error', null, null, (int) ((hrtime(true) - $start) / 1_000_000), 'AI_UNAVAILABLE');

            throw $e;
        }

        $this->log($options, 'ok', $result->promptTokens, $result->completionTokens, $result->durationMs, null);

        return $result;
    }

    private function log(AiOptions $options, string $status, ?int $promptTokens, ?int $completionTokens, int $durationMs, ?string $errorCode): void
    {
        AiRequest::create([
            'user_id' => $options->userId,
            'purpose' => $options->purpose,
            'model' => $options->model,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'duration_ms' => $durationMs,
            'status' => $status,
            'error_code' => $errorCode,
        ]);
    }
}
```

`app/Ai/FakeAiClient.php`:
```php
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
```

`app/Providers/AppServiceProvider.php` — acrescentar os `use` (`App\Ai\AiClient`, `App\Ai\FakeAiClient`, `App\Ai\LoggingAiClient`, `App\Ai\OpenAiCompatibleClient`) e trocar o corpo de `register()` por:
```php
        $this->app->singleton(FakeAiClient::class, fn () => new FakeAiClient(config('services.ai.fake_fail_plan_for', [])));

        // Toda chamada passa pelo log sem conteúdo (RN44). Sem AI_DRIVER=openai, a IA é a falsa.
        $this->app->singleton(AiClient::class, fn ($app) => new LoggingAiClient(
            config('services.ai.driver') === 'openai'
                ? new OpenAiCompatibleClient((string) config('services.ai.base_url'), config('services.ai.key'), (int) config('services.ai.timeout'))
                : $app->make(FakeAiClient::class),
        ));
```

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Feature/Ai tests/Feature/Auth/DeleteAccountTest.php`
Expected: PASS.

- [ ] **Step 4: Lint e commit**

Run: `docker compose run --rm --no-deps api composer lint`
Expected: OK.

```bash
git add -A && git commit -m "feat(ia): cliente OpenAI-compatível, IA falsa determinística e log sem conteúdo (RN44)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 4: Esquema do plano, prompt, leitura da resposta, ajuste de porções e validação (RN18)

**Files:**
- Create: `database/migrations/2026_09_30_000200_create_meal_plans_tables.php`
- Create: `app/Models/{MealPlan,PlanMeal,PlanMealItem}.php`; Modify: `app/Models/User.php`
- Create: `app/Ai/Prompts/PlanPrompt.php`, `app/Ai/Schemas/{PlanResponse,InvalidAiResponse}.php`
- Create: `app/Services/Plans/{PortionAdjuster,PlanValidator}.php`
- Modify: `tests/Feature/Auth/DeleteAccountTest.php`
- Test: `tests/Feature/Plans/MealPlanSchemaTest.php`, `tests/Unit/Plans/{PlanResponseTest,PortionAdjusterTest,PlanValidatorTest}.php` (+ `tests/Unit/Plans/cardapio.php`, funções compartilhadas)

**Interfaces:**
- Consumes: `MealSlot`, `PlanStatus` (Task 1).
- Produces:
  - Tabelas `meal_plans` (coluna gerada `active_user_id` com `UNIQUE`), `plan_meals`, `plan_meal_items`. Models `MealPlan` (`status` → `PlanStatus`, `inputs` array, `meals()`, `user()`), `PlanMeal` (`slot` string, `time` "HH:MM:SS", `items()`), `PlanMealItem` (`food()`, `grams` float). Em `User`: `mealPlans()`, `activePlan()` (HasOne `is_active`).
  - Tipo dos cardápios em memória: `list<array{slot: string, items: list<array{food_id: int, grams: float}>}>` ("Meals").
  - `PlanPrompt::VERSION`, `system(): string`, `user(array $inputs): string`, `correction(list<string> $errors): string`.
  - `PlanResponse::parse(string): Meals` (lança `InvalidAiResponse` com mensagem em português, que volta para a IA).
  - `PortionAdjuster::adjust(Meals, Collection<int, Food> $foods, int $targetKcal): Meals`.
  - `PlanValidator::validate(Meals, Collection<int, Food> $allowed, int $targetKcal, int $targetProteinG): list<string>`.

- [ ] **Step 1: Testes que devem falhar**

`tests/Unit/Plans/PlanResponseTest.php`:
```php
<?php

use App\Ai\Schemas\InvalidAiResponse;
use App\Ai\Schemas\PlanResponse;

it('lê o JSON do contrato, convertendo números', function () {
    $meals = PlanResponse::parse('{"meals":[{"slot":"cafe","items":[{"food_id":"31","grams":150},{"food_id":40,"grams":"50.5"}]}]}');

    expect($meals)->toBe([['slot' => 'cafe', 'items' => [['food_id' => 31, 'grams' => 150.0], ['food_id' => 40, 'grams' => 50.5]]]]);
});

it('acha o objeto JSON mesmo com texto em volta', function () {
    $meals = PlanResponse::parse("Aqui está o plano:\n```json\n{\"meals\":[{\"slot\":\"jantar\",\"items\":[{\"food_id\":1,\"grams\":100}]}]}\n```\nBom apetite!");

    expect($meals[0]['slot'])->toBe('jantar');
});

it('recusa o que não é o contrato, dizendo o motivo', function (string $content, string $motivo) {
    expect(fn () => PlanResponse::parse($content))->toThrow(InvalidAiResponse::class, $motivo);
})->with([
    'texto solto' => ['não sei montar', 'JSON'],
    'sem meals' => ['{"refeicoes":[]}', '"meals"'],
    'item sem food_id' => ['{"meals":[{"slot":"cafe","items":[{"grams":100}]}]}', 'food_id'],
    'slot que não é texto' => ['{"meals":[{"slot":3,"items":[]}]}', 'slot'],
]);
```

`tests/Unit/Plans/cardapio.php` (funções dos dois testes abaixo; não é arquivo de teste):
```php
<?php

use App\Models\Food;


function alimentoDeTeste(int $id, string $grupo, float $kcal, float $proteina): Food
{
    return (new Food(['name' => "Alimento {$id}", 'group' => $grupo, 'kcal_per_100g' => $kcal, 'protein_per_100g' => $proteina, 'carbs_per_100g' => 0, 'fat_per_100g' => 0]))
        ->forceFill(['id' => $id]);
}

function cardapioDeTeste(): array
{
    return [
        ['slot' => 'cafe', 'items' => [['food_id' => 3, 'grams' => 100.0]]],
        ['slot' => 'lanche', 'items' => [['food_id' => 3, 'grams' => 100.0]]],
        ['slot' => 'almoco', 'items' => [['food_id' => 1, 'grams' => 200.0], ['food_id' => 2, 'grams' => 150.0]]],
        ['slot' => 'pre-treino', 'items' => [['food_id' => 1, 'grams' => 100.0]]],
        ['slot' => 'jantar', 'items' => [['food_id' => 1, 'grams' => 150.0], ['food_id' => 2, 'grams' => 150.0]]],
    ];
}

function alimentosDeTeste(): Illuminate\Support\Collection
{
    return collect([alimentoDeTeste(1, 'carboidrato', 128, 2.5), alimentoDeTeste(2, 'proteina', 159, 32), alimentoDeTeste(3, 'fruta', 98, 1.3)])->keyBy('id');
}

function kcalDoCardapio(array $meals): float
{
    $foods = alimentosDeTeste();

    return collect($meals)->flatMap(fn ($m) => $m['items'])->sum(fn ($i) => $foods[$i['food_id']]->kcal_per_100g * $i['grams'] / 100);
}
```

`tests/Unit/Plans/PortionAdjusterTest.php`:
```php
<?php

use App\Services\Plans\PortionAdjuster;

require_once __DIR__.'/cardapio.php';

it('escala todas as porções quando o desvio é de até 25% (RN18)', function () {
    // cardápio: 1.249 kcal; meta 1.500 → desvio de 16,7%
    $ajustado = (new PortionAdjuster)->adjust(cardapioDeTeste(), alimentosDeTeste(), 1500);

    expect(abs(kcalDoCardapio($ajustado) - 1500) / 1500)->toBeLessThan(0.02)
        ->and($ajustado[2]['items'][0]['grams'])->toBe(240.0)
        ->and(collect($ajustado)->flatMap(fn ($m) => $m['items'])->every(fn ($i) => fmod($i['grams'], 5) == 0))->toBeTrue();
});

it('não mexe quando o desvio passa de 25% ou já está na meta', function (int $meta) {
    expect((new PortionAdjuster)->adjust(cardapioDeTeste(), alimentosDeTeste(), $meta))->toBe(cardapioDeTeste());
})->with([2000, 1249]);
```

`tests/Unit/Plans/PlanValidatorTest.php`:
```php
<?php

use App\Services\Plans\PlanValidator;

require_once __DIR__.'/cardapio.php';

// cardapioDeTeste(): 1.249 kcal e 109,85 g de proteína
function problemas(array $meals, int $kcal = 1250, int $proteina = 110): string
{
    return implode("\n", (new PlanValidator)->validate($meals, alimentosDeTeste(), $kcal, $proteina));
}

it('aceita o cardápio que cumpre RN18', function () {
    expect((new PlanValidator)->validate(cardapioDeTeste(), alimentosDeTeste(), 1250, 110))->toBe([]);
});

it('aponta cada regra quebrada (RN18)', function (Closure $estraga, string $trecho, int $kcal, int $proteina) {
    expect(problemas($estraga(cardapioDeTeste()), $kcal, $proteina))->toContain($trecho);
})->with([
    'faltando refeição' => [fn ($m) => array_slice($m, 0, 4), 'exatamente 5 refeições', 1250, 110],
    'refeição repetida' => [fn ($m) => [...array_slice($m, 1), $m[4]], 'exatamente 5 refeições', 1250, 110],
    'refeição vazia' => [function ($m) { $m[0]['items'] = []; return $m; }, 'de 1 a 6 itens', 1250, 110],
    'refeição com 7 itens' => [function ($m) { $m[0]['items'] = array_fill(0, 7, ['food_id' => 3, 'grams' => 10.0]); return $m; }, 'de 1 a 6 itens', 1250, 110],
    'alimento fora da lista' => [function ($m) { $m[0]['items'][0]['food_id'] = 99; return $m; }, 'não está na lista permitida', 1250, 110],
    'porção de 4 g' => [function ($m) { $m[0]['items'][0]['grams'] = 4.0; return $m; }, 'entre 5 e 600 g', 1250, 110],
    'porção de 601 g' => [function ($m) { $m[0]['items'][0]['grams'] = 601.0; return $m; }, 'entre 5 e 600 g', 1250, 110],
    'kcal longe da meta' => [fn ($m) => $m, 'meta é 2000 kcal', 2000, 110],
    'proteína baixa' => [fn ($m) => $m, 'pelo menos 135', 1250, 150],
]);
```

`tests/Feature/Plans/MealPlanSchemaTest.php`:
```php
<?php

use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

function planoDeTeste(User $user, array $extra = []): MealPlan
{
    return $user->mealPlans()->create(array_merge([
        'status' => 'ready', 'target_kcal' => 2250, 'target_protein_g' => 115, 'target_carbs_g' => 305, 'target_fat_g' => 65, 'inputs' => [],
    ], $extra));
}

it('permite um só plano ativo por usuário (coluna gerada única)', function () {
    $user = User::factory()->create();
    planoDeTeste($user, ['is_active' => true]);

    expect(fn () => planoDeTeste($user, ['is_active' => true]))->toThrow(UniqueConstraintViolationException::class);
});

it('deixa vários planos inativos e um ativo por usuário diferente', function () {
    $ana = User::factory()->create();
    $bia = User::factory()->create();
    planoDeTeste($ana);
    planoDeTeste($ana);
    planoDeTeste($ana, ['is_active' => true]);
    planoDeTeste($bia, ['is_active' => true]);

    expect($ana->activePlan()->count())->toBe(1)->and(MealPlan::count())->toBe(4);
});
```

Em `tests/Feature/Auth/DeleteAccountTest.php`, acrescentar ao dataset:
```php
    'meal_plans' => ['meal_plans', 'user_id'],
```
e, no teste de CA08, junto das outras linhas:
```php
    $user->mealPlans()->create(['status' => 'ready', 'target_kcal' => 2250, 'target_protein_g' => 115, 'target_carbs_g' => 305, 'target_fat_g' => 65, 'inputs' => []]);
```

Run: `docker compose run --rm api php artisan test tests/Unit/Plans tests/Feature/Plans`
Expected: FAIL — classes e tabela inexistentes.

- [ ] **Step 2: Esquema e models**

`database/migrations/2026_09_30_000200_create_meal_plans_tables.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12);
            $table->boolean('is_active')->default(false);
            // RN20: um só plano ativo por usuário, garantido pelo banco (UNIQUE abaixo). Não é coluna gerada:
            // o MySQL não aceita CASCADE na FK da coluna-base de uma coluna gerada. O model mantém o valor.
            $table->unsignedBigInteger('active_user_id')->nullable();
            $table->unsignedSmallInteger('target_kcal');
            $table->unsignedSmallInteger('target_protein_g');
            $table->unsignedSmallInteger('target_carbs_g');
            $table->unsignedSmallInteger('target_fat_g');
            $table->json('inputs');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('failure_reason')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->unique('active_user_id');
        });

        Schema::create('plan_meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->string('slot', 12);
            $table->string('name', 40);
            $table->time('time');
            $table->unsignedTinyInteger('position');
            $table->unique(['meal_plan_id', 'slot']);
        });

        Schema::create('plan_meal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_meal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('foods')->restrictOnDelete();
            $table->decimal('grams', 6, 1);
            $table->unsignedTinyInteger('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_meal_items');
        Schema::dropIfExists('plan_meals');
        Schema::dropIfExists('meal_plans');
    }
};
```

`app/Models/MealPlan.php`:
```php
<?php

namespace App\Models;

use App\Enums\PlanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plano alimentar gerado (RN18–RN20).
 *
 * @property PlanStatus $status
 * @property array<string, mixed> $inputs
 */
class MealPlan extends Model
{
    protected $fillable = [
        'status', 'is_active', 'target_kcal', 'target_protein_g', 'target_carbs_g', 'target_fat_g',
        'inputs', 'attempts', 'failure_reason', 'ready_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlanStatus::class,
            'is_active' => 'boolean',
            'inputs' => 'array',
            'ready_at' => 'datetime',
        ];
    }

    /** `active_user_id` = `user_id` só no plano ativo; o UNIQUE garante um ativo por usuário (RN20). */
    protected static function booted(): void
    {
        static::saving(function (MealPlan $plan) {
            $plan->active_user_id = $plan->is_active ? $plan->user_id : null;
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PlanMeal, $this> */
    public function meals(): HasMany
    {
        return $this->hasMany(PlanMeal::class)->orderBy('position');
    }
}
```

`app/Models/PlanMeal.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Refeição-modelo do plano (vale para todos os dias — RN15). */
class PlanMeal extends Model
{
    public $timestamps = false;

    protected $fillable = ['slot', 'name', 'time', 'position'];

    /** @return BelongsTo<MealPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class, 'meal_plan_id');
    }

    /** @return HasMany<PlanMealItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PlanMealItem::class)->orderBy('position');
    }
}
```

`app/Models/PlanMealItem.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanMealItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['food_id', 'grams', 'position'];

    protected function casts(): array
    {
        return ['grams' => 'float'];
    }

    /** @return BelongsTo<Food, $this> */
    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }
}
```

Em `app/Models/User.php`, acrescentar:
```php
    /** @return HasMany<MealPlan, $this> */
    public function mealPlans(): HasMany
    {
        return $this->hasMany(MealPlan::class);
    }

    /** RN20: o único plano ativo. @return HasOne<MealPlan, $this> */
    public function activePlan(): HasOne
    {
        return $this->hasOne(MealPlan::class)->where('is_active', true);
    }
```

- [ ] **Step 3: Prompt, leitura, ajuste e validação**

`app/Ai/Prompts/PlanPrompt.php`:
```php
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
```

`app/Ai/Schemas/InvalidAiResponse.php`:
```php
<?php

namespace App\Ai\Schemas;

use RuntimeException;

/** A IA respondeu fora do contrato. A mensagem vai de volta para ela na nova tentativa. */
final class InvalidAiResponse extends RuntimeException {}
```

`app/Ai/Schemas/PlanResponse.php`:
```php
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
```

`app/Services/Plans/PortionAdjuster.php`:
```php
<?php

namespace App\Services\Plans;

use App\Models\Food;
use Illuminate\Support\Collection;

/** RN18 — escala uniforme das porções do dia para bater a meta de kcal. Puro. */
final class PortionAdjuster
{
    private const MAX_DEVIATION = 0.25;

    /**
     * Só escala quando o desvio é de até 25% (mais que isso é erro da IA, não arredondamento).
     *
     * @param  list<array{slot: string, items: list<array{food_id: int, grams: float}>}>  $meals
     * @param  Collection<int, Food>  $foods
     * @return list<array{slot: string, items: list<array{food_id: int, grams: float}>}>
     */
    public function adjust(array $meals, Collection $foods, int $targetKcal): array
    {
        $total = 0.0;
        foreach ($meals as $meal) {
            foreach ($meal['items'] as $item) {
                $food = $foods->get($item['food_id']);
                $total += $food ? $food->kcal_per_100g * $item['grams'] / 100 : 0;
            }
        }
        if ($total <= 0 || $targetKcal <= 0) {
            return $meals;
        }

        $deviation = abs($total - $targetKcal) / $targetKcal;
        if ($deviation < 0.005 || $deviation > self::MAX_DEVIATION) {
            return $meals;
        }

        $factor = $targetKcal / $total;

        return array_map(fn (array $meal) => [
            'slot' => $meal['slot'],
            'items' => array_map(fn (array $item) => [
                'food_id' => $item['food_id'],
                'grams' => max(5.0, round($item['grams'] * $factor / 5) * 5),
            ], $meal['items']),
        ], $meals);
    }
}
```

`app/Services/Plans/PlanValidator.php`:
```php
<?php

namespace App\Services\Plans;

use App\Enums\MealSlot;
use App\Models\Food;
use Illuminate\Support\Collection;

/** RN18 — aceita ou recusa o cardápio da IA. As mensagens vão de volta para ela. Puro. */
final class PlanValidator
{
    /**
     * @param  list<array{slot: string, items: list<array{food_id: int, grams: float}>}>  $meals
     * @param  Collection<int, Food>  $allowed  RN16 (chave = id)
     * @return list<string>
     */
    public function validate(array $meals, Collection $allowed, int $targetKcal, int $targetProteinG): array
    {
        $errors = [];

        $slots = array_column($meals, 'slot');
        $expected = array_map(fn (MealSlot $slot) => $slot->value, MealSlot::cases());
        sort($slots);
        sort($expected);
        if ($slots !== $expected) {
            $errors[] = 'Monte exatamente 5 refeições, uma para cada slot: cafe, lanche, almoco, pre-treino, jantar.';
        }

        $kcal = 0.0;
        $protein = 0.0;
        foreach ($meals as $meal) {
            $count = count($meal['items']);
            if ($count < 1 || $count > 6) {
                $errors[] = "A refeição {$meal['slot']} precisa ter de 1 a 6 itens.";
            }
            foreach ($meal['items'] as $item) {
                $food = $allowed->get($item['food_id']);
                if ($food === null) {
                    $errors[] = "O alimento {$item['food_id']} não está na lista permitida.";

                    continue;
                }
                if ($item['grams'] < 5 || $item['grams'] > 600) {
                    $errors[] = "O item {$item['food_id']} em {$meal['slot']} precisa ter entre 5 e 600 g.";
                }
                $kcal += $food->kcal_per_100g * $item['grams'] / 100;
                $protein += $food->protein_per_100g * $item['grams'] / 100;
            }
        }

        if (abs($kcal - $targetKcal) > 0.10 * $targetKcal) {
            $errors[] = sprintf('O total do dia ficou em %d kcal; a meta é %d kcal (±10%%).', round($kcal), $targetKcal);
        }
        if ($protein < 0.9 * $targetProteinG) {
            $errors[] = sprintf('A proteína do dia ficou em %d g; precisa de pelo menos %d g.', round($protein), ceil(0.9 * $targetProteinG));
        }

        return $errors;
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Unit/Plans tests/Feature/Plans tests/Feature/Auth/DeleteAccountTest.php`
Expected: PASS.

- [ ] **Step 5: Lint e commit**

Run: `docker compose run --rm --no-deps api composer lint`
Expected: OK.

```bash
git add -A && git commit -m "feat(plano): esquema do plano, prompt, leitura da resposta, ajuste e validação (RN18)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 5: Geração — `PlanService` (pedir/ativar), `PlanGenerator`, `GeneratePlanJob` e `plans:fail-stale`

**Files:**
- Create: `app/Services/Plans/{PlanService,PlanGenerator}.php`, `app/Jobs/GeneratePlanJob.php`, `app/Console/Commands/FailStalePlans.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Plans/GeneratePlanJobTest.php`

**Interfaces:**
- Consumes: Tasks 1–4; `NutritionCalculator` (Plano 03).
- Produces:
  - `PlanService::requestGeneration(User, bool $force = false): MealPlan` — 409 `ONBOARDING_INCOMPLETE`/`PLAN_ALREADY_GENERATING` (`details.plan_id`); `$force` (mudança de restrição) ignora o que já está gerando. Cria `pending` com as metas e despacha o job.
  - `PlanService::targetsFor(User): DailyTargets`; `PlanService::activate(MealPlan): bool` — só ativa o mais novo que não falhou; senão `failed` `SUPERSEDED`.
  - `PlanGenerator::generate(MealPlan): void` — pipeline do integracao-ia.md §3.3 (2 tentativas).
  - `GeneratePlanJob(int $planId)`; comando `plans:fail-stale` (agendado a cada 5 min) → `failed` `TIMEOUT` depois de 3 min.
  - `failure_reason`: `AI_UNAVAILABLE`, `AI_INVALID_RESPONSE`, `TIMEOUT`, `SUPERSEDED`.

- [ ] **Step 1: Testes que devem falhar**

`tests/Feature/Plans/GeneratePlanJobTest.php`:
```php
<?php

use App\Enums\PlanStatus;
use App\Jobs\GeneratePlanJob;
use App\Models\Food;
use App\Models\MealPlan;
use App\Models\PlanMealItem;
use App\Models\Restriction;
use App\Models\User;
use App\Services\Plans\PlanService;

beforeEach(function () {
    seedCatalog();
    $this->user = User::factory()->onboarded()->create(['name' => 'Camila Réus', 'email' => 'camila@exemplo.com']);
    $this->user->restrictions()->attach(Restriction::where('slug', 'castanhas')->sole());
});

function kcalEProteina(MealPlan $plan): array
{
    $itens = PlanMealItem::with('food')->whereIn('plan_meal_id', $plan->meals()->pluck('id'))->get();

    return [
        $itens->sum(fn ($i) => $i->food->kcal_per_100g * $i->grams / 100),
        $itens->sum(fn ($i) => $i->food->protein_per_100g * $i->grams / 100),
    ];
}

it('gera um plano válido, pronto e ativo, com os horários do RN14 (CA01, CA04)', function () {
    $plan = app(PlanService::class)->requestGeneration($this->user)->fresh();

    expect($plan->status)->toBe(PlanStatus::Ready)
        ->and($plan->is_active)->toBeTrue()
        ->and($plan->attempts)->toBe(1)
        ->and([$plan->target_kcal, $plan->target_protein_g])->toBe([2250, 115])
        ->and($plan->meals()->pluck('time', 'slot')->all())->toBe([
            'cafe' => '07:00:00', 'lanche' => '10:00:00', 'almoco' => '12:30:00', 'pre-treino' => '17:30:00', 'jantar' => '20:30:00',
        ])
        ->and($plan->meals()->pluck('name')->all())->toBe(['Café da manhã', 'Lanche da manhã', 'Almoço', 'Pré-treino', 'Jantar']);

    [$kcal, $proteina] = kcalEProteina($plan);
    expect(abs($kcal - 2250) / 2250)->toBeLessThanOrEqual(0.10)
        ->and($proteina)->toBeGreaterThanOrEqual(0.9 * 115);
});

it('recusa o alimento proibido da 1ª resposta e aceita a 2ª (CA02, RN17)', function () {
    $castanha = Food::where('slug', 'castanha-de-caju')->value('id');
    $proibido = ['food_id' => $castanha, 'grams' => 50];
    fakeAi()->queue('plan', json_encode(['meals' => array_map(
        fn (string $slot) => ['slot' => $slot, 'items' => [$proibido]],
        ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar'],
    )]));

    $plan = app(PlanService::class)->requestGeneration($this->user)->fresh();

    expect($plan->status)->toBe(PlanStatus::Ready)
        ->and($plan->attempts)->toBe(2)
        ->and(PlanMealItem::where('food_id', $castanha)->exists())->toBeFalse();
    fakeAi()->assertSent('plan', function (array $mensagens) use ($castanha) {
        if (count($mensagens) > 2) {
            expect(end($mensagens)['content'])->toContain('Corrija')->toContain((string) $castanha);
        }
    });
});

it('duas respostas fora do contrato deixam o plano como falhou (CA03)', function () {
    fakeAi()->queue('plan', 'não sei');
    fakeAi()->queue('plan', '{"meals": "nada"}');

    $plan = app(PlanService::class)->requestGeneration($this->user)->fresh();

    expect($plan->status)->toBe(PlanStatus::Failed)
        ->and($plan->failure_reason)->toBe('AI_INVALID_RESPONSE')
        ->and($plan->attempts)->toBe(2)
        ->and($plan->is_active)->toBeFalse();
});

it('IA fora do ar deixa o plano como falhou com AI_UNAVAILABLE', function () {
    fakeAi()->failNext('plan');

    $plan = app(PlanService::class)->requestGeneration($this->user)->fresh();

    expect($plan->status)->toBe(PlanStatus::Failed)->and($plan->failure_reason)->toBe('AI_UNAVAILABLE');
});

it('não manda nome nem e-mail para a IA, e guarda o que mandou no plano (minimização)', function () {
    $plan = app(PlanService::class)->requestGeneration($this->user)->fresh();

    fakeAi()->assertSent('plan', function (array $mensagens) {
        $tudo = implode("\n", array_column($mensagens, 'content'));
        expect($tudo)->not->toContain('Camila Réus')->not->toContain('camila@exemplo.com');
    });
    expect($plan->inputs)->toHaveKeys(['pessoa', 'metas_diarias', 'horarios', 'alimentos_permitidos', 'prompt_version'])
        ->and(collect($plan->inputs['alimentos_permitidos'])->pluck('id'))->not->toContain(Food::where('slug', 'castanha-de-caju')->value('id'));
});

it('quem não come nada de origem animal também recebe plano válido', function () {
    $this->user->restrictions()->sync(Restriction::where('slug', 'sem-animal')->pluck('id'));

    expect(app(PlanService::class)->requestGeneration($this->user)->fresh()->status)->toBe(PlanStatus::Ready);
});

it('o plano novo vira o único ativo (RN20)', function () {
    $primeiro = app(PlanService::class)->requestGeneration($this->user);
    $segundo = app(PlanService::class)->requestGeneration($this->user);

    expect($primeiro->fresh()->is_active)->toBeFalse()
        ->and($segundo->fresh()->is_active)->toBeTrue()
        ->and($this->user->activePlan()->value('id'))->toBe($segundo->id);
});

it('um plano mais antigo que termina depois do mais novo não é ativado (SUPERSEDED)', function () {
    $antigo = $this->user->mealPlans()->create(['status' => 'pending', 'target_kcal' => 2250, 'target_protein_g' => 115, 'target_carbs_g' => 305, 'target_fat_g' => 65, 'inputs' => []]);
    $novo = app(PlanService::class)->requestGeneration($this->user, force: true);

    GeneratePlanJob::dispatchSync($antigo->id);

    expect($antigo->fresh()->status)->toBe(PlanStatus::Failed)
        ->and($antigo->fresh()->failure_reason)->toBe('SUPERSEDED')
        ->and($novo->fresh()->is_active)->toBeTrue();
});

it('plans:fail-stale marca como TIMEOUT o que está gerando há mais de 3 min (RN19)', function () {
    $velho = $this->user->mealPlans()->create(['status' => 'generating', 'target_kcal' => 1, 'target_protein_g' => 1, 'target_carbs_g' => 1, 'target_fat_g' => 1, 'inputs' => []]);
    $velho->forceFill(['created_at' => now()->subMinutes(4)])->save();
    $recente = $this->user->mealPlans()->create(['status' => 'pending', 'target_kcal' => 1, 'target_protein_g' => 1, 'target_carbs_g' => 1, 'target_fat_g' => 1, 'inputs' => []]);

    $this->artisan('plans:fail-stale')->assertSuccessful();

    expect($velho->fresh()->failure_reason)->toBe('TIMEOUT')
        ->and($velho->fresh()->status)->toBe(PlanStatus::Failed)
        ->and($recente->fresh()->status)->toBe(PlanStatus::Pending);
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Plans/GeneratePlanJobTest.php`
Expected: FAIL — `Class "App\Services\Plans\PlanService" not found`.

- [ ] **Step 2: Implementar**

`app/Services/Plans/PlanService.php`:
```php
<?php

namespace App\Services\Plans;

use App\Enums\ActivityLevel;
use App\Enums\ErrorCode;
use App\Enums\Goal;
use App\Enums\PlanStatus;
use App\Enums\Sex;
use App\Enums\WorkPosture;
use App\Exceptions\DomainException;
use App\Jobs\GeneratePlanJob;
use App\Models\MealPlan;
use App\Models\User;
use App\Services\Nutrition\DailyTargets;
use App\Services\Nutrition\NutritionCalculator;
use Illuminate\Support\Facades\DB;

/** Pedir, ativar e refazer planos (RN19–RN21). */
class PlanService
{
    public function __construct(private readonly NutritionCalculator $calculator) {}

    /**
     * RN19 — um plano gerando por usuário. `$force`: mudança de restrição (RN21) não espera o que está
     * gerando; o mais novo vence na ativação.
     */
    public function requestGeneration(User $user, bool $force = false): MealPlan
    {
        $plan = DB::transaction(function () use ($user, $force) {
            User::whereKey($user->id)->lockForUpdate()->first(); // serializa pedidos do mesmo usuário
            $profile = $user->profile()->firstOrFail();

            if (! $profile->isOnboarded()) {
                throw new DomainException(ErrorCode::OnboardingIncomplete, ['next_step' => $profile->nextStep()?->value]);
            }
            if (! $force) {
                $busy = $user->mealPlans()->whereIn('status', [PlanStatus::Pending, PlanStatus::Generating])->latest('id')->first();
                if ($busy !== null) {
                    throw new DomainException(ErrorCode::PlanAlreadyGenerating, ['plan_id' => $busy->id]);
                }
            }

            $targets = $this->targetsFor($user);

            return $user->mealPlans()->create([
                'status' => PlanStatus::Pending,
                'target_kcal' => $targets->kcal,
                'target_protein_g' => $targets->proteinG,
                'target_carbs_g' => $targets->carbsG,
                'target_fat_g' => $targets->fatG,
                'inputs' => [],
            ]);
        });

        GeneratePlanJob::dispatch($plan->id);

        return $plan;
    }

    /** RN13 com o perfil e o peso atual. */
    public function targetsFor(User $user): DailyTargets
    {
        $profile = $user->profile()->firstOrFail();

        return $this->calculator->dailyTargets(
            Goal::from((string) $profile->goal), Sex::from((string) $profile->sex), (int) $profile->age, (int) $profile->height_cm,
            (float) $user->currentWeightKg(), ActivityLevel::from((string) $profile->activity_level), WorkPosture::from((string) $profile->work_posture),
        );
    }

    /**
     * RN20 — o plano pronto vira o único ativo. Um plano mais antigo que termine depois de um mais novo
     * (ainda válido) não é ativado: vira `failed` `SUPERSEDED`, para uma restrição nova nunca se perder.
     */
    public function activate(MealPlan $plan): bool
    {
        return DB::transaction(function () use ($plan) {
            User::whereKey($plan->user_id)->lockForUpdate()->first();

            $newer = MealPlan::where('user_id', $plan->user_id)->where('id', '>', $plan->id)
                ->where('status', '!=', PlanStatus::Failed)->exists();
            if ($newer) {
                $plan->update(['status' => PlanStatus::Failed, 'failure_reason' => 'SUPERSEDED']);

                return false;
            }

            MealPlan::where('user_id', $plan->user_id)->where('is_active', true)->update(['is_active' => false, 'active_user_id' => null]);
            $plan->update(['status' => PlanStatus::Ready, 'is_active' => true, 'ready_at' => now()]);

            return true;
        });
    }
}
```

`app/Services/Plans/PlanGenerator.php`:
```php
<?php

namespace App\Services\Plans;

use App\Ai\AiClient;
use App\Ai\AiOptions;
use App\Ai\AiUnavailableException;
use App\Ai\Prompts\PlanPrompt;
use App\Ai\Schemas\InvalidAiResponse;
use App\Ai\Schemas\PlanResponse;
use App\Enums\MealSlot;
use App\Enums\PlanStatus;
use App\Models\Food;
use App\Models\MealPlan;
use App\Services\Foods\FoodFilter;
use App\Services\Nutrition\MealScheduler;
use Illuminate\Support\Facades\DB;

/** Pipeline da geração (integracao-ia.md §3.3): prompt → IA → parse → ajuste → validação → 2ª tentativa → persistência. */
class PlanGenerator
{
    private const MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly AiClient $ai,
        private readonly FoodFilter $filter,
        private readonly MealScheduler $scheduler,
        private readonly PortionAdjuster $adjuster,
        private readonly PlanValidator $validator,
        private readonly PlanService $plans,
    ) {}

    public function generate(MealPlan $plan): void
    {
        $plan->update(['status' => PlanStatus::Generating]);
        $user = $plan->user()->firstOrFail();
        $profile = $user->profile()->firstOrFail();

        $allowed = $this->filter->allowedFor($user);
        $pantry = $this->filter->pantryFoodIds($user);
        $training = substr((string) $profile->training_time, 0, 5);
        $times = $this->scheduler->schedule(
            substr((string) $profile->wake_time, 0, 5), $training, substr((string) $profile->sleep_time, 0, 5), $profile->training_days !== [],
        );

        // Minimização: nada de nome, e-mail ou texto livre (seguranca.md); "outras restrições" já saíram no filtro.
        $inputs = [
            'pessoa' => [
                'objetivo' => $profile->goal, 'sexo' => $profile->sex, 'idade' => $profile->age, 'altura_cm' => $profile->height_cm,
                'peso_kg' => $user->currentWeightKg(), 'atividade' => $profile->activity_level, 'trabalho' => $profile->work_posture,
                'almoco' => $profile->lunch_place,
            ],
            'metas_diarias' => [
                'kcal' => $plan->target_kcal, 'proteina_g' => $plan->target_protein_g,
                'carboidrato_g' => $plan->target_carbs_g, 'gordura_g' => $plan->target_fat_g,
            ],
            'horarios' => $times + ['treino' => $training],
            'alimentos_permitidos' => $allowed->map(fn (Food $food) => [
                'id' => $food->id, 'nome' => $food->name, 'grupo' => $food->group,
                'kcal_100g' => $food->kcal_per_100g, 'prot_100g' => $food->protein_per_100g,
                'carb_100g' => $food->carbs_per_100g, 'gord_100g' => $food->fat_per_100g,
                'pantry' => in_array($food->id, $pantry, true),
            ])->values()->all(),
        ];
        $plan->update(['inputs' => $inputs + ['prompt_version' => PlanPrompt::VERSION]]);

        $messages = [
            ['role' => 'system', 'content' => PlanPrompt::system()],
            ['role' => 'user', 'content' => PlanPrompt::user($inputs)],
        ];
        $options = new AiOptions('plan', (string) config('services.ai.model_plan'), 1500, json: true, temperature: 0.4, userId: $user->id);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $result = $this->ai->chat($messages, $options);
            } catch (AiUnavailableException) {
                $this->fail($plan, 'AI_UNAVAILABLE');

                return;
            }
            $plan->increment('attempts');

            try {
                $meals = $this->adjuster->adjust(PlanResponse::parse($result->content), $allowed, $plan->target_kcal);
                $errors = $this->validator->validate($meals, $allowed, $plan->target_kcal, $plan->target_protein_g);
            } catch (InvalidAiResponse $e) {
                $meals = [];
                $errors = [$e->getMessage()];
            }

            if ($errors === []) {
                $this->persist($plan, $meals, $times);
                $this->plans->activate($plan);

                return;
            }

            $messages[] = ['role' => 'assistant', 'content' => $result->content];
            $messages[] = ['role' => 'user', 'content' => PlanPrompt::correction($errors)];
        }

        $this->fail($plan, 'AI_INVALID_RESPONSE');
    }

    /**
     * @param  list<array{slot: string, items: list<array{food_id: int, grams: float}>}>  $meals
     * @param  array<string, string>  $times  slot => "HH:MM", em ordem de horário
     */
    private function persist(MealPlan $plan, array $meals, array $times): void
    {
        $order = array_keys($times);

        DB::transaction(function () use ($plan, $meals, $times, $order) {
            foreach ($meals as $meal) {
                $planMeal = $plan->meals()->create([
                    'slot' => $meal['slot'],
                    'name' => MealSlot::from($meal['slot'])->label(),
                    'time' => $times[$meal['slot']],
                    'position' => (int) array_search($meal['slot'], $order, true) + 1,
                ]);
                foreach ($meal['items'] as $i => $item) {
                    $planMeal->items()->create(['food_id' => $item['food_id'], 'grams' => $item['grams'], 'position' => $i + 1]);
                }
            }
        });
    }

    private function fail(MealPlan $plan, string $reason): void
    {
        $plan->update(['status' => PlanStatus::Failed, 'failure_reason' => $reason]);
    }
}
```

`app/Jobs/GeneratePlanJob.php`:
```php
<?php

namespace App\Jobs;

use App\Enums\PlanStatus;
use App\Models\MealPlan;
use App\Services\Plans\PlanGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Gera o plano fora do request (RF09). Uma tentativa de job; as 2 tentativas com a IA ficam no gerador. */
class GeneratePlanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 170;

    public function __construct(public readonly int $planId) {}

    public function handle(PlanGenerator $generator): void
    {
        $plan = MealPlan::find($this->planId);
        if ($plan === null || $plan->status !== PlanStatus::Pending) {
            return; // apagado, ou já marcado como TIMEOUT pelo plans:fail-stale
        }

        $generator->generate($plan);
    }

    public function failed(?Throwable $exception): void
    {
        MealPlan::whereKey($this->planId)
            ->whereIn('status', [PlanStatus::Pending, PlanStatus::Generating])
            ->update(['status' => PlanStatus::Failed, 'failure_reason' => 'AI_UNAVAILABLE']);
    }
}
```

`app/Console/Commands/FailStalePlans.php`:
```php
<?php

namespace App\Console\Commands;

use App\Enums\PlanStatus;
use App\Models\MealPlan;
use Illuminate\Console\Command;

/** RN19 — plano pedido há mais de 3 min e ainda sem resposta vira `failed` `TIMEOUT`. */
class FailStalePlans extends Command
{
    protected $signature = 'plans:fail-stale';

    protected $description = 'Marca como falhou (TIMEOUT) os planos gerando há mais de 3 minutos';

    public function handle(): int
    {
        $count = MealPlan::whereIn('status', [PlanStatus::Pending, PlanStatus::Generating])
            ->where('created_at', '<', now()->subMinutes(3))
            ->update(['status' => PlanStatus::Failed, 'failure_reason' => 'TIMEOUT']);

        $this->info("{$count} plano(s) marcados como TIMEOUT.");

        return self::SUCCESS;
    }
}
```

`routes/console.php` — acrescentar `use Illuminate\Support\Facades\Schedule;` e, ao fim:
```php
Schedule::command('plans:fail-stale')->everyFiveMinutes();
```

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Feature/Plans`
Expected: PASS. Se "gera um plano válido" ou "origem animal" falharem na validação, o problema é o plano padrão do `FakeAiClient` (Task 3), não o pipeline: ajuste as porções iniciais/limites lá até o plano da Camila e o vegano passarem em RN18 — é o que o E2E vai usar.

- [ ] **Step 4: Suíte, lint e commit**

Run: `docker compose run --rm api php artisan test && docker compose run --rm --no-deps api composer lint`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(plano): geração com IA em 2 tentativas, ativação do mais novo e timeout (RN18–RN20)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 6: API do plano — `POST /plans`, `GET /plans/{plan}`, `GET /plans/active`, posse (RN43) e o 1º plano ao concluir o onboarding

**Files:**
- Create: `app/Services/Nutrition/MealSummary.php`, `app/Http/Resources/PlanResource.php`, `app/Policies/MealPlanPolicy.php`
- Modify: `app/Http/Controllers/Api/V1/PlanController.php`, `app/Http/Controllers/Api/V1/OnboardingController.php`, `app/Services/Profile/OnboardingService.php`, `app/Exceptions/ApiExceptionRenderer.php`, `app/Providers/AppServiceProvider.php` (limite `plans`), `routes/api.php`
- Modify (testes): `tests/Feature/Onboarding/CompleteOnboardingTest.php`
- Test: `tests/Unit/Nutrition/MealSummaryTest.php`, `tests/Feature/Plans/PlanEndpointsTest.php`, `tests/Feature/Api/ErrorFormatTest.php` (dois casos novos)

**Interfaces:**
- Consumes: `PlanService` (Task 5), `DayTotals`, `PortionFormatter` (Task 1).
- Produces:
  - `MealSummary::of(list<string> $names): string` — "Ovos mexidos, pão francês, mamão e café sem açúcar" (RN15).
  - `PlanResource` (`GET /plans/{plan}`): `pending`/`generating` → `{id, status, is_active}`; `failed` → `+ failure_reason`; `ready` → `+ ready_at, targets{kcal, protein_g, carbs_g, fat_g}, meals[{slot, name, time, calories, summary}], rating: null`; com `withItems()`, `meals[].items[{food_id, name, grams, amount, calories, macros{protein, carbs, fat}}]` (`GET /plans/active`).
  - `POST /api/v1/plans` (throttle `plans`: 5/dia/usuário) → `202 { data: { id, status: "pending" } }`.
  - `POST /api/v1/onboarding/complete` → `202 { data: { plan: { id, status } } }` (200 com o plano mais recente se já concluído).
  - `ApiExceptionRenderer`: `ModelNotFoundException` e `AuthorizationException` com status 404 → `404 NOT_FOUND`; `AuthorizationException` 403 → `FORBIDDEN`.

- [ ] **Step 1: Testes que devem falhar**

`tests/Unit/Nutrition/MealSummaryTest.php`:
```php
<?php

use App\Services\Nutrition\MealSummary;

it('junta os nomes com vírgula e "e", minúsculos depois do primeiro (RN15)', function (array $nomes, string $esperado) {
    expect(MealSummary::of($nomes))->toBe($esperado);
})->with([
    [['Ovos mexidos', 'Pão francês', 'Mamão', 'Café sem açúcar'], 'Ovos mexidos, pão francês, mamão e café sem açúcar'],
    [['Frango grelhado', 'Arroz integral'], 'Frango grelhado e arroz integral'],
    [['Banana'], 'Banana'],
    [[], ''],
]);
```

`tests/Feature/Api/ErrorFormatTest.php` — acrescentar:
```php
it('responde 404 NOT_FOUND quando o modelo não existe ou não é do usuário', function () {
    Illuminate\Support\Facades\Route::get('api/v1/_teste/modelo', fn () => App\Models\User::findOrFail(999999));
    Illuminate\Support\Facades\Route::get('api/v1/_teste/policy', fn () => throw (new Illuminate\Auth\Access\AuthorizationException)->withStatus(404));

    $this->getJson('/api/v1/_teste/modelo')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    $this->getJson('/api/v1/_teste/policy')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
});
```

`tests/Feature/Plans/PlanEndpointsTest.php`:
```php
<?php

use App\Jobs\GeneratePlanJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => seedCatalog());

it('pede um plano e responde 202 (RF09)', function () {
    Queue::fake();
    login(User::factory()->onboarded()->create());

    $response = $this->postJson('/api/v1/plans')->assertAccepted()->assertJsonPath('data.status', 'pending');

    Queue::assertPushed(GeneratePlanJob::class, fn ($job) => $job->planId === $response->json('data.id'));
});

it('recusa um segundo pedido enquanto o primeiro gera (RN19)', function () {
    Queue::fake();
    login(User::factory()->onboarded()->create());
    $primeiro = $this->postJson('/api/v1/plans')->json('data.id');

    $this->postJson('/api/v1/plans')
        ->assertStatus(409)
        ->assertJsonPath('code', 'PLAN_ALREADY_GENERATING')
        ->assertJsonPath('details.plan_id', $primeiro);
});

it('recusa antes de concluir o onboarding (RN07)', function () {
    login(User::factory()->answered()->create());

    $this->postJson('/api/v1/plans')->assertStatus(409)->assertJsonPath('code', 'ONBOARDING_INCOMPLETE');
});

it('limita a 5 gerações por dia (RN19)', function () {
    login(User::factory()->onboarded()->create());

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/plans')->assertAccepted();
    }

    $this->postJson('/api/v1/plans')->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
});

it('mostra o plano pronto com metas e refeições, e o ativo com os itens', function () {
    login(User::factory()->onboarded()->create());
    $id = $this->postJson('/api/v1/plans')->json('data.id');

    $this->getJson("/api/v1/plans/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.targets', ['kcal' => 2250, 'protein_g' => 115, 'carbs_g' => 305, 'fat_g' => 65])
        ->assertJsonPath('data.meals.*.time', ['07:00', '10:00', '12:30', '17:30', '20:30'])
        ->assertJsonPath('data.rating', null)
        ->assertJsonStructure(['data' => ['ready_at', 'meals' => [['slot', 'name', 'time', 'calories', 'summary']]]])
        ->assertJsonMissingPath('data.meals.0.items');

    $this->getJson('/api/v1/plans/active')
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonStructure(['data' => ['meals' => [['items' => [['food_id', 'name', 'grams', 'amount', 'calories', 'macros' => ['protein', 'carbs', 'fat']]]]]]]);
});

it('mostra o motivo quando falhou', function () {
    fakeAi()->failNext('plan');
    login(User::factory()->onboarded()->create());
    $id = $this->postJson('/api/v1/plans')->json('data.id');

    $this->getJson("/api/v1/plans/{$id}")
        ->assertExactJson(['data' => ['id' => $id, 'status' => 'failed', 'is_active' => false, 'failure_reason' => 'AI_UNAVAILABLE']]);
});

it('não mostra o plano de outra pessoa: 404 (CA12, RN43)', function () {
    login(User::factory()->onboarded()->create());
    $daAna = $this->postJson('/api/v1/plans')->json('data.id');

    login(User::factory()->onboarded()->create());

    $this->getJson("/api/v1/plans/{$daAna}")->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
});

it('sem plano ativo responde NO_ACTIVE_PLAN', function () {
    login(User::factory()->onboarded()->create());

    $this->getJson('/api/v1/plans/active')->assertStatus(409)->assertJsonPath('code', 'NO_ACTIVE_PLAN');
});
```

Em `tests/Feature/Onboarding/CompleteOnboardingTest.php`, trocar o primeiro teste e o de idempotência por:
```php
it('conclui, cria a primeira pesagem, pede o primeiro plano e responde 202 (RF07, RN34)', function () {
    $user = login(User::factory()->answered()->create());

    $response = $this->postJson('/api/v1/onboarding/complete')
        ->assertAccepted()
        ->assertJsonPath('data.plan.status', 'pending');

    expect($user->profile->fresh()->isOnboarded())->toBeTrue()
        ->and(WeighIn::where('user_id', $user->id)->sole()->weight_kg)->toBe(58.4)
        ->and(WeighIn::where('user_id', $user->id)->sole()->date->isToday())->toBeTrue()
        ->and($user->mealPlans()->sole()->id)->toBe($response->json('data.plan.id'));
    $this->getJson('/api/v1/me')->assertJsonPath('data.onboarding_completed', true)->assertJsonPath('data.next_step', null);
});
```
```php
it('é idempotente: concluir de novo responde 200 com o mesmo plano e não duplica nada', function () {
    $user = login(User::factory()->answered()->create());
    $plano = $this->postJson('/api/v1/onboarding/complete')->json('data.plan.id');

    $this->postJson('/api/v1/onboarding/complete')
        ->assertOk()
        ->assertJsonPath('data.plan.id', $plano);

    expect(WeighIn::where('user_id', $user->id)->count())->toBe(1)
        ->and($user->mealPlans()->count())->toBe(1);
});
```

Run: `docker compose run --rm api php artisan test tests/Unit/Nutrition/MealSummaryTest.php tests/Feature/Plans/PlanEndpointsTest.php tests/Feature/Api/ErrorFormatTest.php tests/Feature/Onboarding/CompleteOnboardingTest.php`
Expected: FAIL — `MealSummary` inexistente, rotas 404/405, `plan` nulo no complete.

- [ ] **Step 2: Implementar**

`app/Services/Nutrition/MealSummary.php`:
```php
<?php

namespace App\Services\Nutrition;

/** RN15 — resumo da refeição: nomes unidos por vírgula e "e". Puro. */
final class MealSummary
{
    /** @param list<string> $names */
    public static function of(array $names): string
    {
        $parts = array_values(array_map(
            fn (string $name, int $i) => $i === 0 ? $name : mb_strtolower(mb_substr($name, 0, 1)).mb_substr($name, 1),
            $names,
            array_keys($names),
        ));

        if (count($parts) <= 1) {
            return $parts[0] ?? '';
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' e '.$last;
    }
}
```

`app/Http/Resources/PlanResource.php`:
```php
<?php

namespace App\Http\Resources;

use App\Enums\PlanStatus;
use App\Models\MealPlan;
use App\Models\PlanMeal;
use App\Models\PlanMealItem;
use App\Services\Nutrition\DayTotals;
use App\Services\Nutrition\MealSummary;
use App\Services\Nutrition\PortionFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plano (`GET /plans/{plan}`, `GET /plans/active`). Pronto: carregue `meals.items.food`.
 *
 * @mixin MealPlan
 */
class PlanResource extends JsonResource
{
    public const RELATIONS = ['meals.items.food'];

    private bool $withItems = false;

    /** `GET /plans/active`: refeições com os itens. */
    public function withItems(): self
    {
        $this->withItems = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = ['id' => $this->id, 'status' => $this->status->value, 'is_active' => $this->is_active];

        if ($this->status === PlanStatus::Failed) {
            return $data + ['failure_reason' => $this->failure_reason];
        }
        if ($this->status !== PlanStatus::Ready) {
            return $data;
        }

        return $data + [
            'ready_at' => $this->ready_at?->toIso8601String(),
            'targets' => [
                'kcal' => $this->target_kcal, 'protein_g' => $this->target_protein_g,
                'carbs_g' => $this->target_carbs_g, 'fat_g' => $this->target_fat_g,
            ],
            'meals' => $this->meals->map(fn (PlanMeal $meal) => $this->meal($meal))->all(),
            'rating' => null, // avaliação 👍/👎: spec 07 (Plano 08)
        ];
    }

    /** @return array<string, mixed> */
    private function meal(PlanMeal $meal): array
    {
        $items = $meal->items->map(fn (PlanMealItem $item) => [
            'food_id' => $item->food_id,
            'name' => $item->food->name,
            'grams' => $item->grams,
            'amount' => app(PortionFormatter::class)->forFood($item->food, $item->grams),
            'macros' => DayTotals::item($item->food, $item->grams),
        ]);

        $data = [
            'slot' => $meal->slot,
            'name' => $meal->name,
            'time' => substr((string) $meal->time, 0, 5),
            'calories' => DayTotals::sum($items->pluck('macros')->all())['calories'],
            'summary' => MealSummary::of($items->pluck('name')->all()),
        ];

        if ($this->withItems) {
            $data['items'] = $items->map(fn (array $item) => [
                'food_id' => $item['food_id'], 'name' => $item['name'], 'grams' => $item['grams'], 'amount' => $item['amount'],
                'calories' => $item['macros']['calories'],
                'macros' => ['protein' => $item['macros']['protein'], 'carbs' => $item['macros']['carbs'], 'fat' => $item['macros']['fat']],
            ])->all();
        }

        return $data;
    }
}
```

`app/Policies/MealPlanPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** RN43 — plano de outra pessoa não existe para quem pergunta (404, não 403). */
class MealPlanPolicy
{
    public function view(User $user, MealPlan $plan): Response
    {
        return $plan->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
```

Em `app/Exceptions/ApiExceptionRenderer.php`, acrescentar os `use` (`Illuminate\Auth\Access\AuthorizationException`, `Illuminate\Database\Eloquent\ModelNotFoundException`) e, no `match`, antes da linha de `HttpExceptionInterface`:
```php
            $e instanceof ModelNotFoundException => $this->respond(ErrorCode::NotFound),
            $e instanceof AuthorizationException => $this->respond($e->status() === 404 ? ErrorCode::NotFound : ErrorCode::Forbidden),
```

Em `app/Providers/AppServiceProvider.php`, em `configureRateLimiting()`:
```php
        RateLimiter::for('plans', fn (Request $request) => Limit::perDay(5)->by('plans|'.$request->user()?->getAuthIdentifier()));
```

`app/Http/Controllers/Api/V1/PlanController.php` — acrescentar os `use` (`App\Http\Resources\PlanResource`, `App\Models\MealPlan`, `App\Services\Plans\PlanService`, `Illuminate\Support\Facades\Gate`) e os métodos:
```php
    public function store(Request $request, PlanService $plans): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $plan = $plans->requestGeneration($user);

        return response()->json(['data' => ['id' => $plan->id, 'status' => $plan->status->value]], 202);
    }

    public function show(MealPlan $plan): PlanResource
    {
        Gate::authorize('view', $plan);

        return new PlanResource($plan->load(PlanResource::RELATIONS));
    }

    public function active(Request $request): PlanResource
    {
        /** @var User $user */
        $user = $request->user();
        $plan = $user->activePlan()->with(PlanResource::RELATIONS)->first()
            ?? throw new DomainException(ErrorCode::NoActivePlan);

        return (new PlanResource($plan))->withItems();
    }
```

`app/Services/Profile/OnboardingService.php` — injetar o `PlanService` e devolver o plano:
- construtor: `public function __construct(private readonly GoalWeightResolver $goalWeights, private readonly PlanService $plans) {}` (com `use App\Services\Plans\PlanService;` e `use App\Models\MealPlan;`);
- assinatura e início de `complete`:
```php
    /** Idempotente: quem já concluiu recebe o plano mais recente. */
    public function complete(User $user): ?MealPlan
    {
        $profile = $user->profile;
        if ($profile->isOnboarded()) {
            return $user->mealPlans()->latest('id')->first();
        }
```
- ao fim de `complete`, depois do `DB::transaction(...)`:
```php
        // RF09 — o primeiro plano sai daqui (spec 02 §5).
        return $this->plans->requestGeneration($user);
```

`app/Http/Controllers/Api/V1/OnboardingController.php` — trocar o corpo de `complete` por:
```php
        /** @var User $user */
        $user = $request->user();
        $alreadyDone = $user->profile->isOnboarded();

        $plan = $onboarding->complete($user);

        return response()->json(
            ['data' => ['plan' => $plan ? ['id' => $plan->id, 'status' => $plan->status->value] : null]],
            $alreadyDone ? 200 : 202,
        );
```

`routes/api.php` — `use` de `App\Http\Controllers\Api\V1\PlanController` já existe; no grupo `auth:sanctum`, trocar a linha da prévia por:
```php
        Route::get('plans/preview-targets', [PlanController::class, 'previewTargets']);
        Route::get('plans/active', [PlanController::class, 'active']);
        Route::get('plans/{plan}', [PlanController::class, 'show'])->whereNumber('plan');
        Route::post('plans', [PlanController::class, 'store'])->middleware('throttle:plans');
```

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Unit/Nutrition tests/Feature/Plans tests/Feature/Api tests/Feature/Onboarding`
Expected: PASS.

- [ ] **Step 4: Suíte, lint e commit**

Run: `docker compose run --rm api php artisan test && docker compose run --rm --no-deps api composer lint`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(plano): pedir, acompanhar e ler o plano; onboarding concluído já pede o primeiro (RF09, RN43)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 7: O dia — esquema, `DayMaterializer` (RN22, RN15) e `GET /days/{date}`

**Files:**
- Create: `database/migrations/2026_09_30_000300_create_day_tables.php`
- Create: `app/Models/{DayMeal,DayMealItem,DayMealChange}.php`; Modify: `app/Models/User.php`
- Create: `app/Support/DayDate.php`, `app/Services/Days/{DayMaterializer,DayView}.php`, `app/Http/Resources/DayResource.php`, `app/Http/Controllers/Api/V1/DayController.php`
- Modify: `routes/api.php`, `tests/Feature/Auth/DeleteAccountTest.php`, `tests/Pest.php`
- Test: `tests/Feature/Days/DayTest.php`

**Interfaces:**
- Consumes: `PlanService` (Task 5), `DayTotals`, `PortionFormatter`, `MealSummary` (Tasks 1, 6).
- Produces:
  - Tabelas `day_meals` (`UNIQUE(user_id, date, slot)`), `day_meal_items`, `day_meal_changes`. Models `DayMeal` (`items()`, `plan()`, `isDone()`), `DayMealItem` (`food()`, `replacedFood()`, `dayMeal()`, `source` → `ItemSource`), `DayMealChange` (`items_before` array, `type` → `DayChangeType`; `scopeUndoableFor(User, CarbonImmutable)` = do dia, não desfeita, de até 15 min, a mais recente). Em `User`: `dayMeals()`.
  - `DayDate::parse(string): CarbonImmutable` — `today` ou `YYYY-MM-DD` em [hoje − 90, hoje + 6] (fuso de São Paulo); fora disso, 422 no campo `date`.
  - `DayMaterializer::view(User, CarbonImmutable): DayView` e `meals(User, CarbonImmutable): Collection<int, DayMeal>` — hoje grava uma vez (corrida entre abas não duplica); futuro é prévia sem gravar; passado é o gravado (ou vazio); sem plano ativo em hoje/futuro, 409 `NO_ACTIVE_PLAN` com `details.plan_status` e `details.plan_id` do último plano.
  - `DayMaterializer::build(User, MealPlan, CarbonImmutable, bool $save, ?list<string> $onlySlots = null)` — cria (ou só monta) as refeições do dia a partir do plano, com nome e nota do dia (RN15). Reusado pelo RN20 (Task 10).
  - `final readonly class DayView(CarbonImmutable $date, Collection $meals, ?MealPlan $plan, bool $materialized, bool $isTrainingDay, ?DayMealChange $lastChange)`.
  - `DayResource` no formato da spec 03 §5 (`last_change` = `null` quando não há o que desfazer).
  - `GET /api/v1/days/{date}` (exige onboarding). Helper de teste `planoPronto(User): MealPlan`.

- [ ] **Step 1: Testes que devem falhar**

`tests/Pest.php` — acrescentar ao fim:
```php
/** Pede e gera (fila síncrona + IA falsa) o plano do usuário; devolve o plano já ativo. */
function planoPronto(\App\Models\User $user): \App\Models\MealPlan
{
    return app(\App\Services\Plans\PlanService::class)->requestGeneration($user)->fresh();
}
```

`tests/Feature/Auth/DeleteAccountTest.php` — dataset:
```php
    'day_meals' => ['day_meals', 'user_id'],
    'day_meal_changes' => ['day_meal_changes', 'user_id'],
```
e, no teste de CA08, depois de criar o `mealPlans()`, trocar aquela linha por um plano de verdade com o dia materializado:
```php
    planoPronto($user);
    $this->getJson('/api/v1/days/today')->assertOk();
    $meal = \App\Models\DayMeal::where('user_id', $user->id)->firstOrFail();
    \App\Models\DayMealChange::create(['user_id' => $user->id, 'date' => today(), 'day_meal_id' => $meal->id, 'type' => 'swap', 'description' => 'x', 'items_before' => []]);
```

`tests/Feature/Days/DayTest.php`:
```php
<?php

use App\Models\DayMeal;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Sao_Paulo')); // segunda-feira, dia de treino
    $this->user = login(User::factory()->onboarded()->create()); // treina seg/qua/sex às 19:00
    $this->plano = planoPronto($this->user);
});

it('materializa hoje na primeira leitura, uma vez só (RN22)', function () {
    $primeira = $this->getJson('/api/v1/days/today')->assertOk()->json('data.meals.*.id');
    $segunda = $this->getJson('/api/v1/days/2026-09-28')->json('data.meals.*.id');

    expect($primeira)->toBe($segunda)->and(DayMeal::where('user_id', $this->user->id)->count())->toBe(5);
});

it('mostra hoje com as 5 refeições em ordem, a próxima e os totais (RN15, RN24)', function () {
    $dia = $this->getJson('/api/v1/days/today')
        ->assertJsonPath('data.date', '2026-09-28')
        ->assertJsonPath('data.is_today', true)
        ->assertJsonPath('data.editable', true)
        ->assertJsonPath('data.materialized', true)
        ->assertJsonPath('data.is_training_day', true)
        ->assertJsonPath('data.meals.*.slot', ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar'])
        ->assertJsonPath('data.meals.*.is_next', [true, false, false, false, false])
        ->assertJsonPath('data.targets', ['kcal' => 2250, 'protein_g' => 115, 'carbs_g' => 305, 'fat_g' => 65])
        ->assertJsonPath('data.totals.consumed.calories', 0)
        ->assertJsonPath('data.last_change', null)
        ->json('data');

    expect($dia['totals']['planned']['calories'])->toBe(array_sum(array_column($dia['meals'], 'calories')))
        ->and($dia['totals']['remaining'])->toBe($dia['totals']['planned'])
        ->and($dia['meals'][0]['items'][0])->toHaveKeys(['id', 'food_id', 'name', 'grams', 'amount', 'calories', 'macros', 'source', 'replaced_from'])
        ->and($dia['meals'][0]['items'][0]['source'])->toBe('plan');
});

it('no dia de treino o jantar tem a nota do treino (CA04)', function () {
    $this->getJson('/api/v1/days/today')
        ->assertJsonPath('data.meals.4.name', 'Jantar')
        ->assertJsonPath('data.meals.4.note', 'Depois do treino das 19h')
        ->assertJsonPath('data.meals.3.name', 'Pré-treino');
});

it('terça sem treino é prévia, com "Lanche da tarde" e sem nota, e não grava nada (CA05, RN22)', function () {
    $this->getJson('/api/v1/days/2026-09-29')
        ->assertOk()
        ->assertJsonPath('data.is_today', false)
        ->assertJsonPath('data.editable', false)
        ->assertJsonPath('data.materialized', false)
        ->assertJsonPath('data.is_training_day', false)
        ->assertJsonPath('data.meals.3.name', 'Lanche da tarde')
        ->assertJsonPath('data.meals.4.note', null)
        ->assertJsonPath('data.meals.0.id', null)
        ->assertJsonPath('data.meals.*.is_next', [false, false, false, false, false]);

    expect(DayMeal::whereDate('date', '2026-09-29')->exists())->toBeFalse();
});

it('passado sem registro vem vazio e só para leitura', function () {
    $this->getJson('/api/v1/days/2026-09-27')
        ->assertOk()
        ->assertJsonPath('data.meals', [])
        ->assertJsonPath('data.materialized', false)
        ->assertJsonPath('data.editable', false);
});

it('recusa datas fora de [hoje − 90, hoje + 6] com 422', function (string $data) {
    $this->getJson("/api/v1/days/{$data}")->assertUnprocessable()->assertJsonValidationErrors(['date']);
})->with(['2026-06-29', '2026-10-05', '2026-02-30', 'ontem']);

it('aceita as bordas do intervalo', function (string $data) {
    $this->getJson("/api/v1/days/{$data}")->assertOk();
})->with(['2026-06-30', '2026-10-04']);

it('"hoje" é o dia de São Paulo, também perto da meia-noite (Review Focus 3)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 23:59', 'America/Sao_Paulo'));
    $this->getJson('/api/v1/days/today')->assertJsonPath('data.date', '2026-09-28');

    $this->travelTo(CarbonImmutable::parse('2026-09-29 03:01', 'UTC')); // 00:01 em São Paulo
    $this->getJson('/api/v1/days/today')->assertJsonPath('data.date', '2026-09-29');
    $this->getJson('/api/v1/days/2026-09-28')->assertJsonPath('data.editable', false)->assertJsonPath('data.materialized', true);
});

it('sem plano ativo responde NO_ACTIVE_PLAN com o status do último plano', function () {
    fakeAi()->failNext('plan');
    $outro = login(User::factory()->onboarded()->create());
    $falhou = app(App\Services\Plans\PlanService::class)->requestGeneration($outro);

    $this->getJson('/api/v1/days/today')
        ->assertStatus(409)
        ->assertJsonPath('code', 'NO_ACTIVE_PLAN')
        ->assertJsonPath('details.plan_status', 'failed')
        ->assertJsonPath('details.plan_id', $falhou->id);
});

it('exige o onboarding concluído (RN07)', function () {
    login(User::factory()->answered()->create());

    $this->getJson('/api/v1/days/today')->assertStatus(409)->assertJsonPath('code', 'ONBOARDING_INCOMPLETE');
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Days`
Expected: FAIL — rota inexistente (404).

- [ ] **Step 2: Esquema e models**

`database/migrations/2026_09_30_000300_create_day_tables.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->string('slot', 12);
            $table->string('name', 40);
            $table->time('time');
            $table->string('note', 80)->nullable();
            $table->unsignedTinyInteger('position');
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'date', 'slot']); // RN22: o dia é materializado uma vez
            $table->index(['user_id', 'date']);
        });

        Schema::create('day_meal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('day_meal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('foods')->restrictOnDelete();
            $table->decimal('grams', 6, 1);
            $table->foreignId('replaced_food_id')->nullable()->constrained('foods')->restrictOnDelete();
            $table->string('source', 8);
            $table->unsignedTinyInteger('position');
        });

        Schema::create('day_meal_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('day_meal_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12);
            $table->string('description', 160);
            $table->json('items_before');
            $table->timestamp('undone_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'date', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_meal_changes');
        Schema::dropIfExists('day_meal_items');
        Schema::dropIfExists('day_meals');
    }
};
```

`app/Models/DayMeal.php`:
```php
<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Refeição de uma data concreta (RN22).
 *
 * @property CarbonImmutable $date
 */
class DayMeal extends Model
{
    protected $fillable = ['user_id', 'date', 'meal_plan_id', 'slot', 'name', 'time', 'note', 'position', 'done_at'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'done_at' => 'datetime'];
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    /** @return HasMany<DayMealItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(DayMealItem::class)->orderBy('position');
    }

    /** @return BelongsTo<MealPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class, 'meal_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

`app/Models/DayMealItem.php`:
```php
<?php

namespace App\Models;

use App\Enums\ItemSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DayMealItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['food_id', 'grams', 'replaced_food_id', 'source', 'position'];

    protected function casts(): array
    {
        return ['grams' => 'float', 'source' => ItemSource::class];
    }

    /** @return BelongsTo<Food, $this> */
    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    /** Alimento original da refeição-modelo, quando foi trocado (RN26). @return BelongsTo<Food, $this> */
    public function replacedFood(): BelongsTo
    {
        return $this->belongsTo(Food::class, 'replaced_food_id');
    }

    /** @return BelongsTo<DayMeal, $this> */
    public function dayMeal(): BelongsTo
    {
        return $this->belongsTo(DayMeal::class);
    }
}
```

`app/Models/DayMealChange.php`:
```php
<?php

namespace App\Models;

use App\Enums\DayChangeType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alteração de conteúdo do dia, com o antes, para o "Desfazer" (RN27).
 *
 * @property list<array{food_id: int, grams: float, replaced_food_id: int|null, source: string, position: int}> $items_before
 */
class DayMealChange extends Model
{
    public const UPDATED_AT = null;

    public const UNDO_MINUTES = 15;

    protected $fillable = ['user_id', 'date', 'day_meal_id', 'type', 'description', 'items_before', 'undone_at'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'type' => DayChangeType::class, 'items_before' => 'array', 'undone_at' => 'datetime', 'created_at' => 'immutable_datetime'];
    }

    /**
     * A última alteração do dia, ainda não desfeita, feita há no máximo 15 min (RN27).
     *
     * @param  Builder<self>  $query
     */
    public function scopeUndoableFor(Builder $query, User $user, CarbonImmutable $date): void
    {
        $query->where('user_id', $user->id)
            ->whereDate('date', $date)
            ->whereNull('undone_at')
            ->where('created_at', '>=', now()->subMinutes(self::UNDO_MINUTES))
            ->latest('id')
            ->limit(1);
    }

    /** @return BelongsTo<DayMeal, $this> */
    public function dayMeal(): BelongsTo
    {
        return $this->belongsTo(DayMeal::class);
    }
}
```

Em `app/Models/User.php`:
```php
    /** @return HasMany<DayMeal, $this> */
    public function dayMeals(): HasMany
    {
        return $this->hasMany(DayMeal::class);
    }
```

- [ ] **Step 3: Datas, materialização, resource e rota**

`app/Support/DayDate.php`:
```php
<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/** `{date}` das rotas do dia: `today` ou `YYYY-MM-DD` entre hoje − 90 e hoje + 6 (fuso da comunidade). */
final class DayDate
{
    public static function parse(string $value): CarbonImmutable
    {
        $today = CarbonImmutable::today();
        if ($value === 'today') {
            return $today;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $date = null;
        }

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value
            || $date->lt($today->subDays(90)) || $date->gt($today->addDays(6))) {
            throw ValidationException::withMessages(['date' => 'Escolha um dia entre os últimos 90 e os próximos 6.']);
        }

        return $date;
    }
}
```

`app/Services/Days/DayView.php`:
```php
<?php

namespace App\Services\Days;

use App\Models\DayMeal;
use App\Models\DayMealChange;
use App\Models\MealPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final readonly class DayView
{
    /** @param Collection<int, DayMeal> $meals em ordem de horário */
    public function __construct(
        public CarbonImmutable $date,
        public Collection $meals,
        public ?MealPlan $plan,
        public bool $materialized,
        public bool $isTrainingDay,
        public ?DayMealChange $lastChange,
    ) {}
}
```

`app/Services/Days/DayMaterializer.php`:
```php
<?php

namespace App\Services\Days;

use App\Enums\ErrorCode;
use App\Enums\ItemSource;
use App\Enums\MealSlot;
use App\Exceptions\DomainException;
use App\Models\DayMeal;
use App\Models\DayMealChange;
use App\Models\DayMealItem;
use App\Models\MealPlan;
use App\Models\PlanMeal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** RN22 (hoje, futuro, passado) e RN15 (nome e nota de cada dia). */
class DayMaterializer
{
    public function view(User $user, CarbonImmutable $date): DayView
    {
        $meals = $this->meals($user, $date);
        $plan = $meals->first()?->plan ?? $user->activePlan()->first();

        return new DayView(
            $date,
            $meals,
            $plan,
            $meals->isNotEmpty() && $meals->first()->exists,
            $this->isTrainingDay($user, $date),
            $date->isToday() ? DayMealChange::undoableFor($user, $date)->first() : null,
        );
    }

    /**
     * Refeições da data, em ordem de horário, com `items.food` e `items.replacedFood`.
     *
     * @return Collection<int, DayMeal>
     */
    public function meals(User $user, CarbonImmutable $date): Collection
    {
        $stored = $this->stored($user, $date);
        if ($stored->isNotEmpty() || $date->lt(CarbonImmutable::today())) {
            return $stored; // passado: o que foi gravado (ou nada)
        }

        $plan = $user->activePlan()->with('meals.items.food')->first() ?? throw $this->noActivePlan($user);

        if (! $date->isToday()) {
            return $this->build($user, $plan, $date, save: false); // futuro: prévia, sem gravar
        }

        try {
            DB::transaction(fn () => $this->build($user, $plan, $date, save: true));
        } catch (UniqueConstraintViolationException) {
            // Outra aba materializou ao mesmo tempo: fica o que ela gravou.
        }

        return $this->stored($user, $date);
    }

    /**
     * Refeições do dia a partir do plano (RN15: "Lanche da tarde" sem treino; nota do jantar pós-treino).
     *
     * @param  list<string>|null  $onlySlots
     * @return Collection<int, DayMeal>
     */
    public function build(User $user, MealPlan $plan, CarbonImmutable $date, bool $save, ?array $onlySlots = null): Collection
    {
        $plan->loadMissing('meals.items.food');
        $training = $this->isTrainingDay($user, $date);
        $trainingTime = substr((string) $user->profile->training_time, 0, 5);

        return $plan->meals
            ->filter(fn (PlanMeal $meal) => $onlySlots === null || in_array($meal->slot, $onlySlots, true))
            ->map(function (PlanMeal $planMeal) use ($user, $plan, $date, $save, $training, $trainingTime) {
                $meal = new DayMeal([
                    'user_id' => $user->id,
                    'date' => $date,
                    'meal_plan_id' => $plan->id,
                    'slot' => $planMeal->slot,
                    'name' => $planMeal->slot === MealSlot::PreTreino->value && ! $training ? 'Lanche da tarde' : $planMeal->name,
                    'time' => substr((string) $planMeal->time, 0, 5),
                    'note' => $this->note($planMeal, $training, $trainingTime),
                    'position' => $planMeal->position,
                ]);
                $meal->setRelation('plan', $plan);

                $items = $planMeal->items->map(fn ($planItem) => (new DayMealItem([
                    'food_id' => $planItem->food_id,
                    'grams' => $planItem->grams,
                    'source' => ItemSource::Plan,
                    'position' => $planItem->position,
                ]))->setRelation('food', $planItem->food)->setRelation('replacedFood', null));

                if ($save) {
                    $meal->save();
                    $meal->items()->saveMany($items);
                }

                return $meal->setRelation('items', $items->values());
            })
            ->values();
    }

    public function isTrainingDay(User $user, CarbonImmutable $date): bool
    {
        return in_array($date->dayOfWeek, $user->profile->training_days, true);
    }

    /** @return Collection<int, DayMeal> */
    private function stored(User $user, CarbonImmutable $date): Collection
    {
        return $user->dayMeals()
            ->whereDate('date', $date)
            ->with(['items.food', 'items.replacedFood', 'plan'])
            ->orderBy('position')
            ->get();
    }

    /** "Depois do treino das 19h" no jantar que vem depois do treino, em dia de treino. */
    private function note(PlanMeal $meal, bool $training, string $trainingTime): ?string
    {
        if (! $training || $meal->slot !== MealSlot::Jantar->value) {
            return null;
        }

        [$hours, $minutes] = array_map('intval', explode(':', $trainingTime));
        [$mealHours, $mealMinutes] = array_map('intval', explode(':', substr((string) $meal->time, 0, 5)));
        $after = ((($mealHours * 60 + $mealMinutes) - ($hours * 60 + $minutes)) + 1440) % 1440;

        return $after > 0 && $after < 12 * 60
            ? 'Depois do treino das '.$hours.'h'.($minutes > 0 ? sprintf('%02d', $minutes) : '')
            : null;
    }

    private function noActivePlan(User $user): DomainException
    {
        $latest = $user->mealPlans()->latest('id')->first();

        return new DomainException(ErrorCode::NoActivePlan, ['plan_status' => $latest?->status->value, 'plan_id' => $latest?->id]);
    }
}
```

`app/Http/Resources/DayResource.php`:
```php
<?php

namespace App\Http\Resources;

use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Services\Days\DayView;
use App\Services\Nutrition\DayTotals;
use App\Services\Nutrition\MealSummary;
use App\Services\Nutrition\PortionFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O dia (spec 03 §5, `GET /days/{date}` e todas as escritas do dia).
 *
 * @property DayView $resource
 */
class DayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $view = $this->resource;
        $isToday = $view->date->isToday();
        $next = $isToday ? $view->meals->first(fn (DayMeal $meal) => ! $meal->isDone()) : null;

        $meals = $view->meals->map(fn (DayMeal $meal) => $this->meal($meal, $next !== null && $meal->slot === $next->slot));
        $planned = DayTotals::sum($meals->pluck('totals')->all());
        $consumed = DayTotals::sum($meals->filter(fn (array $meal) => $meal['done'])->pluck('totals')->all());

        return [
            'date' => $view->date->toDateString(),
            'is_today' => $isToday,
            'editable' => $isToday,
            'materialized' => $view->materialized,
            'is_training_day' => $view->isTrainingDay,
            'targets' => $view->plan ? [
                'kcal' => $view->plan->target_kcal, 'protein_g' => $view->plan->target_protein_g,
                'carbs_g' => $view->plan->target_carbs_g, 'fat_g' => $view->plan->target_fat_g,
            ] : null,
            'totals' => [
                'planned' => $planned,
                'consumed' => $consumed,
                'remaining' => DayTotals::remaining($planned, $consumed),
            ],
            'meals' => $meals->map(fn (array $meal) => array_diff_key($meal, ['totals' => true]))->all(),
            'last_change' => $view->lastChange ? [
                'id' => $view->lastChange->id,
                'text' => $view->lastChange->description,
                'undo_until' => $view->lastChange->created_at->addMinutes(15)->toIso8601String(),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function meal(DayMeal $meal, bool $isNext): array
    {
        $items = $meal->items->map(fn (DayMealItem $item) => $this->item($item));
        $totals = DayTotals::sum($items->pluck('totals')->all());

        return [
            'id' => $meal->id,
            'slot' => $meal->slot,
            'name' => $meal->name,
            'time' => substr((string) $meal->time, 0, 5),
            'note' => $meal->note,
            'position' => $meal->position,
            'done' => $meal->isDone(),
            'is_next' => $isNext,
            'summary' => MealSummary::of($items->pluck('name')->all()),
            'calories' => $totals['calories'],
            'macros' => ['protein' => $totals['protein'], 'carbs' => $totals['carbs'], 'fat' => $totals['fat']],
            'items' => $items->map(fn (array $item) => array_diff_key($item, ['totals' => true]))->all(),
            'totals' => $totals,
        ];
    }

    /** @return array<string, mixed> */
    private function item(DayMealItem $item): array
    {
        $totals = DayTotals::item($item->food, $item->grams);

        return [
            'id' => $item->id,
            'food_id' => $item->food_id,
            'name' => $item->food->name,
            'grams' => $item->grams,
            'amount' => app(PortionFormatter::class)->forFood($item->food, $item->grams),
            'calories' => $totals['calories'],
            'macros' => ['protein' => $totals['protein'], 'carbs' => $totals['carbs'], 'fat' => $totals['fat']],
            'source' => $item->source->value,
            'replaced_from' => $item->replacedFood?->name,
            'totals' => $totals,
        ];
    }
}
```

`app/Http/Controllers/Api/V1/DayController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DayResource;
use App\Models\User;
use App\Services\Days\DayMaterializer;
use App\Support\DayDate;
use Illuminate\Http\Request;

class DayController extends Controller
{
    public function show(Request $request, string $date, DayMaterializer $days): DayResource
    {
        /** @var User $user */
        $user = $request->user();

        return new DayResource($days->view($user, DayDate::parse($date)));
    }
}
```

`routes/api.php` — `use App\Http\Controllers\Api\V1\DayController;` e, dentro do grupo `onboarded`:
```php
            Route::get('days/{date}', [DayController::class, 'show']);
```

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Feature/Days tests/Feature/Auth/DeleteAccountTest.php`
Expected: PASS.

- [ ] **Step 5: Suíte, lint e commit**

Run: `docker compose run --rm api php artisan test && docker compose run --rm --no-deps api composer lint`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(dia): dia materializado a partir do plano, prévia do futuro e histórico (RN15, RN22)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 8: Marcar e desmarcar refeição — `DayService` e `PATCH /days/{date}/meals/{slot}` (RN23, RN24)

**Files:**
- Create: `app/Services/Days/DayService.php`
- Modify: `app/Http/Controllers/Api/V1/DayController.php`, `routes/api.php`
- Test: `tests/Feature/Days/ToggleMealTest.php`

**Interfaces:**
- Consumes: `DayMaterializer` (Task 7).
- Produces:
  - `DayService::assertEditable(CarbonImmutable): void` (409 `DAY_NOT_EDITABLE` fora de hoje — RN23; checagem central de toda escrita do dia).
  - `DayService::setDone(User, CarbonImmutable, string $slot, bool $done): void` (marcar de novo não muda a hora registrada).
  - `PATCH /api/v1/days/{date}/meals/{slot}` `{ done: boolean }` → o dia completo (`DayResource`); slot fora de `MealSlot` → 404.

- [ ] **Step 1: Testes que devem falhar**

`tests/Feature/Days/ToggleMealTest.php`:
```php
<?php

use App\Models\DayMeal;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 13:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
});

it('marcar o almoço soma exatamente as kcal dele e persiste (CA06)', function () {
    $almoco = $this->getJson('/api/v1/days/today')->json('data.meals.2');

    $this->patchJson('/api/v1/days/today/meals/almoco', ['done' => true])
        ->assertOk()
        ->assertJsonPath('data.meals.2.done', true)
        ->assertJsonPath('data.totals.consumed.calories', $almoco['calories'])
        ->assertJsonPath('data.totals.consumed.protein', $almoco['macros']['protein']);

    $this->getJson('/api/v1/days/today')->assertJsonPath('data.meals.2.done', true);
});

it('a próxima refeição é a primeira não feita', function () {
    $this->patchJson('/api/v1/days/today/meals/cafe', ['done' => true]);

    $this->patchJson('/api/v1/days/today/meals/lanche', ['done' => true])
        ->assertJsonPath('data.meals.*.is_next', [false, false, true, false, false]);
});

it('desmarcar volta o consumido a zero', function () {
    $this->patchJson('/api/v1/days/today/meals/almoco', ['done' => true]);

    $this->patchJson('/api/v1/days/today/meals/almoco', ['done' => false])
        ->assertJsonPath('data.meals.2.done', false)
        ->assertJsonPath('data.totals.consumed.calories', 0);
});

it('marcar de novo não muda a hora em que foi feita', function () {
    $this->patchJson('/api/v1/days/today/meals/cafe', ['done' => true]);
    $primeira = DayMeal::where('slot', 'cafe')->sole()->done_at;
    $this->travel(10)->minutes();

    $this->patchJson('/api/v1/days/today/meals/cafe', ['done' => true]);

    expect(DayMeal::where('slot', 'cafe')->sole()->done_at->equalTo($primeira))->toBeTrue();
});

it('ontem e amanhã não são editáveis (CA07, RN23)', function (string $data) {
    $this->patchJson("/api/v1/days/{$data}/meals/almoco", ['done' => true])
        ->assertStatus(409)
        ->assertJsonPath('code', 'DAY_NOT_EDITABLE');
})->with(['2026-09-27', '2026-09-29']);

it('slot que não existe responde 404 e "done" é obrigatório', function () {
    $this->patchJson('/api/v1/days/today/meals/ceia', ['done' => true])->assertNotFound();
    $this->patchJson('/api/v1/days/today/meals/cafe', [])->assertJsonValidationErrors(['done']);
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Days/ToggleMealTest.php`
Expected: FAIL — rota inexistente.

- [ ] **Step 2: Implementar**

`app/Services/Days/DayService.php`:
```php
<?php

namespace App\Services\Days;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Única porta de escrita do dia (RN23, RN26, RN27). */
class DayService
{
    public function __construct(private readonly DayMaterializer $days) {}

    /** RN23 — só o dia de hoje (São Paulo) muda. */
    public function assertEditable(CarbonImmutable $date): void
    {
        if (! $date->isToday()) {
            throw new DomainException(ErrorCode::DayNotEditable);
        }
    }

    /** RF13 — marcar ou desmarcar uma refeição. */
    public function setDone(User $user, CarbonImmutable $date, string $slot, bool $done): void
    {
        $this->assertEditable($date);
        $meal = $this->days->meals($user, $date)->firstWhere('slot', $slot) ?? throw new NotFoundHttpException;

        $meal->update(['done_at' => $done ? ($meal->done_at ?? now()) : null]);
    }
}
```

Em `app/Http/Controllers/Api/V1/DayController.php`, acrescentar os `use` (`App\Services\Days\DayService`) e:
```php
    public function toggleMeal(Request $request, string $date, string $slot, DayService $service, DayMaterializer $days): DayResource
    {
        $data = $request->validate(['done' => ['required', 'boolean']], ['done.*' => 'Diga se a refeição foi feita.']);
        /** @var User $user */
        $user = $request->user();
        $day = DayDate::parse($date);

        $service->setDone($user, $day, $slot, (bool) $data['done']);

        return new DayResource($days->view($user, $day));
    }
```

`routes/api.php` — `use App\Enums\MealSlot;` e, no grupo `onboarded`:
```php
            Route::patch('days/{date}/meals/{slot}', [DayController::class, 'toggleMeal'])
                ->whereIn('slot', array_map(fn (MealSlot $slot) => $slot->value, MealSlot::cases()));
```

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Feature/Days`
Expected: PASS.

- [ ] **Step 4: Lint e commit**

Run: `docker compose run --rm --no-deps api composer lint`
Expected: OK.

```bash
git add -A && git commit -m "feat(dia): marcar e desmarcar refeição de hoje (RF13, RN23)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 9: Trocas e desfazer — substituições, `swap`, `undo` (RN25–RN27) e o invariante da alergia (RN17)

**Files:**
- Modify: `app/Services/Days/DayService.php`, `app/Http/Controllers/Api/V1/DayController.php`, `routes/api.php`
- Create: `app/Http/Resources/SubstitutionsResource.php`
- Test: `tests/Feature/Days/{SubstitutionsTest,SwapTest,UndoTest}.php`, `tests/Feature/Plans/AllergyInvariantTest.php`

**Interfaces:**
- Consumes: `FoodFilter`, `SubstitutionFinder` (Task 2); `DayMealChange::undoableFor` (Task 7); `DayService` (Task 8).
- Produces:
  - `DayService::item(User, CarbonImmutable, int $itemId): DayMealItem` (404 se não for do usuário ou da data — RN43).
  - `DayService::substitutions(User, CarbonImmutable, int $itemId): array{item: DayMealItem, options: list<SubstitutionOption>, restrictions: list<string>}` — `restrictions` = rótulos das restrições + "outras restrições" (garantia com **todas**).
  - `DayService::swap(User, CarbonImmutable, int $itemId, int $foodId): void` — só aceita `food_id` entre as opções válidas agora (409 `SUBSTITUTION_NOT_ALLOWED`); grava `day_meal_changes` (`swap`, "{Original} trocado por {novo}", snapshot da refeição); `replaced_food_id` guarda o original da refeição-modelo; `source = manual`; não muda "feita".
  - `DayService::undo(User, CarbonImmutable): void` — desfaz a última alteração não desfeita de até 15 min (409 `NOTHING_TO_UNDO`).
  - `DayService::snapshot(DayMeal): list<array>` (reusado pelo Nutri no Plano 05).
  - Rotas: `GET /days/{date}/items/{item}/substitutions`, `POST /days/{date}/items/{item}/swap` `{ food_id }`, `POST /days/{date}/undo` (as escritas devolvem o dia completo).

- [ ] **Step 1: Testes que devem falhar**

`tests/Pest.php` — acrescentar ao fim:
```php
/** Primeiro item de hoje cujo alimento é do grupo dado (materializa o dia se preciso). */
function itemDeHoje(string $grupo): \App\Models\DayMealItem
{
    test()->getJson('/api/v1/days/today')->assertOk();

    return \App\Models\DayMealItem::with('food')
        ->whereHas('food', fn ($q) => $q->where('group', $grupo))
        ->whereHas('dayMeal', fn ($q) => $q->where('user_id', auth('web')->id())->whereDate('date', today()))
        ->orderBy('id')
        ->firstOrFail();
}

/** Troca o item pela primeira opção da folha e devolve a opção escolhida. */
function trocarPelaPrimeiraOpcao(int $itemId): array
{
    $opcao = test()->getJson("/api/v1/days/today/items/{$itemId}/substitutions")->json('data.options.0');
    test()->postJson("/api/v1/days/today/items/{$itemId}/swap", ['food_id' => $opcao['food_id']])->assertOk();

    return $opcao;
}

/** Itens de uma refeição de hoje como a API mostra (sem ids, que mudam ao desfazer). */
function itensDaRefeicao(string $slot): array
{
    $meal = collect(test()->getJson('/api/v1/days/today')->json('data.meals'))->firstWhere('slot', $slot);

    return array_map(fn ($i) => [$i['food_id'], $i['grams'], $i['source'], $i['replaced_from']], $meal['items']);
}
```

`tests/Feature/Days/SubstitutionsTest.php`:
```php
<?php

use App\Models\Restriction;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    $this->user->restrictions()->attach(Restriction::where('slug', 'castanhas')->sole());
    $this->user->profile->update(['other_restrictions' => ['camarão']]);
    planoPronto($this->user);
});

it('lista até 4 opções do mesmo grupo, com a garantia de todas as restrições (RN25, CA08)', function () {
    $item = itemDeHoje('carboidrato');

    $resposta = $this->getJson("/api/v1/days/today/items/{$item->id}/substitutions")
        ->assertOk()
        ->assertJsonPath('data.item.id', $item->id)
        ->assertJsonPath('data.item.name', $item->food->name)
        ->assertJsonPath('data.guarantee.restrictions', ['Amendoim e castanhas', 'camarão'])
        ->assertJsonStructure(['data' => ['item' => ['amount', 'calories', 'macros'], 'options' => [['food_id', 'name', 'grams', 'amount', 'calories', 'macros', 'calorie_delta', 'note', 'in_pantry']]]]);

    $opcoes = $resposta->json('data.options');
    expect(count($opcoes))->toBeGreaterThan(0)->toBeLessThanOrEqual(4)
        ->and(App\Models\Food::whereIn('id', array_column($opcoes, 'food_id'))->pluck('group')->unique()->all())->toBe(['carboidrato']);
});

it('item de outra pessoa responde 404 (CA12, RN43)', function () {
    $daCamila = itemDeHoje('carboidrato');

    $outra = login(User::factory()->onboarded()->create());
    planoPronto($outra);

    $this->getJson("/api/v1/days/today/items/{$daCamila->id}/substitutions")->assertNotFound();
    $this->postJson("/api/v1/days/today/items/{$daCamila->id}/swap", ['food_id' => 1])->assertNotFound();
});

it('não oferece trocas fora de hoje (RN23)', function () {
    $item = itemDeHoje('carboidrato');

    $this->getJson("/api/v1/days/2026-09-29/items/{$item->id}/substitutions")->assertStatus(409)->assertJsonPath('code', 'DAY_NOT_EDITABLE');
});
```

`tests/Feature/Days/SwapTest.php`:
```php
<?php

use App\Models\DayMealChange;
use App\Models\Food;
use App\Models\Restriction;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    $this->user->restrictions()->attach(Restriction::where('slug', 'castanhas')->sole());
    planoPronto($this->user);
});

it('troca o alimento, marca o original e registra para desfazer (RN26)', function () {
    $item = itemDeHoje('carboidrato');
    $opcoes = $this->getJson("/api/v1/days/today/items/{$item->id}/substitutions")->json('data.options');

    $dia = $this->postJson("/api/v1/days/today/items/{$item->id}/swap", ['food_id' => $opcoes[0]['food_id']])->assertOk()->json('data');

    $trocado = collect($dia['meals'])->flatMap(fn ($m) => $m['items'])->firstWhere('id', $item->id);
    expect($trocado['food_id'])->toBe($opcoes[0]['food_id'])
        ->and($trocado['grams'])->toBe($opcoes[0]['grams'])
        ->and($trocado['source'])->toBe('manual')
        ->and($trocado['replaced_from'])->toBe($item->food->name)
        ->and($dia['last_change']['text'])->toBe($item->food->name.' trocado por '.mb_strtolower(mb_substr($opcoes[0]['name'], 0, 1)).mb_substr($opcoes[0]['name'], 1))
        ->and(DayMealChange::sole()->type->value)->toBe('swap');
});

it('trocas seguidas guardam o original da refeição-modelo (RN26)', function () {
    $item = itemDeHoje('carboidrato');
    $original = $item->food->name;

    trocarPelaPrimeiraOpcao($item->id);
    trocarPelaPrimeiraOpcao($item->id);

    $this->getJson('/api/v1/days/today')->assertOk();
    expect($item->fresh()->replacedFood->name)->toBe($original);
});

it('trocar não muda o "feita" da refeição (RN26)', function () {
    $item = itemDeHoje('carboidrato');
    $slot = $item->dayMeal->slot;
    $this->patchJson("/api/v1/days/today/meals/{$slot}", ['done' => true]);

    trocarPelaPrimeiraOpcao($item->id);

    expect($item->dayMeal->fresh()->isDone())->toBeTrue();
});

it('recusa food_id que não está entre as opções, inclusive um alimento proibido forjado (RN17, Review Focus 1)', function () {
    $item = itemDeHoje('carboidrato');

    foreach (['castanha-de-caju', 'frango-grelhado'] as $slug) {
        $this->postJson("/api/v1/days/today/items/{$item->id}/swap", ['food_id' => Food::where('slug', $slug)->value('id')])
            ->assertStatus(409)
            ->assertJsonPath('code', 'SUBSTITUTION_NOT_ALLOWED');
    }
    expect($item->fresh()->food_id)->toBe($item->food_id)->and(DayMealChange::count())->toBe(0);
});

it('food_id é obrigatório', function () {
    $item = itemDeHoje('carboidrato');

    $this->postJson("/api/v1/days/today/items/{$item->id}/swap", [])->assertJsonValidationErrors(['food_id']);
});
```

`tests/Feature/Days/UndoTest.php`:
```php
<?php

use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
});

it('desfaz a troca: a refeição volta como era, sem selo "Trocado" (CA09)', function () {
    $item = itemDeHoje('carboidrato');
    $slot = $item->dayMeal->slot;
    $antes = itensDaRefeicao($slot);
    trocarPelaPrimeiraOpcao($item->id);
    expect(itensDaRefeicao($slot))->not->toBe($antes);

    $this->postJson('/api/v1/days/today/undo')->assertOk()->assertJsonPath('data.last_change', null);

    expect(itensDaRefeicao($slot))->toBe($antes);
});

it('cada desfazer volta exatamente um passo (Review Focus 5)', function () {
    $carbo = itemDeHoje('carboidrato');
    $proteina = itemDeHoje('proteina');
    $slots = array_unique([$carbo->dayMeal->slot, $proteina->dayMeal->slot]);
    $estado = fn () => array_map(fn (string $slot) => itensDaRefeicao($slot), $slots);

    $original = $estado();
    trocarPelaPrimeiraOpcao($carbo->id);
    $depoisDoCarbo = $estado();
    trocarPelaPrimeiraOpcao($proteina->id);

    $this->postJson('/api/v1/days/today/undo')->assertOk();
    expect($estado())->toBe($depoisDoCarbo);

    $this->postJson('/api/v1/days/today/undo')->assertOk();
    expect($estado())->toBe($original);

    $this->postJson('/api/v1/days/today/undo')->assertStatus(409)->assertJsonPath('code', 'NOTHING_TO_UNDO');
});

it('depois de 15 minutos não desfaz mais (CA10, RN27)', function () {
    trocarPelaPrimeiraOpcao(itemDeHoje('carboidrato')->id);
    $this->travel(16)->minutes();

    $this->getJson('/api/v1/days/today')->assertJsonPath('data.last_change', null);
    $this->postJson('/api/v1/days/today/undo')->assertStatus(409)->assertJsonPath('code', 'NOTHING_TO_UNDO');
});

it('sem troca, nada para desfazer; e só hoje', function () {
    $this->postJson('/api/v1/days/today/undo')->assertStatus(409)->assertJsonPath('code', 'NOTHING_TO_UNDO');
    $this->postJson('/api/v1/days/2026-09-27/undo')->assertStatus(409)->assertJsonPath('code', 'DAY_NOT_EDITABLE');
});
```

`tests/Feature/Plans/AllergyInvariantTest.php`:
```php
<?php

use App\Models\DayMealItem;
use App\Models\Food;
use App\Models\PlanMealItem;
use App\Models\Restriction;
use App\Models\User;
use App\Services\Foods\FoodFilter;
use Carbon\CarbonImmutable;

// RN17 de ponta a ponta: com alergia a castanhas e a frutos do mar e "camarão" em outras restrições,
// nenhum alimento proibido aparece no plano, no dia ou nas trocas — mesmo com a IA errando.
it('nenhum alimento proibido chega ao plano, ao dia ou às trocas (RN17, CA08)', function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Sao_Paulo'));
    $user = login(User::factory()->onboarded()->create());
    $user->restrictions()->attach(Restriction::whereIn('slug', ['castanhas', 'frutos-do-mar'])->pluck('id'));
    $user->profile->update(['other_restrictions' => ['camarão']]);
    $proibidos = Food::whereNotIn('id', app(FoodFilter::class)->allowedFor($user)->keys())->pluck('id');
    fakeAi()->queue('plan', json_encode(['meals' => array_map(
        fn (string $slot) => ['slot' => $slot, 'items' => [['food_id' => $proibidos->first(), 'grams' => 100]]],
        ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar'],
    )]));

    planoPronto($user);
    $dia = $this->getJson('/api/v1/days/today')->assertOk()->json('data');

    expect(PlanMealItem::whereIn('food_id', $proibidos)->exists())->toBeFalse()
        ->and(DayMealItem::whereIn('food_id', $proibidos)->exists())->toBeFalse();
    foreach (collect($dia['meals'])->flatMap(fn ($m) => $m['items']) as $item) {
        $opcoes = $this->getJson("/api/v1/days/today/items/{$item['id']}/substitutions")->json('data.options.*.food_id');
        expect(array_intersect($opcoes, $proibidos->all()))->toBe([]);
    }
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Days tests/Feature/Plans/AllergyInvariantTest.php`
Expected: FAIL — rotas inexistentes.

- [ ] **Step 2: Implementar**

Em `app/Services/Days/DayService.php`: acrescentar os `use` (`App\Enums\DayChangeType`, `App\Enums\ItemSource`, `App\Models\DayMeal`, `App\Models\DayMealChange`, `App\Models\DayMealItem`, `App\Services\Foods\FoodFilter`, `App\Services\Foods\SubstitutionFinder`, `App\Services\Foods\SubstitutionOption`, `Illuminate\Support\Facades\DB`), trocar o construtor por
```php
    public function __construct(
        private readonly DayMaterializer $days,
        private readonly FoodFilter $filter,
        private readonly SubstitutionFinder $finder,
    ) {}
```
e acrescentar:
```php
    /** Item pedido na rota: precisa ser do usuário e da data (RN43 — senão, 404). */
    public function item(User $user, CarbonImmutable $date, int $itemId): DayMealItem
    {
        $item = DayMealItem::with(['food', 'dayMeal'])->find($itemId);
        if ($item === null || $item->dayMeal->user_id !== $user->id || ! $item->dayMeal->date->isSameDay($date)) {
            throw new NotFoundHttpException;
        }

        return $item;
    }

    /**
     * RF14 — opções de troca e a garantia com todas as restrições da pessoa.
     *
     * @return array{item: DayMealItem, options: list<SubstitutionOption>, restrictions: list<string>}
     */
    public function substitutions(User $user, CarbonImmutable $date, int $itemId): array
    {
        $this->assertEditable($date);
        $item = $this->item($user, $date, $itemId);

        return [
            'item' => $item,
            'options' => $this->options($user, $item),
            'restrictions' => [...$user->restrictions()->pluck('label')->all(), ...$user->profile->other_restrictions],
        ];
    }

    /** RN26 — troca só por uma das opções válidas agora (RN17 garantido pelo FoodFilter). */
    public function swap(User $user, CarbonImmutable $date, int $itemId, int $foodId): void
    {
        $this->assertEditable($date);
        $item = $this->item($user, $date, $itemId);
        $option = collect($this->options($user, $item))->first(fn (SubstitutionOption $o) => $o->food->id === $foodId)
            ?? throw new DomainException(ErrorCode::SubstitutionNotAllowed);

        DB::transaction(function () use ($user, $date, $item, $option) {
            DayMealChange::create([
                'user_id' => $user->id,
                'date' => $date,
                'day_meal_id' => $item->day_meal_id,
                'type' => DayChangeType::Swap,
                'description' => $item->food->name.' trocado por '.mb_strtolower(mb_substr($option->food->name, 0, 1)).mb_substr($option->food->name, 1),
                'items_before' => $this->snapshot($item->dayMeal),
            ]);

            $item->update([
                'food_id' => $option->food->id,
                'grams' => $option->grams,
                'source' => ItemSource::Manual,
                'replaced_food_id' => $item->replaced_food_id ?? $item->food_id,
            ]);
        });
    }

    /** RN27 — volta a última alteração de conteúdo (até 15 min), um passo por vez. */
    public function undo(User $user, CarbonImmutable $date): void
    {
        $this->assertEditable($date);
        $change = DayMealChange::undoableFor($user, $date)->first() ?? throw new DomainException(ErrorCode::NothingToUndo);

        DB::transaction(function () use ($change) {
            $meal = $change->dayMeal()->firstOrFail();
            $meal->items()->delete();
            foreach ($change->items_before as $item) {
                $meal->items()->create($item);
            }
            $change->update(['undone_at' => now()]);
        });
    }

    /**
     * Itens da refeição como estavam — o "antes" de uma alteração.
     *
     * @return list<array{food_id: int, grams: float, replaced_food_id: int|null, source: string, position: int}>
     */
    public function snapshot(DayMeal $meal): array
    {
        return $meal->items()->get()->map(fn (DayMealItem $item) => [
            'food_id' => $item->food_id,
            'grams' => $item->grams,
            'replaced_food_id' => $item->replaced_food_id,
            'source' => $item->source->value,
            'position' => $item->position,
        ])->values()->all();
    }

    /** @return list<SubstitutionOption> */
    private function options(User $user, DayMealItem $item): array
    {
        return $this->finder->find($item->food, $item->grams, $this->filter->allowedFor($user), $this->filter->pantryFoodIds($user));
    }
```

`app/Http/Resources/SubstitutionsResource.php`:
```php
<?php

namespace App\Http\Resources;

use App\Models\DayMealItem;
use App\Services\Foods\SubstitutionOption;
use App\Services\Nutrition\DayTotals;
use App\Services\Nutrition\PortionFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Folha de troca (`GET /days/{date}/items/{item}/substitutions`).
 *
 * @property array{item: DayMealItem, options: list<SubstitutionOption>, restrictions: list<string>} $resource
 */
class SubstitutionsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $item = $this->resource['item'];
        $formatter = app(PortionFormatter::class);
        $macros = DayTotals::item($item->food, $item->grams);

        return [
            'item' => [
                'id' => $item->id,
                'name' => $item->food->name,
                'amount' => $formatter->forFood($item->food, $item->grams),
                'calories' => $macros['calories'],
                'macros' => ['protein' => $macros['protein'], 'carbs' => $macros['carbs'], 'fat' => $macros['fat']],
            ],
            'options' => array_map(fn (SubstitutionOption $option) => [
                'food_id' => $option->food->id,
                'name' => $option->food->name,
                'grams' => $option->grams,
                'amount' => $formatter->forFood($option->food, $option->grams),
                'calories' => $option->macros['calories'],
                'macros' => ['protein' => $option->macros['protein'], 'carbs' => $option->macros['carbs'], 'fat' => $option->macros['fat']],
                'calorie_delta' => $option->calorieDelta,
                'note' => $option->food->substitution_note,
                'in_pantry' => $option->inPantry,
            ], $this->resource['options']),
            'guarantee' => ['restrictions' => $this->resource['restrictions']],
        ];
    }
}
```

Em `app/Http/Controllers/Api/V1/DayController.php`, acrescentar (com `use App\Http\Resources\SubstitutionsResource;`):
```php
    public function substitutions(Request $request, string $date, int $item, DayService $service): SubstitutionsResource
    {
        /** @var User $user */
        $user = $request->user();

        return new SubstitutionsResource($service->substitutions($user, DayDate::parse($date), $item));
    }

    public function swap(Request $request, string $date, int $item, DayService $service, DayMaterializer $days): DayResource
    {
        $data = $request->validate(['food_id' => ['required', 'integer']], ['food_id.*' => 'Escolha uma das opções.']);
        /** @var User $user */
        $user = $request->user();
        $day = DayDate::parse($date);

        $service->swap($user, $day, $item, (int) $data['food_id']);

        return new DayResource($days->view($user, $day));
    }

    public function undo(Request $request, string $date, DayService $service, DayMaterializer $days): DayResource
    {
        /** @var User $user */
        $user = $request->user();
        $day = DayDate::parse($date);

        $service->undo($user, $day);

        return new DayResource($days->view($user, $day));
    }
```

`routes/api.php` — no grupo `onboarded`:
```php
            Route::get('days/{date}/items/{item}/substitutions', [DayController::class, 'substitutions'])->whereNumber('item');
            Route::post('days/{date}/items/{item}/swap', [DayController::class, 'swap'])->whereNumber('item');
            Route::post('days/{date}/undo', [DayController::class, 'undo']);
```

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Feature/Days tests/Feature/Plans`
Expected: PASS.

- [ ] **Step 4: Suíte, lint e commit**

Run: `docker compose run --rm api php artisan test && docker compose run --rm --no-deps api composer lint`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(dia): trocar alimento, desfazer e o invariante da alergia (RN17, RN25–RN27)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 10: Plano novo no dia de hoje (RN20) e efeitos das mudanças do perfil (RN21)

**Files:**
- Create: `app/Services/Plans/ProfileChangeEffects.php`
- Modify: `app/Services/Plans/PlanService.php` (`activate` refaz as não feitas de hoje; `retime`), `app/Services/Profile/ProfileService.php`, `app/Http/Controllers/Api/V1/{ProfileController,PreferencesController}.php`
- Test: `tests/Feature/Plans/ActivationTest.php`, `tests/Feature/Profile/PlanEffectTest.php`

**Interfaces:**
- Consumes: `PlanService`, `DayMaterializer::build` (Tasks 5, 7), `MealScheduler`, `PlanEffect`.
- Produces:
  - `PlanService::activate` passa a trocar, no dia de hoje já aberto, as refeições **não feitas** pelas do plano novo (as feitas ficam — CA11).
  - `PlanService::retime(User): void` — recalcula os horários do plano ativo (RN14) e das refeições não feitas de hoje, sem IA (CA09).
  - `ProfileChangeEffects::snapshot(User): array` e `apply(User, array $before, array $after): array{effect: PlanEffect, plan_id: int|null}` — restrições/"outras" mudaram → `regeneration_started` (novo plano, `force`); metas, objetivo, meta de peso, almoço, cozinha ou "não curto" mudaram → `regeneration_suggested`; só horários/dias de treino → `times_updated`; nada relevante → `none`. Sem plano ativo, sempre `none`. Mudança de horário sempre reprograma (mesmo junto de uma sugestão).
  - `ProfileService::updateStep(...)` passa a devolver `array{warnings: list<string>, effect: PlanEffect, plan_id: int|null}`; `updatePreferences(...)` devolve `array{effect: PlanEffect, plan_id: int|null}`. As respostas de `PATCH /profile/steps/{step}` e `PUT /profile/preferences` levam esses valores em `meta`.

- [ ] **Step 1: Testes que devem falhar**

`tests/Feature/Plans/ActivationTest.php`:
```php
<?php

use App\Models\DayMeal;
use App\Models\User;
use Carbon\CarbonImmutable;

it('plano novo às 15:00 mantém café, lanche e almoço feitos e troca o resto (CA11, RN20)', function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 15:00', 'America/Sao_Paulo'));
    $user = login(User::factory()->onboarded()->create());
    $antigo = planoPronto($user);
    $this->getJson('/api/v1/days/today')->assertOk();
    foreach (['cafe', 'lanche', 'almoco'] as $slot) {
        $this->patchJson("/api/v1/days/today/meals/{$slot}", ['done' => true])->assertOk();
    }
    $feitas = DayMeal::whereIn('slot', ['cafe', 'lanche', 'almoco'])->with('items')->get()
        ->mapWithKeys(fn (DayMeal $m) => [$m->slot => [$m->id, $m->items->pluck('id')->all()]])->all();

    $novo = $this->postJson('/api/v1/plans')->assertAccepted()->json('data.id');

    $dia = DayMeal::with('items')->whereDate('date', today())->get()->keyBy('slot');
    foreach ($feitas as $slot => [$id, $itens]) {
        expect($dia[$slot]->id)->toBe($id)
            ->and($dia[$slot]->isDone())->toBeTrue()
            ->and($dia[$slot]->meal_plan_id)->toBe($antigo->id)
            ->and($dia[$slot]->items->pluck('id')->all())->toBe($itens);
    }
    expect($dia['pre-treino']->meal_plan_id)->toBe($novo)
        ->and($dia['jantar']->meal_plan_id)->toBe($novo)
        ->and($dia)->toHaveCount(5);
    $this->getJson('/api/v1/days/today')->assertJsonPath('data.meals.*.slot', ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar']);
});
```

`tests/Feature/Profile/PlanEffectTest.php`:
```php
<?php

use App\Models\DayMeal;
use App\Models\Food;
use App\Models\PlanMeal;
use App\Models\PlanMealItem;
use App\Models\Restriction;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create()); // acorda 06:20, treina 19:00, dorme 23:00, seg/qua/sex
    $this->plano = planoPronto($this->user);
});

it('restrição nova refaz o plano na hora, sem o alimento restrito (RN21, CA07)', function () {
    $resposta = $this->patchJson('/api/v1/profile/steps/restricoes', ['restrictions' => ['frutos-do-mar'], 'other_restrictions' => []])
        ->assertOk()
        ->assertJsonPath('meta.plan_effect', 'regeneration_started');

    $novo = $resposta->json('meta.plan_id');
    $frutosDoMar = Restriction::where('slug', 'frutos-do-mar')->sole()->foods()->pluck('foods.id');
    expect($novo)->not->toBe($this->plano->id)
        ->and($this->user->activePlan()->value('id'))->toBe($novo)
        ->and(PlanMealItem::whereIn('plan_meal_id', PlanMeal::where('meal_plan_id', $novo)->pluck('id'))->whereIn('food_id', $frutosDoMar)->exists())->toBeFalse();
});

it('"outras restrições" novas também refazem o plano', function () {
    $this->patchJson('/api/v1/profile/steps/restricoes', ['restrictions' => [], 'other_restrictions' => ['banana']])
        ->assertJsonPath('meta.plan_effect', 'regeneration_started');
});

it('mudanças que afetam o cardápio sugerem refazer, sem gerar (RN21)', function (string $step, array $troca) {
    $this->patchJson("/api/v1/profile/steps/{$step}", stepPayload($step, $troca))
        ->assertOk()
        ->assertJsonPath('meta.plan_effect', 'regeneration_suggested')
        ->assertJsonPath('meta.plan_id', null);

    expect($this->user->activePlan()->value('id'))->toBe($this->plano->id)->and($this->user->mealPlans()->count())->toBe(1);
})->with([
    'objetivo' => ['objetivo', ['goal' => 'perder-gordura']],
    'peso de hoje' => ['dados', ['weight_kg' => 61.0]],
    'atividade' => ['atividade', ['activity_level' => 'intenso']],
    'cozinha' => ['preferencias', ['pantry_items' => ['ovos']]],
    'lugar do almoço' => ['rotina', ['lunch_place' => 'casa']],
]);

it('mudar só o nome, ou salvar igual, não mexe no plano', function (string $step, array $troca) {
    $this->patchJson("/api/v1/profile/steps/{$step}", stepPayload($step, $troca))->assertJsonPath('meta.plan_effect', 'none');
})->with([
    'nome preferido' => ['dados', ['preferred_name' => 'Mila', 'weight_kg' => 58.4]],
    'rotina igual' => ['rotina', []],
]);

it('treino às 07:00 muda os horários na hora, sem IA, e preserva a refeição feita (CA09, RN14)', function () {
    $this->getJson('/api/v1/days/today')->assertOk();
    $this->patchJson('/api/v1/days/today/meals/cafe', ['done' => true]);

    $this->patchJson('/api/v1/profile/steps/rotina', stepPayload('rotina', ['training_time' => '07:00']))
        ->assertJsonPath('meta.plan_effect', 'times_updated')
        ->assertJsonPath('meta.plan_id', null);

    // Treino cedo (RN14): pré-treino 06:30, café 08:15, lanche 10:30, almoço 12:30, jantar 20:30.
    expect($this->plano->meals()->pluck('time', 'slot')->all())->toBe([
        'pre-treino' => '06:30:00', 'cafe' => '08:15:00', 'lanche' => '10:30:00', 'almoco' => '12:30:00', 'jantar' => '20:30:00',
    ])->and($this->user->mealPlans()->count())->toBe(1);
    $hoje = DayMeal::whereDate('date', today())->get()->keyBy('slot');
    expect(substr($hoje['cafe']->time, 0, 5))->toBe('07:00')
        ->and(substr($hoje['pre-treino']->time, 0, 5))->toBe('06:30')
        ->and(substr($hoje['lanche']->time, 0, 5))->toBe('10:30')
        ->and($hoje['jantar']->note)->toBeNull();
});

it('Preferências: restrição refaz; só cozinha ou "não curto" sugerem refazer (CA07, CA08)', function () {
    $base = ['restrictions' => [], 'other_restrictions' => [], 'pantry_items' => [], 'disliked_food_ids' => []];

    $this->putJson('/api/v1/profile/preferences', [...$base, 'pantry_items' => ['maca']])->assertJsonPath('meta.plan_effect', 'regeneration_suggested');
    $this->putJson('/api/v1/profile/preferences', [...$base, 'pantry_items' => ['maca'], 'disliked_food_ids' => [Food::where('slug', 'jilo')->value('id')]])
        ->assertJsonPath('meta.plan_effect', 'regeneration_suggested');
    $this->putJson('/api/v1/profile/preferences', [...$base, 'pantry_items' => ['maca'], 'disliked_food_ids' => [Food::where('slug', 'jilo')->value('id')], 'restrictions' => ['lactose']])
        ->assertJsonPath('meta.plan_effect', 'regeneration_started');
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Plans/ActivationTest.php tests/Feature/Profile/PlanEffectTest.php`
Expected: FAIL — `plan_effect` sempre `none`; refeições de hoje não trocadas.

- [ ] **Step 2: Implementar**

Em `app/Services/Plans/PlanService.php`: acrescentar os `use` (`App\Models\DayMeal`, `App\Services\Days\DayMaterializer`, `App\Services\Nutrition\MealScheduler`, `Carbon\CarbonImmutable`), trocar o construtor por
```php
    public function __construct(
        private readonly NutritionCalculator $calculator,
        private readonly MealScheduler $scheduler,
        private readonly DayMaterializer $days,
    ) {}
```
no `activate`, logo depois de `$plan->update(['status' => PlanStatus::Ready, 'is_active' => true, 'ready_at' => now()]);`:
```php
            $this->refreshToday($plan);
```
e acrescentar:
```php
    /** RN14/RN21 (`times_updated`) — horários novos no plano ativo e nas refeições não feitas de hoje, sem IA. */
    public function retime(User $user): void
    {
        $plan = $user->activePlan()->with('meals')->first();
        if ($plan === null) {
            return;
        }
        $profile = $user->profile()->firstOrFail();
        $user->setRelation('profile', $profile);
        $times = $this->scheduler->schedule(
            substr((string) $profile->wake_time, 0, 5), substr((string) $profile->training_time, 0, 5),
            substr((string) $profile->sleep_time, 0, 5), $profile->training_days !== [],
        );
        $order = array_keys($times);

        DB::transaction(function () use ($user, $plan, $times, $order) {
            foreach ($plan->meals as $meal) {
                $meal->update(['time' => $times[$meal->slot], 'position' => (int) array_search($meal->slot, $order, true) + 1]);
            }
            $plan->unsetRelation('meals');

            $fresh = $this->days->build($user, $plan, CarbonImmutable::today(), save: false)->keyBy('slot');
            foreach ($user->dayMeals()->whereDate('date', CarbonImmutable::today())->get() as $meal) {
                $new = $fresh[$meal->slot];
                $meal->update($meal->isDone()
                    ? ['position' => $new->position]
                    : ['position' => $new->position, 'time' => $new->time, 'name' => $new->name, 'note' => $new->note]);
            }
        });
    }

    /** RN20 — hoje já aberto: não feitas vêm do plano novo; feitas ficam (e só mudam de posição). */
    private function refreshToday(MealPlan $plan): void
    {
        $user = $plan->user()->firstOrFail();
        $today = $user->dayMeals()->whereDate('date', CarbonImmutable::today())->get();
        if ($today->isEmpty()) {
            return; // hoje ainda não aberto: materializa do plano novo na primeira leitura
        }

        $pending = $today->reject(fn (DayMeal $meal) => $meal->isDone());
        DayMeal::whereKey($pending->modelKeys())->delete(); // itens e alterações caem em cascata
        $this->days->build($user, $plan, CarbonImmutable::today(), save: true, onlySlots: $pending->pluck('slot')->all());

        $positions = $plan->meals()->pluck('position', 'slot');
        foreach ($today->filter(fn (DayMeal $meal) => $meal->isDone()) as $meal) {
            $meal->update(['position' => $positions[$meal->slot]]);
        }
    }
```

`app/Services/Plans/ProfileChangeEffects.php`:
```php
<?php

namespace App\Services\Plans;

use App\Enums\PlanEffect;
use App\Models\User;
use App\Services\Foods\FoodFilter;

/** RN21 — o que uma mudança do perfil faz com o plano ativo. */
class ProfileChangeEffects
{
    public function __construct(private readonly PlanService $plans) {}

    /**
     * Foto do que importa para o plano, para comparar antes e depois.
     *
     * @return array{restrictions: list<mixed>, plan: list<mixed>, times: list<mixed>}
     */
    public function snapshot(User $user): array
    {
        $user->unsetRelation('latestWeighIn');
        $profile = $user->profile()->firstOrFail();
        $user->setRelation('profile', $profile);
        $sorted = function (array $values): array {
            sort($values);

            return $values;
        };

        return [
            'restrictions' => [
                $sorted($user->restrictions()->pluck('restrictions.id')->all()),
                $sorted(array_map(FoodFilter::normalize(...), $profile->other_restrictions)),
            ],
            'plan' => $profile->isOnboarded() ? [
                (array) $this->plans->targetsFor($user),
                $profile->goal,
                $profile->goal_weight_kg === null ? null : (float) $profile->goal_weight_kg,
                $profile->lunch_place,
                $sorted($user->pantryItems()->pluck('pantry_items.id')->all()),
                $sorted($user->dislikedFoods()->pluck('foods.id')->all()),
            ] : [],
            'times' => [
                substr((string) $profile->wake_time, 0, 5), substr((string) $profile->training_time, 0, 5),
                substr((string) $profile->sleep_time, 0, 5), $sorted($profile->training_days),
            ],
        ];
    }

    /**
     * @param  array{restrictions: list<mixed>, plan: list<mixed>, times: list<mixed>}  $before
     * @param  array{restrictions: list<mixed>, plan: list<mixed>, times: list<mixed>}  $after
     * @return array{effect: PlanEffect, plan_id: int|null}
     */
    public function apply(User $user, array $before, array $after): array
    {
        if (! $user->activePlan()->exists()) {
            return ['effect' => PlanEffect::None, 'plan_id' => null]; // durante o onboarding ou sem plano: nada a refazer
        }

        if ($before['restrictions'] !== $after['restrictions']) {
            // Segurança: restrição nova nunca espera (RN17, RN21).
            return ['effect' => PlanEffect::RegenerationStarted, 'plan_id' => $this->plans->requestGeneration($user, force: true)->id];
        }

        $timesChanged = $before['times'] !== $after['times'];
        if ($timesChanged) {
            $this->plans->retime($user);
        }

        if ($before['plan'] !== $after['plan']) {
            return ['effect' => PlanEffect::RegenerationSuggested, 'plan_id' => null];
        }

        return ['effect' => $timesChanged ? PlanEffect::TimesUpdated : PlanEffect::None, 'plan_id' => null];
    }
}
```

Em `app/Services/Profile/ProfileService.php`: `use App\Enums\PlanEffect;` e `use App\Services\Plans\ProfileChangeEffects;`; construtor
```php
    public function __construct(
        private readonly GoalWeightResolver $goalWeights,
        private readonly ProfileChangeEffects $effects,
    ) {}
```
Em `updateStep`: trocar a docblock `@return list<string>` por `@return array{warnings: list<string>, effect: PlanEffect, plan_id: int|null}`, e o corpo por:
```php
        $before = $this->effects->snapshot($user);

        $warnings = DB::transaction(function () use ($user, $step, $data) {
            $profile = $user->profile;

            $warnings = match ($step) {
                OnboardingStep::Objetivo => $this->saveGoal($user, $profile, Goal::from((string) $data['goal'])),
                OnboardingStep::Dados => $this->saveBody($user, $profile, $data),
                OnboardingStep::Atividade, OnboardingStep::Rotina => $this->fill($profile, $data),
                OnboardingStep::Preferencias => $this->syncPantry($user, $data['pantry_items']),
                OnboardingStep::Restricoes => $this->saveRestrictions($user, $profile, $data),
                OnboardingStep::Resumo => throw new LogicException('O resumo não é uma etapa salva.'),
            };

            $profile->completed_steps = array_values(array_unique([...$profile->completed_steps, $step->value]));
            $profile->save();

            return $warnings;
        });

        return ['warnings' => $warnings, ...$this->effects->apply($user, $before, $this->effects->snapshot($user))];
```
Em `updatePreferences`: `@return array{effect: PlanEffect, plan_id: int|null}` e:
```php
        $before = $this->effects->snapshot($user);

        DB::transaction(function () use ($user, $data) {
            $this->saveRestrictions($user, $user->profile, $data);
            $user->profile->save();
            $this->syncPantry($user, $data['pantry_items']);
            $user->dislikedFoods()->sync($data['disliked_food_ids']);
        });

        return $this->effects->apply($user, $before, $this->effects->snapshot($user));
```

`app/Http/Controllers/Api/V1/ProfileController.php` — em `updateStep`:
```php
        $result = $profiles->updateStep($user, OnboardingStep::from($step), $request->validated());

        return (new OnboardingResource($user->fresh(OnboardingResource::RELATIONS)))
            ->additional(['meta' => ['plan_effect' => $result['effect']->value, 'plan_id' => $result['plan_id'], 'warnings' => $result['warnings']]])
            ->response();
```

`app/Http/Controllers/Api/V1/PreferencesController.php`:
```php
        $result = $profiles->updatePreferences($user, $request->validated());

        return (new ProfileResource($user->fresh(ProfileResource::RELATIONS)))
            ->additional(['meta' => ['plan_effect' => $result['effect']->value, 'plan_id' => $result['plan_id']]])
            ->response();
```

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Feature/Plans tests/Feature/Profile tests/Feature/Onboarding`
Expected: PASS (os testes do Plano 03 continuam com `none`: aqueles usuários não têm plano ativo).

- [ ] **Step 4: Suíte, lint e commit**

Run: `docker compose run --rm api php artisan test && docker compose run --rm --no-deps api composer lint`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(plano): plano novo preserva o feito de hoje; perfil refaz, sugere ou reprograma (RN20, RN21)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 11: Worker da fila, contas E2E com plano, README e specs

**Files:**
- Modify: `compose.yaml`, `README.md`, `database/seeders/E2ESeeder.php`, `tests/Feature/Seeders/E2ESeederTest.php`
- Modify (specs): `docs/superpowers/plans/2026-09-24-00-roteiro.md`, `specs/00-fundacao/integracao-ia.md`, `specs/00-fundacao/modelo-de-dados.md`, `specs/00-fundacao/regras-de-negocio.md`, `specs/02-onboarding-perfil/spec.md`, `specs/03-plano-alimentar/spec.md`

**Interfaces:**
- Produces:
  - Serviços `queue` (`php artisan queue:work --tries=1 --timeout=170`) e `scheduler` (`php artisan schedule:work`) no `compose.yaml`.
  - `E2ESeeder`: as contas `concluido-*` e `senha-*` já têm plano pronto e ativo (gerado com a IA falsa, na hora, sem depender do worker).

- [ ] **Step 1: Teste do seeder que deve falhar**

Em `tests/Feature/Seeders/E2ESeederTest.php`, acrescentar:
```php
it('as contas concluídas já têm plano pronto e ativo, gerado pela IA falsa', function () {
    $this->seed(E2ESeeder::class);

    foreach (['concluido-chromium', 'concluido-webkit', 'senha-chromium', 'senha-webkit'] as $conta) {
        $plano = User::where('email', "{$conta}@e2e.pratoforte.test")->sole()->activePlan()->first();
        expect($plano?->status)->toBe(App\Enums\PlanStatus::Ready);
    }
});
```
Run: `docker compose run --rm api php artisan test tests/Feature/Seeders`
Expected: FAIL — sem plano ativo.

- [ ] **Step 2: Seeder, compose e README**

`database/seeders/E2ESeeder.php` — acrescentar os `use` (`App\Ai\AiClient`, `App\Ai\FakeAiClient`, `App\Ai\LoggingAiClient`, `App\Services\Plans\PlanService`) e trocar o laço dos navegadores por:
```php
        // Contas concluídas já com plano pronto: IA falsa e fila síncrona só durante o seed,
        // para nunca chamar a IA de verdade nem depender do worker.
        config(['queue.default' => 'sync']);
        app()->instance(AiClient::class, new LoggingAiClient(app(FakeAiClient::class)));
        $planos = app(PlanService::class);

        // Uma conta por navegador: o E2E-10 troca a senha, e o login aceita só 5 tentativas
        // por minuto por e-mail — os dois navegadores juntos na mesma conta passariam disso.
        foreach (['chromium', 'webkit'] as $navegador) {
            foreach ([['Camila Réus', 'concluido'], ['Rafa Lima', 'senha']] as [$nome, $conta]) {
                $user = User::factory()->onboarded()->create(['name' => $nome, 'email' => "{$conta}-{$navegador}@e2e.pratoforte.test"]);
                $planos->requestGeneration($user);
            }
        }
```

`compose.yaml` — acrescentar depois do serviço `api`:
```yaml
  # Gera os planos (GeneratePlanJob). Sem ele, os planos ficam "pending" e viram TIMEOUT.
  queue:
    build: ./docker/php
    user: "1000:1000"
    working_dir: /app
    environment:
      HOME: /tmp
    volumes:
      - .:/app
    command: php artisan queue:work --tries=1 --timeout=170 --sleep=1
    depends_on:
      mysql:
        condition: service_healthy

  # Tarefas agendadas (plans:fail-stale; lembretes no Plano 07).
  scheduler:
    build: ./docker/php
    user: "1000:1000"
    working_dir: /app
    environment:
      HOME: /tmp
    volumes:
      - .:/app
    command: php artisan schedule:work
    depends_on:
      mysql:
        condition: service_healthy
```

`README.md` — trocar o comentário da linha `docker compose up -d` por `# API :8000, fila e agendador, Mailpit :8025, MySQL :3307` e acrescentar, antes de "## Segredos":
````markdown
## IA

`AI_DRIVER=fake` (padrão) usa uma IA determinística: planos montados com os alimentos permitidos, sem custo — é a usada nos testes, no E2E e na demonstração. Para a IA de verdade, no `.env`: `AI_DRIVER=openai`, `AI_BASE_URL` (ex.: `https://api.aimlapi.com/v1`), `AI_API_KEY` e, se quiser, `AI_MODEL_PLAN`/`AI_MODEL_CHAT`. Toda chamada vai para `ai_requests` sem conteúdo (RN44).

Contas do E2E (senha `senha1234`): `php artisan migrate:fresh --seeder=E2ESeeder --force`.
````
e trocar o fim da seção "Segredos" (`…a nova vai só no ambiente (\`AI_API_KEY\`, a partir do Plano 04).`) por `…a nova vai só no ambiente (\`AI_API_KEY\`).`

Run: `docker compose run --rm api php artisan test tests/Feature/Seeders && docker compose up -d && docker compose ps --format '{{.Service}} {{.State}}'`
Expected: PASS; `api`, `queue`, `scheduler`, `mysql`, `mailpit` em `running`.

- [ ] **Step 3: Conferência manual contra a fila de verdade (1 vez)**

```bash
docker compose exec api php artisan migrate:fresh --seeder=E2ESeeder --force
docker compose exec api php artisan tinker --execute='$u = App\Models\User::where("email","concluido-chromium@e2e.pratoforte.test")->sole(); $p = app(App\Services\Plans\PlanService::class)->requestGeneration($u); sleep(5); echo $p->fresh()->status->value, PHP_EOL;'
```
Expected: `ready` (o worker `queue` pegou o job). Se sair `pending`, confira `docker compose logs queue`.

- [ ] **Step 4: Specs e roteiro**

- `docs/superpowers/plans/2026-09-24-00-roteiro.md`: trocar a linha do plano 04 por duas:
  `| 04A | Plano alimentar: API | 03, integracao-ia | \`AiClient\` + \`FakeAiClient\`, geração híbrida validada (D6), dia, marcar refeição, trocas, desfazer, RN20/RN21 | — (testes de feature) |`
  `| 04B | Plano alimentar: telas | 04A | Gerando, Pronto, Hoje, Dieta, Detalhe com folha de troca, "Refazer meu plano", efeitos RN21 no front | E2E-01, 04, 05, 06, 07, 11 |`
  e acrescentar abaixo da tabela: `O Plano 04 foi dividido em 04A (backend) e 04B (front) por tamanho: cada metade termina testada.`
- `specs/00-fundacao/integracao-ia.md` §6: trocar a linha do `AI_FAKE_FAIL_FIRST_PLAN=true` por: `- Variável \`AI_FAKE_FAIL_PLAN_FOR=<e-mails separados por vírgula>\` (só E2E) faz a **primeira** geração de plano dessas contas falhar, para o teste "Tentar de novo" — determinístico mesmo com várias contas e vários processos.`
- `specs/00-fundacao/modelo-de-dados.md` §4, `failure_reason`: acrescentar `` `SUPERSEDED` `` (plano mais antigo que terminou depois de um mais novo — ver RN21) à lista; em `ai_requests.status`, acrescentar ` (\`invalid\` reservado; o log registra ok/error/timeout)`.
- `specs/00-fundacao/regras-de-negocio.md`:
  - RN18, item 4: depois de "escala uniforme das porções do dia", acrescentar ` (porções arredondadas a 5 g)`.
  - RN25, item 2: trocar `(carboidrato → carbs; proteína/laticínio → protein; gordura → fat; fruta/vegetal/outros → kcal)` por `(carboidrato → carbs; proteína/laticínio/leguminosa → protein; gordura → fat; fruta/vegetal/bebida/outros → kcal), arredondada a 5 g`.
  - RN21, depois da tabela: `Pedido por restrição nunca espera o que já está gerando: cria outro plano, e ao ficar pronto só o mais novo é ativado (um mais antigo que termine depois vira \`failed\` \`SUPERSEDED\`). Mudança de horário sempre reprograma, mesmo quando outra mudança da mesma etapa sugere refazer.`
- `specs/02-onboarding-perfil/spec.md` §5: apagar as linhas "**Até o Plano 04:** …" (complete) e o trecho "`plan_effect` é sempre `none` até o plano alimentar existir (Plano 04)." (avisos); no §9, tirar as ressalvas "— complete sem plano até o Plano 04" e "— as linhas de RN21 entram no Plano 04", marcar `[x]` em "Feature tests…" e trocar a ressalva de CA07–CA09 por ` — CA07–CA09 cobertos na API pelo Plano 04A; telas no 04B`.
- `specs/03-plano-alimentar/spec.md` §9: marcar `[x]` em "Catálogo semeado…", "NutritionCalculator, MealScheduler, PortionAdjuster, PlanValidator, SubstitutionFinder, DayTotals, PortionFormatter com unit tests", "GeneratePlanJob com FakeAiClient e fixtures; FailStalePlans agendado", "Endpoints de plano e dia; Policies; feature tests incluindo posse e alergia"; e acrescentar ao "CA01–CA12 atendidos" ` — API no 04A (CA01–CA12 por feature test); telas no 04B`.

```bash
git add -A && git commit -m "chore: worker e agendador no compose, contas E2E com plano, specs do 04A

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 5: Verificação final**

Run: `docker compose run --rm api php artisan test && docker compose run --rm --no-deps api composer lint && docker compose run --rm --no-deps api composer audit`
Expected: tudo verde.
