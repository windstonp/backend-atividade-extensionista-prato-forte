# Plano 08A — Validação com a comunidade: API — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O servidor da validação (spec 07): avaliações 👍/👎 de respostas do Nutri e de planos (RN40), questionário de usabilidade SUS com convite (RN41) e a exportação anonimizada `php artisan validacao:exportar` (RN42).

**Architecture:** Tabela `ratings` polimórfica (`nutri_message`/`meal_plan`, morph map sem `enforce`) com índice único por usuário e item; `RatingService` valida posse e papel (404, RN43). `SusScore` puro; `UsabilityService` decide o convite (7 dias de onboarding ou 10 refeições feitas), grava a resposta por rodada (`config('validacao.rodada')`) e o "Agora não". `ValidationExporter` gera 4 CSVs (UTF-8 com BOM, `;`) com `usuario_hash = substr(sha256(user_id.APP_KEY), 0, 12)`; o comando imprime o resumo. As respostas do Nutri e o plano passam a trazer a avaliação da pessoa (`rating`).

**Tech Stack:** Laravel 12, PHP 8.3, MySQL 8, Pest, Larastan 6, Pint.

**Spec:** `specs/07-validacao-feedback/spec.md` (RF31–RF33, §5, §6, §7 CA01–CA07, §8), `specs/00-fundacao/regras-de-negocio.md` (RN03, RN06, RN40–RN43).

**Onde rodar:** `/home/alvez/atividade-extensionista/backend`, branch `plano-08a-validacao-api` saindo de `plano-07c-unidades`.

## Decisões deste plano (rulings sobre a spec)

1. **Morph map** `nutri_message` ⇒ `NutriMessage`, `meal_plan` ⇒ `MealPlan` com `Relation::morphMap` (sem `enforceMorphMap`, para não quebrar `push_subscriptions`, que guarda o nome da classe `User`).
2. **Apagar conversa** não apaga as avaliações das mensagens (elas saem só com a conta, RN06): a pesquisa perde o texto, não o 👍/👎 — e o texto da conversa nunca é exportado mesmo.
3. **"Agora não"** vale enquanto a rodada não muda: `usability_invite_dismissed_at` é comparado com `config('validacao.inicio')` (data de abertura da rodada); dispensado antes da rodada atual ⇒ convida de novo.
4. **Usuário "ativo no período"** (`uso.csv`): teve refeição marcada, pesagem ou pergunta ao Nutri no período.
5. **Trocas:** `trocas_manuais` = itens do dia com `source = manual`; `trocas_nutri` = `source = nutri` (ambos com a data do dia no período).
6. **Termo (RN03/RN42):** acrescenta a frase sobre os comentários livres; `terms_version` passa a `2026-10` (o front acompanha no Plano 08B — os dois precisam subir juntos).
7. **E2E-12:** contas `avaliar-{navegador}` com onboarding concluído há 8 dias e plano pronto (o convite aparece).

## Global Constraints

- `PUT /ratings`: `rateable_type` in:nutri_message,meal_plan; `rateable_id` integer; `value` in:up,down; `comment` nullable|string|max:500; 404 se o item não existe, não é da pessoa ou é mensagem do usuário (CA03). Resposta `{ data: { rateable_type, rateable_id, value, comment } }`. `DELETE /ratings` 204 idempotente.
- `GET /usability-responses/status` ⇒ `{ data: { round, responded, invite } }`; `POST /usability-responses` 201 `{ data: { round, responded: true } }`, 409 `ALREADY_RESPONDED`, 422 (`sus_answers` 10 inteiros 1–5, `usefulness` 1–5, `liked`/`disliked` até 1.000); `POST /usability-responses/dismiss` 204.
- SUS = ((Σ ímpares − 5) + (25 − Σ pares)) × 2,5 (CA05: `[4,2,5,1,4,2,5,1,4,2]` ⇒ 85,0).
- CSV: UTF-8 com BOM, separador `;`; sem nome, e-mail, texto de conversa, restrições ou data de nascimento; mesmo `usuario_hash` em todos os arquivos (CA07).
- Toda tabela nova com `user_id` entra no `DeleteAccountTest`.
- Docblocks multi-linha; nada de segredo no repo.

## Review Focus

1. **Avaliar a mensagem de outra pessoa ou a própria pergunta** ⇒ 404, nada gravado (Task 1, CA03).
2. **Mesma avaliação duas vezes / trocar 👍 por 👎** ⇒ uma linha só (Task 1).
3. **Dois envios do questionário ao mesmo tempo** ⇒ um 201 e um 409, nunca 500 (Task 2).
4. **Comentário com `;`, aspas e quebra de linha** ⇒ CSV continua com o número certo de colunas (Task 3).
5. **Exportar sem nenhuma resposta** ⇒ arquivos com cabeçalho e resumo "0 participantes", sem divisão por zero (Task 3).

---

### Task 1: Avaliações 👍/👎 — `ratings`, `PUT/DELETE /ratings` e `rating` nas respostas

**Files:**
- Create: `database/migrations/2026_10_04_000100_create_ratings_table.php`, `app/Models/Rating.php`, `app/Services/Validation/RatingService.php`, `app/Http/Controllers/Api/V1/RatingController.php`, `app/Http/Requests/RatingRequest.php`
- Modify: `app/Providers/AppServiceProvider.php` (morph map), `app/Models/{NutriMessage,MealPlan}.php` (`rating()`), `app/Http/Resources/{NutriMessageResource,PlanResource}.php`, `app/Http/Controllers/Api/V1/{MessageController,PlanController}.php` (eager load), `routes/api.php`, `tests/Feature/Auth/DeleteAccountTest.php`
- Test: `tests/Feature/Validation/RatingTest.php`

**Interfaces:**
- Produces: `Rating` (`user_id`, `rateable_type`, `rateable_id`, `value`, `comment`); `NutriMessage::rating()`/`MealPlan::rating()` (`morphOne`); `RatingService::rate(User, string $type, int $id, string $value, ?string $comment): Rating` e `remove(User, string $type, int $id): void`; `rating` nos resources: `{ value, comment } | null`.

- [ ] **Step 1: Branch e teste que deve falhar**

```bash
cd /home/alvez/atividade-extensionista/backend
git switch plano-07c-unidades && git switch -c plano-08a-validacao-api
```

`tests/Feature/Validation/RatingTest.php`:
```php
<?php

use App\Models\NutriMessage;
use App\Models\Rating;
use App\Models\User;

beforeEach(function () {
    seedCatalog();
    $this->user = login(User::factory()->onboarded()->create());
    $conversa = $this->user->conversations()->create(['title' => 'Arroz']);
    $this->pergunta = $conversa->messages()->create(['role' => 'user', 'content' => 'Posso trocar o arroz?']);
    $this->resposta = $conversa->messages()->create(['role' => 'assistant', 'content' => 'Pode.']);
});

function avaliar(array $corpo)
{
    return test()->putJson('/api/v1/ratings', $corpo);
}

it('marca 👍 numa resposta do Nutri e ela volta nas mensagens (CA01)', function () {
    avaliar(['rateable_type' => 'nutri_message', 'rateable_id' => $this->resposta->id, 'value' => 'up'])
        ->assertOk()
        ->assertExactJson(['data' => ['rateable_type' => 'nutri_message', 'rateable_id' => $this->resposta->id, 'value' => 'up', 'comment' => null]]);

    $mensagens = collect($this->getJson("/api/v1/conversations/{$this->resposta->conversation_id}/messages")->json('data'));
    expect($mensagens->firstWhere('id', $this->resposta->id)['rating'])->toBe(['value' => 'up', 'comment' => null]);
});

it('👎 com comentário e depois trocar: uma linha só (CA02)', function () {
    avaliar(['rateable_type' => 'nutri_message', 'rateable_id' => $this->resposta->id, 'value' => 'down', 'comment' => 'Não tenho batata-doce em casa.'])->assertOk();
    avaliar(['rateable_type' => 'nutri_message', 'rateable_id' => $this->resposta->id, 'value' => 'up'])->assertOk();

    expect(Rating::count())->toBe(1)->and(Rating::sole()->only(['value', 'comment']))->toBe(['value' => 'up', 'comment' => null]);
});

it('DELETE remove e é idempotente', function () {
    avaliar(['rateable_type' => 'nutri_message', 'rateable_id' => $this->resposta->id, 'value' => 'up'])->assertOk();
    $corpo = ['rateable_type' => 'nutri_message', 'rateable_id' => $this->resposta->id];

    $this->deleteJson('/api/v1/ratings', $corpo)->assertNoContent();
    $this->deleteJson('/api/v1/ratings', $corpo)->assertNoContent();

    expect(Rating::count())->toBe(0);
});

it('avalia o plano e ele traz a avaliação', function () {
    $plano = planoPronto($this->user);
    avaliar(['rateable_type' => 'meal_plan', 'rateable_id' => $plano->id, 'value' => 'down'])->assertOk();

    expect($this->getJson("/api/v1/plans/{$plano->id}")->json('data.rating'))->toBe(['value' => 'down', 'comment' => null]);
});

it('404 para a própria pergunta, mensagem de outra pessoa ou item que não existe (CA03)', function (Closure $alvo) {
    [$tipo, $id] = $alvo($this);
    avaliar(['rateable_type' => $tipo, 'rateable_id' => $id, 'value' => 'up'])->assertNotFound();

    expect(Rating::count())->toBe(0);
})->with([
    'pergunta do usuário' => [fn ($t) => ['nutri_message', $t->pergunta->id]],
    'de outra pessoa' => [function ($t) {
        $outra = User::factory()->onboarded()->create()->conversations()->create(['title' => 'x'])->messages()->create(['role' => 'assistant', 'content' => 'Oi']);

        return ['nutri_message', $outra->id];
    }],
    'não existe' => [fn ($t) => ['meal_plan', 999999]],
]);

it('valida o corpo', function (array $corpo, string $campo) {
    avaliar([...['rateable_type' => 'nutri_message', 'rateable_id' => $this->resposta->id, 'value' => 'up'], ...$corpo])
        ->assertUnprocessable()->assertJsonValidationErrors($campo);
})->with([
    [['rateable_type' => 'conversa'], 'rateable_type'],
    [['value' => 'meh'], 'value'],
    [['comment' => str_repeat('a', 501)], 'comment'],
]);
```

No `DeleteAccountTest`: dataset `'ratings' => ['ratings', 'user_id'],` e, no arranjo, antes do `deleteJson`: `DB::table('ratings')->insert(['user_id' => $user->id, 'rateable_type' => 'meal_plan', 'rateable_id' => 1, 'value' => 'up', 'created_at' => now(), 'updated_at' => now()]);`

Run: `docker compose run --rm api php artisan test tests/Feature/Validation/RatingTest.php`
Expected: FAIL — rota inexistente.

- [ ] **Step 2: Implementar**

`database/migrations/2026_10_04_000100_create_ratings_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RN40: uma avaliação por pessoa por item; avaliar de novo substitui.
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('rateable_type', 20);
            $table->unsignedBigInteger('rateable_id');
            $table->string('value', 4);
            $table->string('comment', 500)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'rateable_type', 'rateable_id']);
            $table->index(['rateable_type', 'rateable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
```

`app/Models/Rating.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Avaliação 👍/👎 de uma resposta do Nutri ou de um plano (RN40). */
class Rating extends Model
{
    protected $fillable = ['user_id', 'rateable_type', 'rateable_id', 'value', 'comment'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{value: string, comment: string|null}
     */
    public function toPublic(): array
    {
        return ['value' => $this->value, 'comment' => $this->comment];
    }
}
```

`app/Providers/AppServiceProvider.php` — no `boot()`:
```php
        // RN40: nomes curtos e estáveis para o que se avalia (sem enforce: push_subscriptions guarda a classe do User).
        Relation::morphMap(['nutri_message' => NutriMessage::class, 'meal_plan' => MealPlan::class]);
```
(imports `Illuminate\Database\Eloquent\Relations\Relation`, `App\Models\NutriMessage`, `App\Models\MealPlan`.)

`NutriMessage` e `MealPlan` — acrescentar:
```php
    /**
     * @return MorphOne<Rating, $this>
     */
    public function rating(): MorphOne
    {
        return $this->morphOne(Rating::class, 'rateable');
    }
```
(import `Illuminate\Database\Eloquent\Relations\MorphOne`.)

`app/Services/Validation/RatingService.php`:
```php
<?php

namespace App\Services\Validation;

use App\Models\MealPlan;
use App\Models\NutriMessage;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** RN40/RN43 — só respostas do Nutri e planos da própria pessoa; o resto é 404. */
final class RatingService
{
    public function rate(User $user, string $type, int $id, string $value, ?string $comment): Rating
    {
        $this->assertOwned($user, $type, $id);

        return Rating::updateOrCreate(
            ['user_id' => $user->id, 'rateable_type' => $type, 'rateable_id' => $id],
            ['value' => $value, 'comment' => $value === 'down' ? $comment : null],
        );
    }

    public function remove(User $user, string $type, int $id): void
    {
        Rating::where(['user_id' => $user->id, 'rateable_type' => $type, 'rateable_id' => $id])->delete();
    }

    private function assertOwned(User $user, string $type, int $id): void
    {
        $existe = match ($type) {
            'nutri_message' => NutriMessage::whereKey($id)->where('role', 'assistant')
                ->whereHas('conversation', fn ($q) => $q->where('user_id', $user->id))->exists(),
            'meal_plan' => MealPlan::whereKey($id)->where('user_id', $user->id)->exists(),
            default => false,
        };
        if (! $existe) {
            throw new ModelNotFoundException;
        }
    }
}
```
(Comentário só com 👎, como a tela: 👍 limpa o comentário. Se o `ModelNotFoundException` não virar o 404 no formato único do `ApiExceptionRenderer`, lance a mesma exceção que as Policies usam — `abort(404)` — e registre.)

`app/Http/Requests/RatingRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** `PUT/DELETE /ratings` (spec 07 §5). */
class RatingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $alvo = [
            'rateable_type' => ['required', 'in:nutri_message,meal_plan'],
            'rateable_id' => ['required', 'integer'],
        ];

        return $this->isMethod('DELETE') ? $alvo : $alvo + [
            'value' => ['required', 'in:up,down'],
            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }
}
```

`app/Http/Controllers/Api/V1/RatingController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RatingRequest;
use App\Models\User;
use App\Services\Validation\RatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class RatingController extends Controller
{
    public function update(RatingRequest $request, RatingService $ratings): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $rating = $ratings->rate(
            $user,
            $request->string('rateable_type')->toString(),
            $request->integer('rateable_id'),
            $request->string('value')->toString(),
            $request->input('comment'),
        );

        return response()->json(['data' => [
            'rateable_type' => $rating->rateable_type,
            'rateable_id' => $rating->rateable_id,
            'value' => $rating->value,
            'comment' => $rating->comment,
        ]]);
    }

    public function destroy(RatingRequest $request, RatingService $ratings): Response
    {
        /** @var User $user */
        $user = $request->user();
        $ratings->remove($user, $request->string('rateable_type')->toString(), $request->integer('rateable_id'));

        return response()->noContent();
    }
}
```

`routes/api.php` — `use App\Http\Controllers\Api\V1\RatingController;` e, no grupo `onboarded`:
```php
            Route::put('ratings', [RatingController::class, 'update']);
            Route::delete('ratings', [RatingController::class, 'destroy']);
```

Resources:
- `NutriMessageResource`: `'rating' => $m->relationLoaded('rating') ? $m->rating?->toPublic() : null,`
- `PlanResource`: `'rating' => $this->resource->relationLoaded('rating') ? $this->resource->rating?->toPublic() : null,` (ajuste ao estilo do arquivo) e acrescentar `'rating'` a `PlanResource::RELATIONS`.
- `MessageController::index`: `$conversation->messages()->with('rating')->orderByDesc('id')->cursorPaginate(30)`.

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm api bash -c "php artisan test tests/Feature/Validation tests/Feature/Auth/DeleteAccountTest.php tests/Feature/Nutri tests/Feature/Plans && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(validacao): avaliações 👍/👎 de respostas do Nutri e de planos (RN40, CA01–CA03)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Questionário de usabilidade — `SusScore`, convite, resposta e "Agora não"

**Files:**
- Create: `config/validacao.php`, `database/migrations/2026_10_04_000200_create_usability_responses_table.php`, `app/Models/UsabilityResponse.php`, `app/Services/Validation/SusScore.php`, `app/Services/Validation/UsabilityService.php`, `app/Http/Controllers/Api/V1/UsabilityController.php`, `app/Http/Requests/UsabilityResponseRequest.php`
- Modify: `app/Enums/ErrorCode.php` (`AlreadyResponded`), `routes/api.php`, `.env.example`, `tests/Feature/Auth/DeleteAccountTest.php`
- Test: `tests/Unit/Validation/SusScoreTest.php`, `tests/Feature/Validation/UsabilityTest.php`

**Interfaces:**
- Produces: `SusScore::of(list<int> $answers): float`; `UsabilityService::status(User): array{round: string, responded: bool, invite: bool}`, `respond(User, array $data): void` (409 se já respondeu), `dismiss(User): void`; `config('validacao.rodada')`, `config('validacao.inicio')`.

- [ ] **Step 1: Testes que devem falhar**

`tests/Unit/Validation/SusScoreTest.php`:
```php
<?php

use App\Services\Validation\SusScore;

it('calcula o SUS: exemplo do CA05, neutro e extremos', function (array $respostas, float $sus) {
    expect(SusScore::of($respostas))->toBe($sus);
})->with([
    'CA05' => [[4, 2, 5, 1, 4, 2, 5, 1, 4, 2], 85.0],
    'tudo 3' => [[3, 3, 3, 3, 3, 3, 3, 3, 3, 3], 50.0],
    'melhor' => [[5, 1, 5, 1, 5, 1, 5, 1, 5, 1], 100.0],
    'pior' => [[1, 5, 1, 5, 1, 5, 1, 5, 1, 5], 0.0],
]);
```

`tests/Feature/Validation/UsabilityTest.php`:
```php
<?php

use App\Models\UsabilityResponse;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['validacao.rodada' => '2026-1', 'validacao.inicio' => '2026-09-01']);
    $this->travelTo(CarbonImmutable::parse('2026-10-04 10:00', 'America/Sao_Paulo'));
});

$resposta = ['sus_answers' => [4, 2, 5, 1, 4, 2, 5, 1, 4, 2], 'usefulness' => 4, 'liked' => 'Os horários batem com o meu treino.', 'disliked' => null];

it('convida quem concluiu o onboarding há 7 dias (CA04)', function () {
    $user = login(User::factory()->onboarded()->create());
    $user->profile->update(['onboarding_completed_at' => now()->subDays(8)]);

    $this->getJson('/api/v1/usability-responses/status')->assertOk()->assertExactJson(['data' => ['round' => '2026-1', 'responded' => false, 'invite' => true]]);
});

it('não convida quem começou ontem e marcou poucas refeições; convida com 10 refeições feitas', function () {
    seedCatalog();
    $user = login(User::factory()->onboarded()->create());
    $user->profile->update(['onboarding_completed_at' => now()->subDay()]);
    expect($this->getJson('/api/v1/usability-responses/status')->json('data.invite'))->toBeFalse();

    $plano = planoPronto($user);
    foreach (range(1, 10) as $i) {
        $user->dayMeals()->create(['date' => now()->subDays($i)->toDateString(), 'meal_plan_id' => $plano->id, 'slot' => 'cafe', 'name' => 'Café', 'time' => '07:00', 'position' => 1, 'done_at' => now()]);
    }

    expect($this->getJson('/api/v1/usability-responses/status')->json('data.invite'))->toBeTrue();
});

it('responde (201), grava o SUS e não aceita de novo na rodada (CA05, CA06)', function () use ($resposta) {
    $user = login(User::factory()->onboarded()->create());

    $this->postJson('/api/v1/usability-responses', $resposta)->assertCreated()->assertExactJson(['data' => ['round' => '2026-1', 'responded' => true]]);
    $this->postJson('/api/v1/usability-responses', $resposta)->assertStatus(409)->assertJsonPath('code', 'ALREADY_RESPONDED');

    expect(UsabilityResponse::sole()->only(['round', 'sus_score', 'usefulness']))->toEqual(['round' => '2026-1', 'sus_score' => 85.0, 'usefulness' => 4])
        ->and($this->getJson('/api/v1/usability-responses/status')->json('data'))->toBe(['round' => '2026-1', 'responded' => true, 'invite' => false]);
});

it('rodada nova: pode responder de novo', function () use ($resposta) {
    login(User::factory()->onboarded()->create());
    $this->postJson('/api/v1/usability-responses', $resposta)->assertCreated();
    config(['validacao.rodada' => '2026-2']);

    $this->postJson('/api/v1/usability-responses', $resposta)->assertCreated();
    expect(UsabilityResponse::count())->toBe(2);
});

it('"Agora não" some com o convite nesta rodada; rodada aberta depois convida de novo', function () {
    $user = login(User::factory()->onboarded()->create());
    $user->profile->update(['onboarding_completed_at' => now()->subDays(8)]);

    $this->postJson('/api/v1/usability-responses/dismiss')->assertNoContent();
    expect($this->getJson('/api/v1/usability-responses/status')->json('data.invite'))->toBeFalse();

    config(['validacao.rodada' => '2026-2', 'validacao.inicio' => '2026-10-05']);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 10:00', 'America/Sao_Paulo'));
    expect($this->getJson('/api/v1/usability-responses/status')->json('data.invite'))->toBeTrue();
});

it('valida as respostas', function (array $mudanca, string $campo) use ($resposta) {
    login(User::factory()->onboarded()->create());

    $this->postJson('/api/v1/usability-responses', [...$resposta, ...$mudanca])->assertUnprocessable()->assertJsonValidationErrors($campo);
})->with([
    'nove respostas' => [['sus_answers' => [4, 2, 5, 1, 4, 2, 5, 1, 4]], 'sus_answers'],
    'fora da escala' => [['sus_answers' => [6, 2, 5, 1, 4, 2, 5, 1, 4, 2]], 'sus_answers.0'],
    'utilidade 0' => [['usefulness' => 0], 'usefulness'],
    'texto longo' => [['liked' => str_repeat('a', 1001)], 'liked'],
]);

it('dois envios ao mesmo tempo: o segundo vira 409, nunca 500', function () use ($resposta) {
    $user = login(User::factory()->onboarded()->create());
    // O outro envio gravou entre a checagem e o insert: o índice único estoura.
    UsabilityResponse::creating(function () use ($user) {
        UsabilityResponse::flushEventListeners();
        UsabilityResponse::create(['user_id' => $user->id, 'round' => '2026-1', 'sus_answers' => [3, 3, 3, 3, 3, 3, 3, 3, 3, 3], 'sus_score' => 50, 'usefulness' => 3]);
    });

    $this->postJson('/api/v1/usability-responses', $resposta)->assertStatus(409);
    expect(UsabilityResponse::count())->toBe(1);
});
```

No `DeleteAccountTest`: dataset `'usability_responses' => ['usability_responses', 'user_id'],` e no arranjo `DB::table('usability_responses')->insert(['user_id' => $user->id, 'round' => '2026-1', 'sus_answers' => '[3,3,3,3,3,3,3,3,3,3]', 'sus_score' => 50, 'usefulness' => 3, 'created_at' => now()]);`

Run: `docker compose run --rm api php artisan test tests/Unit/Validation tests/Feature/Validation/UsabilityTest.php`
Expected: FAIL.

- [ ] **Step 2: Implementar**

`config/validacao.php`:
```php
<?php

return [
    // Rodada da validação com a comunidade (RN41): uma resposta por pessoa por rodada.
    'rodada' => env('VALIDACAO_RODADA', '2026-1'),
    // Abertura da rodada: "Agora não" dado antes disso não vale para esta rodada.
    'inicio' => env('VALIDACAO_INICIO', '2026-09-01'),
];
```
`.env.example` — `VALIDACAO_RODADA=2026-1` e `VALIDACAO_INICIO=2026-09-01` com um comentário "rodada da validação (spec 07)".

`database/migrations/2026_10_04_000200_create_usability_responses_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usability_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('round', 10);
            $table->json('sus_answers');
            $table->decimal('sus_score', 5, 1);
            $table->unsignedTinyInteger('usefulness');
            $table->string('liked', 1000)->nullable();
            $table->string('disliked', 1000)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'round']); // RN41: uma resposta por rodada
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usability_responses');
    }
};
```

`app/Models/UsabilityResponse.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Resposta ao questionário de usabilidade (RN41). */
class UsabilityResponse extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'round', 'sus_answers', 'sus_score', 'usefulness', 'liked', 'disliked'];

    protected function casts(): array
    {
        return ['sus_answers' => 'array', 'sus_score' => 'float', 'usefulness' => 'integer'];
    }
}
```

`app/Services/Validation/SusScore.php`:
```php
<?php

namespace App\Services\Validation;

/** SUS: ((Σ ímpares − 5) + (25 − Σ pares)) × 2,5 — 0 a 100 (RN41). Puro. */
final class SusScore
{
    /**
     * @param  list<int>  $answers  10 respostas de 1 a 5, na ordem das afirmações
     */
    public static function of(array $answers): float
    {
        $impares = $answers[0] + $answers[2] + $answers[4] + $answers[6] + $answers[8];
        $pares = $answers[1] + $answers[3] + $answers[5] + $answers[7] + $answers[9];

        return (($impares - 5) + (25 - $pares)) * 2.5;
    }
}
```

`app/Enums/ErrorCode.php` — `case AlreadyResponded = 'ALREADY_RESPONDED';` com status 409 e mensagem "Você já respondeu nesta rodada. Obrigado!" (siga o padrão do enum para status e mensagem).

`app/Services/Validation/UsabilityService.php`:
```php
<?php

namespace App\Services\Validation;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use App\Models\UsabilityResponse;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/** RN41 — convite, resposta por rodada e "Agora não". */
final class UsabilityService
{
    /**
     * @return array{round: string, responded: bool, invite: bool}
     */
    public function status(User $user): array
    {
        $rodada = (string) config('validacao.rodada');
        $respondeu = UsabilityResponse::where(['user_id' => $user->id, 'round' => $rodada])->exists();

        return ['round' => $rodada, 'responded' => $respondeu, 'invite' => ! $respondeu && ! $this->dismissed($user) && $this->eligible($user)];
    }

    /**
     * @param  array{sus_answers: list<int>, usefulness: int, liked?: string|null, disliked?: string|null}  $data
     */
    public function respond(User $user, array $data): void
    {
        $rodada = (string) config('validacao.rodada');
        if (UsabilityResponse::where(['user_id' => $user->id, 'round' => $rodada])->exists()) {
            throw new DomainException(ErrorCode::AlreadyResponded);
        }
        try {
            UsabilityResponse::create([
                'user_id' => $user->id,
                'round' => $rodada,
                'sus_answers' => array_map('intval', $data['sus_answers']),
                'sus_score' => SusScore::of(array_map('intval', $data['sus_answers'])),
                'usefulness' => $data['usefulness'],
                'liked' => $data['liked'] ?? null,
                'disliked' => $data['disliked'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new DomainException(ErrorCode::AlreadyResponded); // o outro envio chegou primeiro
        }
    }

    public function dismiss(User $user): void
    {
        $user->settings()->update(['usability_invite_dismissed_at' => now()]);
    }

    private function dismissed(User $user): bool
    {
        $quando = $user->settings?->usability_invite_dismissed_at;

        return $quando !== null && $quando->greaterThanOrEqualTo(CarbonImmutable::parse((string) config('validacao.inicio')));
    }

    /** Onboarding concluído há ≥ 7 dias OU ≥ 10 refeições marcadas. */
    private function eligible(User $user): bool
    {
        $concluido = $user->profile?->onboarding_completed_at;
        if ($concluido !== null && $concluido->lessThanOrEqualTo(now()->subDays(7))) {
            return true;
        }

        return $user->dayMeals()->whereNotNull('done_at')->count() >= 10;
    }
}
```
(Confira o namespace e o construtor de `DomainException` usados pelo `PlanService`; ajuste o import.)

`app/Http/Requests/UsabilityResponseRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** `POST /usability-responses` (spec 07 §5). */
class UsabilityResponseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sus_answers' => ['required', 'array', 'size:10'],
            'sus_answers.*' => ['required', 'integer', 'between:1,5'],
            'usefulness' => ['required', 'integer', 'between:1,5'],
            'liked' => ['nullable', 'string', 'max:1000'],
            'disliked' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
```

`app/Http/Controllers/Api/V1/UsabilityController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UsabilityResponseRequest;
use App\Models\User;
use App\Services\Validation\UsabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UsabilityController extends Controller
{
    public function status(Request $request, UsabilityService $usability): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $usability->status($user)]);
    }

    public function store(UsabilityResponseRequest $request, UsabilityService $usability): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{sus_answers: list<int>, usefulness: int, liked?: string|null, disliked?: string|null} $dados */
        $dados = $request->validated();
        $usability->respond($user, $dados);

        return response()->json(['data' => ['round' => (string) config('validacao.rodada'), 'responded' => true]], 201);
    }

    public function dismiss(Request $request, UsabilityService $usability): Response
    {
        /** @var User $user */
        $user = $request->user();
        $usability->dismiss($user);

        return response()->noContent();
    }
}
```

`routes/api.php` — `use App\Http\Controllers\Api\V1\UsabilityController;` e, no grupo `onboarded`:
```php
            Route::get('usability-responses/status', [UsabilityController::class, 'status']);
            Route::post('usability-responses', [UsabilityController::class, 'store']);
            Route::post('usability-responses/dismiss', [UsabilityController::class, 'dismiss']);
```
(Se `usability_invite_dismissed_at` não tiver cast `datetime` no `UserSetting`, ele já tem — confira.)

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm api bash -c "php artisan test tests/Unit/Validation tests/Feature/Validation tests/Feature/Auth/DeleteAccountTest.php && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(validacao): questionário de usabilidade com SUS, convite e rodada (RN41, CA04–CA06)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Exportação anonimizada — `ValidationExporter` e `validacao:exportar`

**Files:**
- Create: `app/Services/Validation/ValidationExporter.php`, `app/Console/Commands/ExportValidationData.php`
- Test: `tests/Feature/Validation/ExportCommandTest.php`

**Interfaces:**
- Consumes: `ratings`, `usability_responses`, `day_meals`, `day_meal_items`, `nutri_messages`, `nutri_conversations`, `meal_plans`, `weigh_ins`, `ai_requests`.
- Produces: `ValidationExporter::hash(int $userId): string`; `export(string $rodada, ?CarbonImmutable $de, ?CarbonImmutable $ate, string $dir): array{participantes: int, sus_medio: ?float, sus_desvio: ?float, up_nutri: ?float, up_plano: ?float, utilidade_media: ?float, dias_ativos_medios: ?float}`; comando `validacao:exportar {--rodada=} {--de=} {--ate=} {--dir=}`.

- [ ] **Step 1: Teste que deve falhar**

`tests/Feature/Validation/ExportCommandTest.php`:
```php
<?php

use App\Models\User;
use App\Services\Validation\ValidationExporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seedCatalog();
    config(['validacao.rodada' => '2026-1']);
    $this->travelTo(CarbonImmutable::parse('2026-10-04 10:00', 'America/Sao_Paulo'));
    $this->dir = storage_path('framework/testing/validacao-'.uniqid());
});

afterEach(fn () => \Illuminate\Support\Facades\File::deleteDirectory($this->dir));

/** Lê um CSV do exportador: tira o BOM e separa por ";" respeitando aspas. */
function lerCsv(string $caminho): array
{
    $conteudo = file_get_contents($caminho);
    expect(str_starts_with($conteudo, "\u{FEFF}"))->toBeTrue();
    $linhas = [];
    $h = fopen('php://memory', 'r+');
    fwrite($h, substr($conteudo, 3));
    rewind($h);
    while (($linha = fgetcsv($h, null, ';', '"', '')) !== false) {
        $linhas[] = $linha;
    }

    return $linhas;
}

it('gera os 4 CSVs anonimizados e o mesmo hash em todos (CA07)', function () {
    $camila = User::factory()->onboarded()->create(['name' => 'Camila Réus', 'email' => 'camila@exemplo.com']);
    $plano = planoPronto($camila);
    $conversa = $camila->conversations()->create(['title' => 'Segredo da Camila']);
    $conversa->messages()->create(['role' => 'user', 'content' => 'Texto privado da conversa']);
    $resposta = $conversa->messages()->create(['role' => 'assistant', 'content' => 'Resposta privada']);
    DB::table('ratings')->insert([
        ['user_id' => $camila->id, 'rateable_type' => 'nutri_message', 'rateable_id' => $resposta->id, 'value' => 'up', 'comment' => null, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $camila->id, 'rateable_type' => 'meal_plan', 'rateable_id' => $plano->id, 'value' => 'down', 'comment' => "Faltou; \"arroz\"\nno jantar", 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('usability_responses')->insert(['user_id' => $camila->id, 'round' => '2026-1', 'sus_answers' => '[4,2,5,1,4,2,5,1,4,2]', 'sus_score' => 85, 'usefulness' => 4, 'liked' => 'Horários', 'disliked' => null, 'created_at' => now()]);
    $camila->weighIns()->create(['date' => '2026-09-20', 'weight_kg' => 58.0]);
    $camila->weighIns()->create(['date' => '2026-10-03', 'weight_kg' => 58.6]);

    $this->artisan('validacao:exportar', ['--dir' => $this->dir])
        ->expectsOutputToContain('Participantes: 1')
        ->expectsOutputToContain('SUS médio: 85,0')
        ->assertSuccessful();

    $hash = app(ValidationExporter::class)->hash($camila->id);
    $todos = '';
    foreach (['usabilidade', 'avaliacoes', 'uso', 'ia'] as $arquivo) {
        expect(file_exists("{$this->dir}/{$arquivo}.csv"))->toBeTrue();
        $todos .= file_get_contents("{$this->dir}/{$arquivo}.csv");
    }
    expect($todos)->not->toContain('Camila')->not->toContain('camila@exemplo.com')->not->toContain('Texto privado')->not->toContain('Resposta privada')->not->toContain('Segredo da');

    $usabilidade = lerCsv("{$this->dir}/usabilidade.csv");
    expect($usabilidade[0])->toBe(['usuario_hash', 'rodada', 'q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9', 'q10', 'sus', 'utilidade', 'ajudou', 'atrapalhou', 'respondido_em'])
        ->and($usabilidade[1][0])->toBe($hash)->and($usabilidade[1][12])->toBe('85,0');

    $avaliacoes = lerCsv("{$this->dir}/avaliacoes.csv");
    expect($avaliacoes[0])->toBe(['usuario_hash', 'tipo', 'valor', 'comentario', 'criado_em'])
        ->and(collect($avaliacoes)->skip(1)->pluck(0)->unique()->all())->toBe([$hash])
        ->and(collect($avaliacoes)->firstWhere(1, 'plano'))->toBe([$hash, 'plano', 'down', "Faltou; \"arroz\"\nno jantar", now()->toDateTimeString()]);

    $uso = lerCsv("{$this->dir}/uso.csv");
    expect($uso[0])->toBe(['usuario_hash', 'objetivo', 'dias_desde_cadastro', 'dias_com_refeicao_marcada', 'refeicoes_feitas', 'dias_completos', 'trocas_manuais', 'trocas_nutri', 'perguntas_nutri', 'conversas', 'planos_gerados', 'planos_falhos', 'pesagens', 'variacao_peso_kg'])
        ->and($uso[1][0])->toBe($hash)->and($uso[1][8])->toBe('1')->and($uso[1][12])->toBe('2')->and($uso[1][13])->toBe('0,6');

    expect(lerCsv("{$this->dir}/ia.csv")[0])->toBe(['dia', 'proposito', 'chamadas', 'falhas', 'tokens_entrada', 'tokens_saida', 'latencia_media_ms']);
});

it('sem nenhum dado: cabeçalhos e "Participantes: 0", sem erro', function () {
    $this->artisan('validacao:exportar', ['--dir' => $this->dir])->expectsOutputToContain('Participantes: 0')->assertSuccessful();

    expect(lerCsv("{$this->dir}/usabilidade.csv"))->toHaveCount(1);
});

it('o hash é estável e não é o id', function () {
    $exporter = app(ValidationExporter::class);

    expect($exporter->hash(7))->toBe($exporter->hash(7))->toHaveLength(12)->not->toBe($exporter->hash(8));
});

it('filtra o uso por data (--de/--ate)', function () {
    $user = User::factory()->onboarded()->create();
    $user->weighIns()->create(['date' => '2026-08-01', 'weight_kg' => 60.0]);

    $this->artisan('validacao:exportar', ['--dir' => $this->dir, '--de' => '2026-09-01', '--ate' => '2026-10-04'])->assertSuccessful();

    expect(lerCsv("{$this->dir}/uso.csv"))->toHaveCount(1); // ninguém ativo no período
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Validation/ExportCommandTest.php`
Expected: FAIL — comando inexistente.

- [ ] **Step 2: Implementar**

`app/Services/Validation/ValidationExporter.php`:
```php
<?php

namespace App\Services\Validation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * RN42 — CSVs anonimizados para o relatório da atividade extensionista: sem nome, e-mail,
 * conteúdo de conversa, restrições nem data de nascimento. UTF-8 com BOM e ";" (abre no Excel pt-BR).
 */
final class ValidationExporter
{
    public function hash(int $userId): string
    {
        return substr(hash('sha256', $userId.config('app.key')), 0, 12);
    }

    /**
     * @return array{participantes: int, sus_medio: float|null, sus_desvio: float|null, up_nutri: float|null, up_plano: float|null, utilidade_media: float|null, dias_ativos_medios: float|null}
     */
    public function export(string $rodada, ?CarbonImmutable $de, ?CarbonImmutable $ate, string $dir): array
    {
        File::ensureDirectoryExists($dir);
        $noPeriodo = function ($query, string $coluna) use ($de, $ate) {
            return $query->when($de, fn ($q) => $q->where($coluna, '>=', $de->startOfDay()))
                ->when($ate, fn ($q) => $q->where($coluna, '<=', $ate->endOfDay()));
        };

        // usabilidade.csv
        $respostas = DB::table('usability_responses')->where('round', $rodada)->orderBy('id')->get();
        $this->write("{$dir}/usabilidade.csv", ['usuario_hash', 'rodada', 'q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9', 'q10', 'sus', 'utilidade', 'ajudou', 'atrapalhou', 'respondido_em'],
            $respostas->map(fn ($r) => [$this->hash($r->user_id), $r->round, ...json_decode($r->sus_answers, true), $this->decimal((float) $r->sus_score), $r->usefulness, $r->liked, $r->disliked, $r->created_at]));

        // avaliacoes.csv
        $avaliacoes = $noPeriodo(DB::table('ratings'), 'created_at')->orderBy('id')->get();
        $this->write("{$dir}/avaliacoes.csv", ['usuario_hash', 'tipo', 'valor', 'comentario', 'criado_em'],
            $avaliacoes->map(fn ($a) => [$this->hash($a->user_id), $a->rateable_type === 'meal_plan' ? 'plano' : 'resposta_nutri', $a->value, $a->comment, $a->created_at]));

        // uso.csv — ativos no período: refeição marcada, pesagem ou pergunta ao Nutri
        $ativos = collect()
            ->merge($noPeriodo(DB::table('day_meals')->whereNotNull('done_at'), 'date')->pluck('user_id'))
            ->merge($noPeriodo(DB::table('weigh_ins'), 'date')->pluck('user_id'))
            ->merge($noPeriodo(DB::table('nutri_messages')->join('nutri_conversations', 'nutri_conversations.id', '=', 'nutri_messages.conversation_id')->where('nutri_messages.role', 'user'), 'nutri_messages.created_at')->pluck('nutri_conversations.user_id'))
            ->unique()->sort()->values();
        $diasAtivos = [];
        $linhasUso = $ativos->map(function (int $userId) use ($noPeriodo, &$diasAtivos) {
            $dias = $noPeriodo(DB::table('day_meals')->where('user_id', $userId), 'date')
                ->groupBy('date')->selectRaw('date, count(*) as total, sum(done_at is not null) as done')->get();
            $comMarcada = $dias->where('done', '>', 0)->count();
            $diasAtivos[] = $comMarcada;
            $itens = fn (string $fonte) => $noPeriodo(DB::table('day_meal_items')->join('day_meals', 'day_meals.id', '=', 'day_meal_items.day_meal_id')
                ->where('day_meals.user_id', $userId)->where('day_meal_items.source', $fonte), 'day_meals.date')->count();
            $pesos = $noPeriodo(DB::table('weigh_ins')->where('user_id', $userId), 'date')->orderBy('date')->pluck('weight_kg');
            $cadastro = DB::table('users')->where('id', $userId)->value('created_at');

            return [
                $this->hash($userId),
                DB::table('profiles')->where('user_id', $userId)->value('goal'),
                (int) CarbonImmutable::parse($cadastro)->diffInDays(now()),
                $comMarcada,
                (int) $dias->sum('done'),
                $dias->filter(fn ($d) => (int) $d->done === (int) $d->total)->count(),
                $itens('manual'),
                $itens('nutri'),
                $noPeriodo(DB::table('nutri_messages')->join('nutri_conversations', 'nutri_conversations.id', '=', 'nutri_messages.conversation_id')
                    ->where('nutri_conversations.user_id', $userId)->where('nutri_messages.role', 'user'), 'nutri_messages.created_at')->count(),
                DB::table('nutri_conversations')->where('user_id', $userId)->count(),
                DB::table('meal_plans')->where('user_id', $userId)->where('status', 'ready')->count(),
                DB::table('meal_plans')->where('user_id', $userId)->where('status', 'failed')->count(),
                $pesos->count(),
                $pesos->count() >= 2 ? $this->decimal(round((float) $pesos->last() - (float) $pesos->first(), 1)) : null,
            ];
        });
        $this->write("{$dir}/uso.csv", ['usuario_hash', 'objetivo', 'dias_desde_cadastro', 'dias_com_refeicao_marcada', 'refeicoes_feitas', 'dias_completos', 'trocas_manuais', 'trocas_nutri', 'perguntas_nutri', 'conversas', 'planos_gerados', 'planos_falhos', 'pesagens', 'variacao_peso_kg'], $linhasUso);

        // ia.csv — dia × propósito
        $ia = $noPeriodo(DB::table('ai_requests'), 'created_at')
            ->selectRaw("date(created_at) as dia, purpose, count(*) as chamadas, sum(status <> 'ok') as falhas, coalesce(sum(prompt_tokens), 0) as entrada, coalesce(sum(completion_tokens), 0) as saida, round(avg(duration_ms)) as latencia")
            ->groupByRaw('date(created_at), purpose')->orderBy('dia')->get();
        $this->write("{$dir}/ia.csv", ['dia', 'proposito', 'chamadas', 'falhas', 'tokens_entrada', 'tokens_saida', 'latencia_media_ms'],
            $ia->map(fn ($l) => [$l->dia, $l->purpose, $l->chamadas, $l->falhas, $l->entrada, $l->saida, $l->latencia]));

        $sus = $respostas->pluck('sus_score')->map(fn ($s) => (float) $s);
        $pct = fn ($lista) => $lista->isEmpty() ? null : round($lista->where('value', 'up')->count() / $lista->count() * 100, 1);

        return [
            'participantes' => $ativos->merge($respostas->pluck('user_id'))->unique()->count(),
            'sus_medio' => $sus->isEmpty() ? null : round($sus->avg(), 1),
            'sus_desvio' => $sus->count() < 2 ? null : round(sqrt($sus->map(fn ($s) => ($s - $sus->avg()) ** 2)->sum() / ($sus->count() - 1)), 1),
            'up_nutri' => $pct($avaliacoes->where('rateable_type', 'nutri_message')),
            'up_plano' => $pct($avaliacoes->where('rateable_type', 'meal_plan')),
            'utilidade_media' => $respostas->isEmpty() ? null : round($respostas->avg('usefulness'), 1),
            'dias_ativos_medios' => $diasAtivos === [] ? null : round(array_sum($diasAtivos) / count($diasAtivos), 1),
        ];
    }

    /**
     * @param  list<string>  $cabecalho
     * @param  iterable<array<int, mixed>>  $linhas
     */
    private function write(string $caminho, array $cabecalho, iterable $linhas): void
    {
        $h = fopen($caminho, 'w');
        fwrite($h, "\u{FEFF}");
        fputcsv($h, $cabecalho, ';', '"', '');
        foreach ($linhas as $linha) {
            fputcsv($h, array_map(fn ($v) => $v === null ? '' : (string) $v, $linha), ';', '"', '');
        }
        fclose($h);
    }

    private function decimal(float $n): string
    {
        return number_format($n, 1, ',', '');
    }
}
```
(`status` de plano: confira os valores de `PlanStatus` (`ready`/`failed`) e ajuste. `fputcsv` com escape `''` (PHP 8.3) segue o RFC: aspas dobradas e quebra de linha dentro de aspas.)

`app/Console/Commands/ExportValidationData.php`:
```php
<?php

namespace App\Console\Commands;

use App\Services\Validation\ValidationExporter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** RF33/RN42 — `php artisan validacao:exportar --rodada=2026-1`. */
class ExportValidationData extends Command
{
    protected $signature = 'validacao:exportar {--rodada=} {--de=} {--ate=} {--dir=}';

    protected $description = 'Exporta os dados anonimizados da validação com a comunidade (CSVs)';

    public function handle(ValidationExporter $exporter): int
    {
        $rodada = (string) ($this->option('rodada') ?: config('validacao.rodada'));
        $dir = (string) ($this->option('dir') ?: storage_path("app/validacao/{$rodada}"));
        $data = fn (?string $valor) => $valor ? CarbonImmutable::parse($valor) : null;
        $r = $exporter->export($rodada, $data($this->option('de')), $data($this->option('ate')), $dir);
        $n = fn (?float $v, string $sufixo = '') => $v === null ? '—' : number_format($v, 1, ',', '.').$sufixo;

        $this->info("Arquivos em {$dir}");
        $this->line("Participantes: {$r['participantes']}");
        $this->line('SUS médio: '.$n($r['sus_medio']).' (desvio '.$n($r['sus_desvio']).')');
        $this->line('👍 no Nutri: '.$n($r['up_nutri'], '%').'; no plano: '.$n($r['up_plano'], '%'));
        $this->line('Utilidade média: '.$n($r['utilidade_media']));
        $this->line('Dias ativos em média: '.$n($r['dias_ativos_medios']));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm api bash -c "php artisan test tests/Feature/Validation && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(validacao): exportação anonimizada validacao:exportar (RN42, CA07)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Termo, contas do E2E-12 e roteiro da rodada

**Files:**
- Modify: `config/prato.php` (`terms_version` ⇒ `2026-10`), `tests/Pest.php` (`registerPayload`), `database/seeders/E2ESeeder.php`, `tests/Feature/Seeders/E2ESeederTest.php`, `README.md`

**Interfaces:**
- Produces: contas `avaliar-{chromium,webkit}@e2e.pratoforte.test` (onboarding concluído há 8 dias, plano pronto); `terms_version` `2026-10`.

- [ ] **Step 1: Teste que deve falhar**

Em `tests/Feature/Seeders/E2ESeederTest.php`:
```php
it('cria as contas do E2E-12: convite do questionário já elegível', function () {
    $this->seed(E2ESeeder::class);

    foreach (['chromium', 'webkit'] as $navegador) {
        $user = User::where('email', "avaliar-{$navegador}@e2e.pratoforte.test")->sole();
        expect($user->activePlan()->exists())->toBeTrue()
            ->and($user->profile->onboarding_completed_at->lessThanOrEqualTo(now()->subDays(7)))->toBeTrue();
    }
});
```
e em `tests/Feature/Auth/RegisterTest.php` (ou onde o termo é testado) acrescentar:
```php
it('só aceita a versão vigente do termo (2026-10)', function () {
    $this->postJson('/api/v1/register', registerPayload(['terms_version' => '2026-09']))->assertUnprocessable()->assertJsonValidationErrors('terms_version');
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Seeders tests/Feature/Auth/RegisterTest.php`
Expected: FAIL.

- [ ] **Step 2: Implementar**

- `config/prato.php`: `'terms_version' => '2026-10',` (o termo ganha a frase dos comentários — Plano 08B).
- `tests/Pest.php` → `registerPayload`: `'terms_version' => '2026-10',`.
- `E2ESeeder`, dentro do `foreach` de navegador:
```php
            // 08: validação — onboarding há 8 dias, o convite do questionário aparece (E2E-12).
            $avaliar = User::factory()->onboarded()->create(['name' => 'Vera Vaz', 'email' => "avaliar-{$navegador}@e2e.pratoforte.test"]);
            $avaliar->profile->update(['onboarding_completed_at' => now()->subDays(8)]);
            $planos->requestGeneration($avaliar);
```
- `README.md` — seção:
```markdown
## Rodada de validação (spec 07)

1. Abrir: defina `VALIDACAO_RODADA` (ex.: `2026-1`) e `VALIDACAO_INICIO` (data de abertura) no `.env`. O convite aparece em Hoje para quem concluiu o onboarding há 7 dias ou marcou 10 refeições.
2. Durante: as respostas ficam em `usability_responses` (uma por pessoa por rodada); 👍/👎 em `ratings`.
3. Fechar e exportar: `php artisan validacao:exportar --rodada=2026-1` gera `storage/app/validacao/2026-1/{usabilidade,avaliacoes,uso,ia}.csv` (anônimos, `;`, UTF-8 com BOM) e imprime o resumo para o relatório.
4. Próxima rodada: troque `VALIDACAO_RODADA` e `VALIDACAO_INICIO`.
```

- [ ] **Step 3: Suíte, lint e commit**

Run: `docker compose run --rm api bash -c "php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(validacao): termo 2026-10, contas do E2E-12 e roteiro da rodada

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
