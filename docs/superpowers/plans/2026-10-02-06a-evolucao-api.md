# Plano 06A — Evolução: API — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A API da Evolução: registrar e listar pesagens (RN34), previsão de chegada na meta (RN35), constância dos últimos 28 dias (RN36) e médias do período (RN37), em `GET/POST /weigh-ins` e `GET /progress`.

**Architecture:** Dois cálculos puros em `app/Services/Progress/` (`WeightForecast`, `AdherenceCalculator`), um serviço de escrita (`WeighInService`) e um de leitura (`ProgressService`) que junta pesagens, `day_meals` e o plano ativo. Controllers finos (`WeighInController`, `ProgressController`) no grupo `onboarded`. O E2ESeeder ganha as contas `peso-{navegador}` para o E2E-09 do Plano 06B.

**Tech Stack:** Laravel 12, PHP 8.3, MySQL 8, Pest, Larastan 6, Pint — tudo via `docker compose run --rm api …` no repo backend.

**Spec:** `specs/05-evolucao/spec.md` (§2 RF23–RF25, §5 API, §6, §7 CA01–CA08, §8), `specs/00-fundacao/regras-de-negocio.md` (RN10, RN34–RN37).

**Onde rodar:** `/home/alvez/atividade-extensionista/backend`, branch `plano-06a-evolucao-api` saindo de `plano-05b-nutri-telas`.

## Decisões deste plano (rulings sobre a spec)

1. **Rótulo da previsão:** dia 1–10 "início de {mês}", 11–20 "meados de {mês}", 21+ "fim de {mês}"; acrescenta " de {ano}" só quando o ano é outro **e** o mês é o mesmo ou depois do mês de hoje (senão "fim de setembro" seria ambíguo).
2. **Previsão já vencida:** se a reta cruza a meta em data ≤ hoje, ou a última pesagem já passou da meta no sentido do objetivo, não há previsão (`null`) — a tela mostra a mensagem neutra.
3. **Período sem pesagens:** `start_kg`, `current_kg`, `change_kg` e `span_weeks` vêm `null` e `points` `[]`.
4. **`span_weeks`:** `round(dias entre a primeira e a última pesagem do período / 7)`.
5. **Médias:** `avg_g` e `avg_kcal` inteiros (arredondados); metas do plano ativo (`null` sem plano ativo). Janela das médias = mesma do período (`6w` 42 dias, `3m` 91, `all` sem limite), incluindo hoje.
6. **Insight RN37:** só com dias de treino **e** de descanso na janela; "10 pontos percentuais" = diferença entre as porcentagens da meta.
7. **`period` ausente** ⇒ `6w`; inválido ⇒ 422.
8. **Duas pesagens ao mesmo tempo no mesmo dia** (duas abas): a segunda vira atualização, nunca 500.

## Global Constraints

- Pesagem: `weight_kg` required|numeric|decimal:0,1|between:30,250; `date` opcional, `Y-m-d`, entre hoje − 30 e hoje; uma por data (índice único já existe).
- Respostas: `POST /weigh-ins` 201 (nova) / 200 (substituiu) com `meta.replaced`; `GET /weigh-ins` em ordem crescente de data; `GET /progress` no formato exato do §5 da spec.
- Mensagens de validação em português: "O peso precisa ficar entre 30 e 250 kg.", "A pesagem não pode ser de um dia que ainda não chegou.", "Só dá para registrar pesagens dos últimos 30 dias."
- Constância sempre dos últimos 28 dias (inclui hoje, com status `hoje`), independente do período.
- Docblocks com `@return`/`@param` sempre em várias linhas (Larastan).
- Nenhum segredo no repo; nada no `backend (legado node)/`.

## Review Focus

1. **Pesagem registrada duas vezes no mesmo segundo (duas abas)** → uma linha só, a segunda responde 200 com `replaced: true` (Task 1, teste com a linha já criada entre o `first()` e o `create()` simulado pelo `UniqueConstraintViolationException`).
2. **Período `3m` com pesagens mais antigas que 91 dias** → elas não entram nos pontos nem na previsão (Task 4).
3. **Usuário "mais disposição" (sem meta)** → `goal_kg` `null`, `forecast` `null`, nada quebra (Task 4, CA07).
4. **Dia materializado com refeições de um plano antigo** → a constância conta o que está em `day_meals`, sem olhar o plano ativo (Task 3/4).
5. **Nenhum dia com refeição feita** → `averages.days_counted` 0, médias e `insight` `null` (Task 4, CA06).

---

### Task 1: Pesagens — `WeighInService`, `GET/POST /weigh-ins`

**Files:**
- Create: `app/Services/Progress/WeighInService.php`, `app/Http/Controllers/Api/V1/WeighInController.php`, `app/Http/Requests/WeighInRequest.php`, `app/Http/Resources/WeighInResource.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Progress/WeighInTest.php`

**Interfaces:**
- Consumes: `User::weighIns()`, `User::currentWeightKg()` (existentes), middleware `onboarded`.
- Produces: `WeighInService::record(User $user, float $kg, CarbonImmutable $date): array{weighIn: WeighIn, replaced: bool}`; rotas `GET /api/v1/weigh-ins`, `POST /api/v1/weigh-ins`.

- [ ] **Step 1: Branch e teste que deve falhar**

```bash
cd /home/alvez/atividade-extensionista/backend
git switch plano-05b-nutri-telas && git switch -c plano-06a-evolucao-api
```

`tests/Feature/Progress/WeighInTest.php`:
```php
<?php

use App\Models\User;
use App\Models\WeighIn;
use App\Services\Progress\WeighInService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 08:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
});

it('registra a pesagem de hoje (201) e o peso atual do perfil muda (CA08)', function () {
    $this->postJson('/api/v1/weigh-ins', ['weight_kg' => 58.6])
        ->assertCreated()
        ->assertExactJson(['data' => ['id' => WeighIn::sole()->id, 'date' => '2026-09-23', 'weight_kg' => 58.6], 'meta' => ['replaced' => false]]);

    expect($this->getJson('/api/v1/profile')->json('data.current_weight_kg'))->toBe(58.6);
});

it('registrar de novo no mesmo dia substitui (200, CA02)', function () {
    $this->postJson('/api/v1/weigh-ins', ['weight_kg' => 58.6])->assertCreated();
    $this->postJson('/api/v1/weigh-ins', ['weight_kg' => 58.9])->assertOk()->assertJsonPath('meta.replaced', true);

    expect(WeighIn::count())->toBe(1)->and(WeighIn::sole()->weight_kg)->toBe(58.9);
});

it('aceita data dos últimos 30 dias e lista em ordem crescente', function () {
    $this->postJson('/api/v1/weigh-ins', ['weight_kg' => 58.6])->assertCreated();
    $this->postJson('/api/v1/weigh-ins', ['weight_kg' => 58.1, 'date' => '2026-08-24'])->assertCreated();

    expect($this->getJson('/api/v1/weigh-ins')->assertOk()->json('data'))->toBe([
        ['id' => WeighIn::where('date', '2026-08-24')->sole()->id, 'date' => '2026-08-24', 'weight_kg' => 58.1],
        ['id' => WeighIn::where('date', '2026-09-23')->sole()->id, 'date' => '2026-09-23', 'weight_kg' => 58.6],
    ]);
});

it('recusa peso fora da faixa, mais de uma casa, data futura ou antiga demais', function (array $corpo, string $campo, string $mensagem) {
    $this->postJson('/api/v1/weigh-ins', $corpo)->assertUnprocessable()->assertJsonPath("errors.{$campo}.0", $mensagem);
})->with([
    'leve demais' => [['weight_kg' => 29.9], 'weight_kg', 'O peso precisa ficar entre 30 e 250 kg.'],
    'pesado demais' => [['weight_kg' => 250.1], 'weight_kg', 'O peso precisa ficar entre 30 e 250 kg.'],
    'duas casas' => [['weight_kg' => 58.65], 'weight_kg', 'Use no máximo uma casa decimal.'],
    'amanhã' => [['weight_kg' => 58.6, 'date' => '2026-09-24'], 'date', 'A pesagem não pode ser de um dia que ainda não chegou.'],
    '31 dias atrás' => [['weight_kg' => 58.6, 'date' => '2026-08-23'], 'date', 'Só dá para registrar pesagens dos últimos 30 dias.'],
]);

it('não mostra pesagens de outra pessoa', function () {
    User::factory()->onboarded()->create()->weighIns()->create(['date' => '2026-09-20', 'weight_kg' => 80.0]);

    expect($this->getJson('/api/v1/weigh-ins')->json('data'))->toBe([]);
});

it('onboarding incompleto recebe 409', function () {
    login(User::factory()->answered()->create());

    $this->postJson('/api/v1/weigh-ins', ['weight_kg' => 58.6])->assertStatus(409)->assertJsonPath('code', 'ONBOARDING_INCOMPLETE');
});

it('duas abas ao mesmo tempo: a segunda vira atualização, nunca erro', function () {
    $service = app(WeighInService::class);
    $hoje = CarbonImmutable::today();
    // A outra aba gravou entre a leitura e a escrita desta: o create estoura o índice único.
    WeighIn::creating(function (WeighIn $novo) use ($hoje) {
        WeighIn::flushEventListeners();
        $this->user->weighIns()->create(['date' => $hoje->toDateString(), 'weight_kg' => 58.0]);
        throw new UniqueConstraintViolationException('mysql', 'insert', [], new Exception('Duplicate entry'));
    });

    $resultado = $service->record($this->user, 58.7, $hoje);

    expect($resultado['replaced'])->toBeTrue()->and(WeighIn::count())->toBe(1)->and(WeighIn::sole()->weight_kg)->toBe(58.7);
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Progress/WeighInTest.php`
Expected: FAIL — rota e classe inexistentes.

- [ ] **Step 2: Implementar**

`app/Services/Progress/WeighInService.php`:
```php
<?php

namespace App\Services\Progress;

use App\Models\User;
use App\Models\WeighIn;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/** RN34 — uma pesagem por data; registrar de novo na mesma data substitui. */
final class WeighInService
{
    /**
     * @return array{weighIn: WeighIn, replaced: bool}
     */
    public function record(User $user, float $kg, CarbonImmutable $date): array
    {
        $existente = $user->weighIns()->whereDate('date', $date)->first();
        if ($existente !== null) {
            $existente->update(['weight_kg' => $kg]);

            return ['weighIn' => $existente, 'replaced' => true];
        }

        try {
            return ['weighIn' => $user->weighIns()->create(['date' => $date->toDateString(), 'weight_kg' => $kg]), 'replaced' => false];
        } catch (UniqueConstraintViolationException) {
            // Outra aba gravou a mesma data entre a leitura e a escrita: vira atualização.
            $existente = $user->weighIns()->whereDate('date', $date)->sole();
            $existente->update(['weight_kg' => $kg]);

            return ['weighIn' => $existente, 'replaced' => true];
        }
    }
}
```

`app/Http/Requests/WeighInRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** RN34 — peso 30–250 kg com uma casa; data entre hoje − 30 e hoje. */
class WeighInRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'weight_kg' => ['required', 'numeric', 'decimal:0,1', 'between:30,250'],
            'date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.today()->subDays(30)->toDateString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'weight_kg.between' => 'O peso precisa ficar entre 30 e 250 kg.',
            'weight_kg.decimal' => 'Use no máximo uma casa decimal.',
            'date.before_or_equal' => 'A pesagem não pode ser de um dia que ainda não chegou.',
            'date.after_or_equal' => 'Só dá para registrar pesagens dos últimos 30 dias.',
        ];
    }
}
```

`app/Http/Resources/WeighInResource.php`:
```php
<?php

namespace App\Http\Resources;

use App\Models\WeighIn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WeighIn */
class WeighInResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'date' => $this->date->toDateString(), 'weight_kg' => (float) $this->weight_kg];
    }
}
```

`app/Http/Controllers/Api/V1/WeighInController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\WeighInRequest;
use App\Http\Resources\WeighInResource;
use App\Models\User;
use App\Services\Progress\WeighInService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WeighInController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return WeighInResource::collection($user->weighIns()->orderBy('date')->get());
    }

    public function store(WeighInRequest $request, WeighInService $weighIns): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $date = $request->filled('date') ? CarbonImmutable::parse($request->string('date')->toString()) : CarbonImmutable::today();
        $result = $weighIns->record($user, (float) $request->input('weight_kg'), $date);

        return (new WeighInResource($result['weighIn']))
            ->additional(['meta' => ['replaced' => $result['replaced']]])
            ->response()
            ->setStatusCode($result['replaced'] ? 200 : 201);
    }
}
```

`routes/api.php` — `use App\Http\Controllers\Api\V1\WeighInController;` e, dentro do grupo `onboarded`:
```php
            Route::get('weigh-ins', [WeighInController::class, 'index']);
            Route::post('weigh-ins', [WeighInController::class, 'store']);
```

(O peso vem como `float`: `decimal:0,1` do Laravel valida o texto do número, então `58.65` falha e `58.6` passa. Se `WeighIn::sole()->weight_kg` vier `58.6` como string do MySQL, o cast `float` do model resolve.)

- [ ] **Step 3: Rodar, lint e commit**

Run: `docker compose run --rm api bash -c "php artisan test tests/Feature/Progress/WeighInTest.php && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(evolucao): registrar e listar pesagens (RN34)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `WeightForecast` (RN35)

**Files:**
- Create: `app/Services/Progress/WeightForecast.php`
- Test: `tests/Unit/Progress/WeightForecastTest.php`

**Interfaces:**
- Produces: `WeightForecast::estimate(list<array{date: string, weight_kg: float}> $points, ?float $goalKg, CarbonImmutable $today): ?array{date: string, label: string}`; `WeightForecast::label(CarbonImmutable $date, CarbonImmutable $today): string`.

- [ ] **Step 1: Teste que deve falhar**

`tests/Unit/Progress/WeightForecastTest.php`:
```php
<?php

use App\Services\Progress\WeightForecast;
use Carbon\CarbonImmutable;

/** As 6 pesagens do mock: 56,8 → 58,4 em 5 semanas. */
function pesagensDoMock(): array
{
    return array_map(fn ($p) => ['date' => $p[0], 'weight_kg' => $p[1]], [
        ['2026-08-11', 56.8], ['2026-08-18', 57.0], ['2026-08-25', 57.5], ['2026-09-01', 57.6], ['2026-09-08', 58.0], ['2026-09-15', 58.4],
    ]);
}

beforeEach(fn () => $this->hoje = CarbonImmutable::parse('2026-09-15'));

it('regressão linear sobre as pesagens do mock chega na meta de 62 kg em 05/12 (CA03)', function () {
    // inclinação 0,04531 kg/dia, intercepto 56,757 ⇒ 115,7 dias depois de 11/08.
    expect((new WeightForecast)->estimate(pesagensDoMock(), 62.0, $this->hoje))
        ->toBe(['date' => '2026-12-05', 'label' => 'início de dezembro']);
});

it('sem previsão: sem meta, menos de 3 pesagens ou menos de 14 dias', function (array $pontos, ?float $meta) {
    expect((new WeightForecast)->estimate($pontos, $meta, $this->hoje))->toBeNull();
})->with([
    'sem meta (mais disposição)' => [pesagensDoMock(), null],
    'duas pesagens' => [array_slice(pesagensDoMock(), 0, 2), 62.0],
    'três em 13 dias' => [[['date' => '2026-09-02', 'weight_kg' => 57.0], ['date' => '2026-09-09', 'weight_kg' => 57.5], ['date' => '2026-09-15', 'weight_kg' => 58.0]], 62.0],
]);

it('pesagens se afastando da meta: sem previsão (CA04)', function () {
    expect((new WeightForecast)->estimate(pesagensDoMock(), 50.0, $this->hoje))->toBeNull();
});

it('já passou da meta: sem previsão', function () {
    expect((new WeightForecast)->estimate(pesagensDoMock(), 58.0, $this->hoje))->toBeNull();
});

it('perdendo peso, a reta descendo também prevê', function () {
    $pontos = array_map(fn ($p) => ['date' => $p['date'], 'weight_kg' => round(130 - $p['weight_kg'], 1)], pesagensDoMock()); // 73,2 → 71,6
    expect((new WeightForecast)->estimate($pontos, 68.0, $this->hoje)['date'] ?? null)->toBe('2026-12-05');
});

it('mais de 52 semanas: sem previsão', function () {
    $lento = [['date' => '2026-08-01', 'weight_kg' => 57.0], ['date' => '2026-08-20', 'weight_kg' => 57.1], ['date' => '2026-09-15', 'weight_kg' => 57.2]];
    expect((new WeightForecast)->estimate($lento, 62.0, $this->hoje))->toBeNull();
});

it('rótulo: início, meados e fim do mês; o ano aparece só quando o mês se repetiria', function (string $data, string $rotulo) {
    expect(WeightForecast::label(CarbonImmutable::parse($data), CarbonImmutable::parse('2026-09-15')))->toBe($rotulo);
})->with([
    ['2026-12-10', 'início de dezembro'],
    ['2026-12-11', 'meados de dezembro'],
    ['2026-12-20', 'meados de dezembro'],
    ['2027-01-21', 'fim de janeiro'],
    ['2027-09-02', 'início de setembro de 2027'],
]);
```

Run: `docker compose run --rm api php artisan test tests/Unit/Progress/WeightForecastTest.php`
Expected: FAIL — classe inexistente.

- [ ] **Step 2: Implementar**

`app/Services/Progress/WeightForecast.php`:
```php
<?php

namespace App\Services\Progress;

use Carbon\CarbonImmutable;

/**
 * RN35 — quando a pessoa chega na meta: regressão linear (peso × dia) sobre as pesagens do período.
 * Precisa de meta, ≥ 3 pesagens cobrindo ≥ 14 dias e a reta indo na direção da meta; horizonte de 52 semanas. Puro.
 */
final class WeightForecast
{
    private const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    /**
     * @param  list<array{date: string, weight_kg: float}>  $points  em ordem crescente de data
     * @return array{date: string, label: string}|null
     */
    public function estimate(array $points, ?float $goalKg, CarbonImmutable $today): ?array
    {
        if ($goalKg === null || count($points) < 3) {
            return null;
        }

        $inicio = CarbonImmutable::parse($points[0]['date']);
        $xs = array_map(fn (array $p) => $inicio->diffInDays(CarbonImmutable::parse($p['date'])), $points);
        $ys = array_map(fn (array $p) => (float) $p['weight_kg'], $points);
        if (end($xs) < 14) {
            return null;
        }

        $n = count($xs);
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $sxy = 0.0;
        $sxx = 0.0;
        foreach ($xs as $i => $x) {
            $sxy += ($x - $mx) * ($ys[$i] - $my);
            $sxx += ($x - $mx) ** 2;
        }
        $inclinacao = $sxy / $sxx;
        $ultimo = end($ys);

        $faltam = $goalKg - $ultimo;
        if ($faltam == 0.0 || $inclinacao == 0.0 || ($faltam > 0) !== ($inclinacao > 0)) {
            return null; // já chegou, ou a reta se afasta da meta
        }

        $intercepto = $my - $inclinacao * $mx;
        $data = $inicio->addDays((int) round(($goalKg - $intercepto) / $inclinacao));
        if ($data->lessThanOrEqualTo($today) || $data->greaterThan($today->addWeeks(52))) {
            return null;
        }

        return ['date' => $data->toDateString(), 'label' => self::label($data, $today)];
    }

    /** "início/meados/fim de {mês}", com o ano quando o mês se repetiria dentro do horizonte. */
    public static function label(CarbonImmutable $date, CarbonImmutable $today): string
    {
        $parte = $date->day <= 10 ? 'início' : ($date->day <= 20 ? 'meados' : 'fim');
        $ano = $date->year > $today->year && $date->month >= $today->month ? " de {$date->year}" : '';

        return "{$parte} de ".self::MESES[$date->month - 1].$ano;
    }
}
```

- [ ] **Step 3: Rodar, lint e commit**

Run: `docker compose run --rm api bash -c "php artisan test tests/Unit/Progress/WeightForecastTest.php && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(evolucao): previsão de chegada na meta por regressão linear (RN35)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `AdherenceCalculator` (RN36)

**Files:**
- Create: `app/Services/Progress/AdherenceCalculator.php`
- Test: `tests/Unit/Progress/AdherenceCalculatorTest.php`

**Interfaces:**
- Produces: `AdherenceCalculator::compute(array<string, array{total: int, done: int}> $days, CarbonImmutable $today): array{days: list<array{date: string, status: string}>, complete_days: int, streak: int}` — `$days` indexado por `Y-m-d`, só os dias materializados.

- [ ] **Step 1: Teste que deve falhar**

`tests/Unit/Progress/AdherenceCalculatorTest.php`:
```php
<?php

use App\Services\Progress\AdherenceCalculator;
use Carbon\CarbonImmutable;

/** O padrão de 28 dias do mock, do mais antigo (hoje − 27) até hoje. */
const PADRAO_DO_MOCK = [
    'completo', 'completo', 'parcial', 'completo', 'completo', 'vazio', 'completo',
    'completo', 'parcial', 'completo', 'completo', 'completo', 'completo', 'vazio',
    'completo', 'completo', 'completo', 'parcial', 'completo', 'completo', 'completo',
    'completo', 'completo', 'vazio', 'completo', 'completo', 'completo', 'hoje',
];

beforeEach(fn () => $this->hoje = CarbonImmutable::parse('2026-09-21'));

/** Dias materializados que produzem o padrão (vazio = não materializado ou nada feito). */
function diasDoPadrao(CarbonImmutable $hoje): array
{
    $dias = [];
    foreach (PADRAO_DO_MOCK as $i => $status) {
        $data = $hoje->subDays(27 - $i)->toDateString();
        $dias[$data] = match ($status) {
            'completo' => ['total' => 5, 'done' => 5],
            'parcial' => ['total' => 5, 'done' => 2],
            'vazio' => $i % 2 === 0 ? ['total' => 5, 'done' => 0] : null,
            'hoje' => ['total' => 5, 'done' => 3],
        };
    }

    return array_filter($dias);
}

it('padrão do mock: status por dia, 21 dias completos e sequência de 3 terminando ontem (CA05)', function () {
    $resultado = (new AdherenceCalculator)->compute(diasDoPadrao($this->hoje), $this->hoje);

    expect(array_column($resultado['days'], 'status'))->toBe(PADRAO_DO_MOCK)
        ->and($resultado['days'][0]['date'])->toBe('2026-08-25')
        ->and($resultado['days'][27]['date'])->toBe('2026-09-21')
        ->and($resultado['complete_days'])->toBe(21)
        ->and($resultado['streak'])->toBe(3);
});

it('hoje completo não entra na sequência; ontem vazio zera', function () {
    $dias = [$this->hoje->toDateString() => ['total' => 5, 'done' => 5], $this->hoje->subDays(2)->toDateString() => ['total' => 5, 'done' => 5]];

    $resultado = (new AdherenceCalculator)->compute($dias, $this->hoje);

    expect($resultado['streak'])->toBe(0)->and($resultado['days'][27]['status'])->toBe('hoje')->and($resultado['complete_days'])->toBe(1);
});

it('nada materializado: 27 vazios e hoje', function () {
    $resultado = (new AdherenceCalculator)->compute([], $this->hoje);

    expect(array_count_values(array_column($resultado['days'], 'status')))->toBe(['vazio' => 27, 'hoje' => 1])
        ->and($resultado['complete_days'])->toBe(0)->and($resultado['streak'])->toBe(0);
});

it('sequência longa: 27 dias completos seguidos', function () {
    $dias = [];
    for ($i = 1; $i <= 27; $i++) {
        $dias[$this->hoje->subDays($i)->toDateString()] = ['total' => 5, 'done' => 5];
    }

    expect((new AdherenceCalculator)->compute($dias, $this->hoje)['streak'])->toBe(27);
});
```

Run: `docker compose run --rm api php artisan test tests/Unit/Progress/AdherenceCalculatorTest.php`
Expected: FAIL — classe inexistente.

- [ ] **Step 2: Implementar**

`app/Services/Progress/AdherenceCalculator.php`:
```php
<?php

namespace App\Services\Progress;

use Carbon\CarbonImmutable;

/**
 * RN36 — constância dos últimos 28 dias (inclui hoje). Sequência = dias completos seguidos terminando ontem. Puro.
 */
final class AdherenceCalculator
{
    public const JANELA = 28;

    /**
     * @param  array<string, array{total: int, done: int}>  $days  dias materializados, por `Y-m-d`
     * @return array{days: list<array{date: string, status: string}>, complete_days: int, streak: int}
     */
    public function compute(array $days, CarbonImmutable $today): array
    {
        $lista = [];
        for ($i = self::JANELA - 1; $i >= 0; $i--) {
            $data = $today->subDays($i)->toDateString();
            $lista[] = ['date' => $data, 'status' => $i === 0 ? 'hoje' : $this->status($days[$data] ?? null)];
        }

        $sequencia = 0;
        for ($i = self::JANELA - 2; $i >= 0 && $lista[$i]['status'] === 'completo'; $i--) {
            $sequencia++;
        }

        return [
            'days' => $lista,
            'complete_days' => count(array_filter($lista, fn (array $d) => $d['status'] === 'completo')),
            'streak' => $sequencia,
        ];
    }

    /**
     * @param  array{total: int, done: int}|null  $dia
     */
    private function status(?array $dia): string
    {
        if ($dia === null || $dia['done'] === 0) {
            return 'vazio';
        }

        return $dia['done'] >= $dia['total'] ? 'completo' : 'parcial';
    }
}
```

- [ ] **Step 3: Rodar, lint e commit**

Run: `docker compose run --rm api bash -c "php artisan test tests/Unit/Progress/AdherenceCalculatorTest.php && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(evolucao): constância dos últimos 28 dias e sequência (RN36)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `ProgressService` e `GET /progress` (RF24, RF25, RN37)

**Files:**
- Create: `app/Services/Progress/ProgressService.php`, `app/Http/Controllers/Api/V1/ProgressController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Progress/ProgressTest.php`

**Interfaces:**
- Consumes: `WeightForecast` (Task 2), `AdherenceCalculator` (Task 3), `DayTotals::item/sum`, `DayMaterializer::isTrainingDay`, `User::activePlan()`, helpers de teste `planoPronto`, `login`.
- Produces: `ProgressService::show(User $user, string $period, CarbonImmutable $today): array` no formato do §5; rota `GET /api/v1/progress?period=6w|3m|all`.

- [ ] **Step 1: Teste que deve falhar**

`tests/Feature/Progress/ProgressTest.php`:
```php
<?php

use App\Models\User;
use Carbon\CarbonImmutable;

const MARCA_DE_HOJE = '2026-09-28 20:00';

beforeEach(function () {
    seedCatalog();
    $this->user = login(User::factory()->onboarded()->create()); // treina seg, qua, sex; meta 62 kg
});

function pesagens(User $user, array $pares): void
{
    foreach ($pares as [$data, $kg]) {
        $user->weighIns()->create(['date' => $data, 'weight_kg' => $kg]);
    }
}

/** Vai até o dia, materializa e marca as refeições; devolve o consumido do dia. */
function comerNoDia(string $data, array $slots): array
{
    test()->travelTo(CarbonImmutable::parse("{$data} 21:00", 'America/Sao_Paulo'));
    test()->getJson('/api/v1/days/today')->assertOk();
    foreach ($slots as $slot) {
        test()->patchJson("/api/v1/days/today/meals/{$slot}", ['done' => true])->assertOk();
    }

    return test()->getJson('/api/v1/days/today')->json('data.totals.consumed');
}

it('só a pesagem do onboarding: um ponto, sem previsão (CA01)', function () {
    $this->travelTo(CarbonImmutable::parse(MARCA_DE_HOJE, 'America/Sao_Paulo'));
    pesagens($this->user, [['2026-09-28', 58.4]]);

    $peso = $this->getJson('/api/v1/progress')->assertOk()->json('data.weight');

    expect($peso)->toMatchArray(['start_kg' => 58.4, 'current_kg' => 58.4, 'goal_kg' => 62.0, 'goal_source' => 'user', 'change_kg' => 0.0, 'span_weeks' => 0, 'forecast' => null])
        ->and($peso['points'])->toBe([['date' => '2026-09-28', 'weight_kg' => 58.4]]);
});

it('as 6 pesagens do mock: variação, semanas reais e previsão (CA03)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-15 20:00', 'America/Sao_Paulo'));
    pesagens($this->user, [['2026-08-11', 56.8], ['2026-08-18', 57.0], ['2026-08-25', 57.5], ['2026-09-01', 57.6], ['2026-09-08', 58.0], ['2026-09-15', 58.4]]);

    $dados = $this->getJson('/api/v1/progress?period=6w')->assertOk()->json('data');

    expect($dados['period'])->toBe('6w')
        ->and($dados['weight'])->toMatchArray(['start_kg' => 56.8, 'current_kg' => 58.4, 'change_kg' => 1.6, 'span_weeks' => 5])
        ->and($dados['weight']['forecast'])->toBe(['date' => '2026-12-05', 'label' => 'início de dezembro']);
});

it('o período limita os pontos: 3m deixa de fora o que tem mais de 91 dias', function () {
    $this->travelTo(CarbonImmutable::parse(MARCA_DE_HOJE, 'America/Sao_Paulo'));
    pesagens($this->user, [['2026-06-01', 55.0], ['2026-07-01', 56.0], ['2026-09-28', 58.4]]);

    expect(array_column($this->getJson('/api/v1/progress?period=3m')->json('data.weight.points'), 'date'))->toBe(['2026-07-01', '2026-09-28'])
        ->and(array_column($this->getJson('/api/v1/progress?period=all')->json('data.weight.points'), 'date'))->toBe(['2026-06-01', '2026-07-01', '2026-09-28'])
        ->and($this->getJson('/api/v1/progress?period=6w')->json('data.weight.start_kg'))->toBe(58.4);
});

it('sem pesagens no período: pontos vazios e números nulos', function () {
    $this->travelTo(CarbonImmutable::parse(MARCA_DE_HOJE, 'America/Sao_Paulo'));

    expect($this->getJson('/api/v1/progress')->json('data.weight'))
        ->toMatchArray(['start_kg' => null, 'current_kg' => null, 'change_kg' => null, 'span_weeks' => null, 'points' => [], 'forecast' => null]);
});

it('"mais disposição": sem meta e sem previsão (CA07)', function () {
    $this->user->profile->update(['goal' => 'mais-disposicao', 'goal_weight_kg' => null, 'goal_weight_source' => null]);
    $this->travelTo(CarbonImmutable::parse('2026-09-15 20:00', 'America/Sao_Paulo'));
    pesagens($this->user, [['2026-08-11', 56.8], ['2026-08-25', 57.5], ['2026-09-15', 58.4]]);

    expect($this->getJson('/api/v1/progress')->json('data.weight'))->toMatchArray(['goal_kg' => null, 'goal_source' => null, 'forecast' => null]);
});

it('período inválido: 422', function () {
    $this->getJson('/api/v1/progress?period=1y')->assertUnprocessable();
});

it('constância dos 28 dias vem de day_meals, e as médias só contam dias com refeição feita', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-21 07:00', 'America/Sao_Paulo')); // o plano nasce antes dos dias
    planoPronto($this->user);
    $segunda = comerNoDia('2026-09-21', ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar']); // treino, completo
    $terca = comerNoDia('2026-09-22', ['cafe']);                                                // descanso, parcial
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00', 'America/Sao_Paulo'));
    $this->getJson('/api/v1/days/today')->assertOk();                                            // hoje, nada feito

    $dados = $this->getJson('/api/v1/progress')->assertOk()->json('data');
    $dias = collect($dados['adherence']['days'])->pluck('status', 'date');
    $plano = $this->user->activePlan()->sole();

    expect($dias['2026-09-21'])->toBe('completo')->and($dias['2026-09-22'])->toBe('parcial')->and($dias['2026-09-23'])->toBe('hoje')
        ->and($dias['2026-09-20'])->toBe('vazio')
        ->and($dados['adherence']['complete_days'])->toBe(1)->and($dados['adherence']['streak'])->toBe(0)
        ->and($dados['averages'])->toMatchArray([
            'days_counted' => 2,
            'protein' => ['avg_g' => (int) round(($segunda['protein'] + $terca['protein']) / 2), 'target_g' => $plano->target_protein_g],
            'calories' => ['avg_kcal' => (int) round(($segunda['calories'] + $terca['calories']) / 2), 'target_kcal' => $plano->target_kcal],
        ]);
});

it('observação: proteína baixa nos dias sem treino (RN37)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-21 07:00', 'America/Sao_Paulo'));
    planoPronto($this->user);
    comerNoDia('2026-09-21', ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar']); // segunda, treino: ~100%
    comerNoDia('2026-09-22', ['cafe', 'lanche']);                                   // terça, descanso: bem abaixo

    expect($this->getJson('/api/v1/progress')->json('data.averages.insight'))
        ->toBe('Você fica um pouco abaixo da meta de proteína nos dias sem treino.');
});

it('sem observação quando só há dias de treino', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-21 07:00', 'America/Sao_Paulo'));
    planoPronto($this->user);
    comerNoDia('2026-09-21', ['cafe']);

    expect($this->getJson('/api/v1/progress')->json('data.averages.insight'))->toBeNull();
});

it('nenhum dia com refeição feita: médias vazias (CA06)', function () {
    $this->travelTo(CarbonImmutable::parse(MARCA_DE_HOJE, 'America/Sao_Paulo'));
    planoPronto($this->user);
    $this->getJson('/api/v1/days/today')->assertOk();

    expect($this->getJson('/api/v1/progress')->json('data.averages'))->toBe([
        'days_counted' => 0,
        'protein' => ['avg_g' => null, 'target_g' => $this->user->activePlan()->sole()->target_protein_g],
        'calories' => ['avg_kcal' => null, 'target_kcal' => $this->user->activePlan()->sole()->target_kcal],
        'insight' => null,
    ]);
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Progress/ProgressTest.php`
Expected: FAIL — rota inexistente.

- [ ] **Step 2: Implementar**

`app/Services/Progress/ProgressService.php`:
```php
<?php

namespace App\Services\Progress;

use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Models\User;
use App\Models\WeighIn;
use App\Services\Days\DayMaterializer;
use App\Services\Nutrition\DayTotals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** RF24/RF25 — tudo que a tela de Evolução mostra, num período (RN35–RN37). */
final class ProgressService
{
    /** Dias de cada período (incluindo hoje); `all` não tem limite. */
    public const PERIODOS = ['6w' => 42, '3m' => 91, 'all' => null];

    private const INSIGHT = 'Você fica um pouco abaixo da meta de proteína nos dias sem treino.';

    public function __construct(
        private readonly WeightForecast $forecast,
        private readonly AdherenceCalculator $adherence,
        private readonly DayMaterializer $days,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, string $period, CarbonImmutable $today): array
    {
        $dias = self::PERIODOS[$period];
        $desde = $dias === null ? null : $today->subDays($dias - 1);

        return [
            'period' => $period,
            'weight' => $this->weight($user, $desde, $today),
            'adherence' => $this->adherence($user, $today),
            'averages' => $this->averages($user, $desde, $today),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function weight(User $user, ?CarbonImmutable $desde, CarbonImmutable $today): array
    {
        $pontos = $user->weighIns()
            ->when($desde, fn ($q) => $q->whereDate('date', '>=', $desde))
            ->orderBy('date')
            ->get()
            ->map(fn (WeighIn $w) => ['date' => $w->date->toDateString(), 'weight_kg' => (float) $w->weight_kg])
            ->all();
        $profile = $user->profile;
        $meta = $profile->goal_weight_kg === null ? null : (float) $profile->goal_weight_kg;
        $primeiro = $pontos[0] ?? null;
        $ultimo = $pontos === [] ? null : $pontos[count($pontos) - 1];

        return [
            'start_kg' => $primeiro['weight_kg'] ?? null,
            'current_kg' => $ultimo['weight_kg'] ?? null,
            'goal_kg' => $meta,
            'goal_source' => $meta === null ? null : $profile->goal_weight_source,
            'change_kg' => $ultimo === null ? null : round($ultimo['weight_kg'] - $primeiro['weight_kg'], 1),
            'span_weeks' => $ultimo === null ? null : (int) round(CarbonImmutable::parse($primeiro['date'])->diffInDays(CarbonImmutable::parse($ultimo['date'])) / 7),
            'points' => $pontos,
            'forecast' => $this->forecast->estimate($pontos, $meta, $today),
        ];
    }

    /**
     * @return array{days: list<array{date: string, status: string}>, complete_days: int, streak: int}
     */
    private function adherence(User $user, CarbonImmutable $today): array
    {
        $linhas = DB::table('day_meals')
            ->where('user_id', $user->id)
            ->whereBetween('date', [$today->subDays(AdherenceCalculator::JANELA - 1)->toDateString(), $today->toDateString()])
            ->groupBy('date')
            ->selectRaw('date, count(*) as total, sum(done_at is not null) as done')
            ->get();

        $dias = [];
        foreach ($linhas as $linha) {
            $dias[CarbonImmutable::parse($linha->date)->toDateString()] = ['total' => (int) $linha->total, 'done' => (int) $linha->done];
        }

        return $this->adherence->compute($dias, $today);
    }

    /**
     * RN37 — médias diárias do consumido nos dias com ≥ 1 refeição feita, contra as metas do plano ativo.
     *
     * @return array<string, mixed>
     */
    private function averages(User $user, ?CarbonImmutable $desde, CarbonImmutable $today): array
    {
        $plano = $user->activePlan()->first();
        $porDia = $user->dayMeals()
            ->whereNotNull('done_at')
            ->when($desde, fn ($q) => $q->whereDate('date', '>=', $desde))
            ->whereDate('date', '<=', $today)
            ->with('items.food')
            ->get()
            ->groupBy(fn (DayMeal $meal) => $meal->date->toDateString())
            ->map(fn ($meals) => DayTotals::sum($meals->flatMap(fn (DayMeal $meal) => $meal->items->map(
                fn (DayMealItem $item) => DayTotals::item($item->food, (float) $item->grams),
            ))->values()->all()));

        $metaProteina = $plano?->target_protein_g;
        $contados = $porDia->count();

        return [
            'days_counted' => $contados,
            'protein' => ['avg_g' => $contados === 0 ? null : (int) round($porDia->avg('protein')), 'target_g' => $metaProteina],
            'calories' => ['avg_kcal' => $contados === 0 ? null : (int) round($porDia->avg('calories')), 'target_kcal' => $plano?->target_kcal],
            'insight' => $this->insight($user, $porDia->map(fn (array $t) => $t['protein'])->all(), $metaProteina),
        ];
    }

    /**
     * Proteína nos dias sem treino < 90% da meta e ao menos 10 pontos percentuais abaixo dos dias de treino.
     *
     * @param  array<string, float>  $proteinaPorDia
     */
    private function insight(User $user, array $proteinaPorDia, ?int $meta): ?string
    {
        if (! $meta) {
            return null;
        }
        $treino = [];
        $descanso = [];
        foreach ($proteinaPorDia as $data => $proteina) {
            if ($this->days->isTrainingDay($user, CarbonImmutable::parse($data))) {
                $treino[] = $proteina;
            } else {
                $descanso[] = $proteina;
            }
        }
        if ($treino === [] || $descanso === []) {
            return null;
        }
        $pctTreino = array_sum($treino) / count($treino) / $meta * 100;
        $pctDescanso = array_sum($descanso) / count($descanso) / $meta * 100;

        return $pctDescanso < 90 && $pctTreino - $pctDescanso >= 10 ? self::INSIGHT : null;
    }
}
```

`app/Http/Controllers/Api/V1/ProgressController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Progress\ProgressService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProgressController extends Controller
{
    public function __invoke(Request $request, ProgressService $progress): JsonResponse
    {
        $request->validate(['period' => ['sometimes', Rule::in(array_keys(ProgressService::PERIODOS))]]);
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $progress->show($user, $request->string('period', '6w')->toString(), CarbonImmutable::today())]);
    }
}
```

`routes/api.php` — `use App\Http\Controllers\Api\V1\ProgressController;` e, no grupo `onboarded`:
```php
            Route::get('progress', ProgressController::class);
```

(`sum(done_at is not null)` funciona no MySQL; se o Larastan reclamar do `$linha->total` em `stdClass`, é `mixed` → o `(int)` resolve. Se `DayMaterializer::isTrainingDay` exigir o perfil carregado, `$user->profile` já vem pela relação.)

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm api bash -c "php artisan test tests/Feature/Progress tests/Unit/Progress && php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(evolucao): GET /progress com peso, previsão, constância e médias (RF24, RF25, RN37)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Contas do E2E-09 no `E2ESeeder`

**Files:**
- Modify: `database/seeders/E2ESeeder.php`
- Test: `tests/Feature/Seeders/E2ESeederTest.php`

**Interfaces:**
- Produces: contas `peso-{chromium,webkit}@e2e.pratoforte.test` (senha `senha1234`), onboarding concluído, plano pronto, pesagens em hoje − 21 (58,4), hoje − 14 (58,7) e hoje − 7 (59,0) — o E2E-09 registra a de hoje.

- [ ] **Step 1: Teste que deve falhar**

Acrescentar a `tests/Feature/Seeders/E2ESeederTest.php`:
```php
it('cria as contas da Evolução com três pesagens semanais', function () {
    $this->seed(\Database\Seeders\E2ESeeder::class);

    foreach (['chromium', 'webkit'] as $navegador) {
        $user = \App\Models\User::where('email', "peso-{$navegador}@e2e.pratoforte.test")->sole();
        expect($user->activePlan()->exists())->toBeTrue()
            ->and($user->weighIns()->orderBy('date')->pluck('weight_kg')->all())->toBe([58.4, 58.7, 59.0])
            ->and($user->weighIns()->max('date'))->toBe(today()->subDays(7)->toDateString());
    }
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Seeders/E2ESeederTest.php`
Expected: FAIL — conta inexistente.

- [ ] **Step 2: Implementar**

Em `E2ESeeder::run()`, dentro do `foreach` de navegador, depois do bloco do 04B:
```php
            // 06: Evolução — três pesagens semanais; o E2E-09 registra a de hoje.
            $peso = User::factory()->onboarded()->create(['name' => 'Paula Prado', 'email' => "peso-{$navegador}@e2e.pratoforte.test"]);
            $planos->requestGeneration($peso);
            foreach ([[21, 58.4], [14, 58.7], [7, 59.0]] as [$dias, $kg]) {
                $peso->weighIns()->create(['date' => today()->subDays($dias)->toDateString(), 'weight_kg' => $kg]);
            }
```
(Se `weight_kg` voltar como string no `pluck`, compare com `->map(fn ($kg) => (float) $kg)`.)

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm api bash -c "php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: tudo verde.

```bash
git add -A && git commit -m "test(e2e): contas da Evolução no E2ESeeder (E2E-09)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
