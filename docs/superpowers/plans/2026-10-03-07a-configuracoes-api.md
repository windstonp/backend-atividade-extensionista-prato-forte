# Plano 07A — Configurações e notificações: API — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A parte de servidor da spec 06: `GET/PUT /settings`, `POST/DELETE /push-subscriptions`, o canal Web Push (VAPID) e os três avisos agendados — lembrete de refeição (RF27), resumo da semana (RF28) e dicas do Nutri (RF29) — com a regra RN38 (janela acordado, sem duplicata, inscrição 404/410 apagada).

**Architecture:** Pacote `laravel-notification-channels/webpush` (tabela `push_subscriptions`, trait `HasPushSubscriptions`, `WebPushChannel` que já apaga inscrição expirada). Tabela nova `sent_notifications` com índice único (`user_id`, `type`, `reference`) — o `insertOrIgnore` é a trava anti-duplicata. Serviços em `app/Services/Notifications/`: `AwakeWindow` (puro), `NotificationLedger` (trava), `WeeklySummaryBuilder`, `TipSelector`. Três comandos finos (`notifications:meal-reminders`, `notifications:weekly-summary`, `notifications:tips`) que escolhem os usuários em lotes e disparam `MealReminder`, `WeeklySummary`, `NutriTip` (`ShouldQueue`).

**Tech Stack:** Laravel 12, PHP 8.3, MySQL 8, Pest, Larastan 6, Pint; `laravel-notification-channels/webpush` (usa `minishlink/web-push`; o contêiner tem `bcmath`, `openssl`, `curl`, `mbstring`).

**Spec:** `specs/06-configuracoes-notificacoes/spec.md` (§2 RF26–RF29, §3, §5, §6, §7 CA01–CA08, §8), `specs/00-fundacao/regras-de-negocio.md` (RN38, RN39), `specs/00-fundacao/seguranca.md` §11 (payload sem dado sensível).

**Onde rodar:** `/home/alvez/atividade-extensionista/backend`, branch `plano-07a-configuracoes-api` saindo de `plano-06b-evolucao-telas`.

## Decisões deste plano (rulings sobre a spec)

1. **Chaves VAPID** só no `.env` (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`), geradas com `php artisan webpush:vapid`; `.env.example` ganha as chaves vazias com a instrução. Nos testes, o canal é falso (`Notification::fake()`) ou recebe um `WebPush` simulado — nenhuma chave no repo.
2. **Apagar a conta apaga as inscrições**: `push_subscriptions` usa `morphs` sem FK, então `AccountService::delete` chama `$user->pushSubscriptions()->delete()`; `sent_notifications` tem FK com cascade. As duas entram no `DeleteAccountTest`.
3. **Lembrete sem dia materializado**: usa a prévia do plano ativo (`DayMaterializer::build(..., save: false)`), sem gravar o dia pelo cron.
4. **Janela acordado**: `wake_time ≤ hora ≤ sleep_time`, com `sleep_time` depois da meia-noite tratado como no RN12. O lembrete confere a hora do **envio** (refeição − 15 min).
5. **Resumo da semana**: semana ISO (segunda a domingo); `{n}` = dias `completo` (todas as refeições materializadas feitas); média de proteína consumida nos dias com ≥ 1 refeição feita (inteiro); peso = última pesagem da semana − última pesagem antes da semana, só quando as duas existem, com uma casa e sinal ("peso +0,4 kg"). Separador " · " como está na spec.
6. **Dicas**: "últimos 7 dias" = de hoje − 7 a ontem; regra 2 conta refeições **materializadas** não feitas; regra 1 exige ao menos 1 dia com refeição feita; a primeira regra aplicável que não saiu nos últimos 14 dias (coluna `rule` em `sent_notifications`).
7. **Payload**: `{ title, body, url, tag }` em `WebPushMessage` (`data.url`); `tag` `refeicao-{slot}`, `resumo-semana`, `dica`. Sem nome, e-mail ou peso absoluto — só a variação da semana, que a própria pessoa registrou.

## Global Constraints

- Textos: lembrete "{Refeição} às {HH:MM}" / "{resumo}"; resumo "Sua semana no Prato Forte" / "{n} de 7 dias completos · média de {p} g de proteína{ · peso {+/-x} kg}"; dicas exatamente como a tabela do RF29.
- RN38: só com o tipo ligado **e** ≥ 1 inscrição; nada antes do onboarding concluído; nada fora da janela acordado; nunca a mesma notificação duas vezes; inscrição 404/410 apagada; padrão lembrete e resumo ligados, dicas desligadas (já é o default de `user_settings`).
- `GET /settings` não exige onboarding; `PUT` aceita campos parciais; `unit_system` ∈ {`metric`, `imperial`}; `notifications.*` boolean.
- `POST /push-subscriptions`: `endpoint` required|url|max:500|starts_with:https://; `keys.p256dh`, `keys.auth` required|string|max:255; `content_encoding` in:aesgcm,aes128gcm (padrão `aes128gcm`); upsert por endpoint, trocando de dono se preciso; `DELETE` idempotente (204).
- Lotes com `chunkById(200)`; notificações `ShouldQueue`.
- Nenhum segredo no repo; docblocks multi-linha (Larastan).

## Review Focus

1. **Duas execuções do comando no mesmo minuto** (cron atrasado + manual) → um lembrete só (Task 4, trava `insertOrIgnore`).
2. **`sleep_time` depois da meia-noite** (dorme 01:00) e refeição às 23:30 → lembrete às 23:15 sai (Task 3, `AwakeWindow`).
3. **Mesmo navegador, outra conta** → a inscrição passa a ser da conta nova; a antiga não recebe mais (Task 2).
4. **Usuário sem plano ativo** (gerando ou falhou) com lembrete ligado → o comando pula, sem exceção (Task 4).
5. **Semana com dica já enviada na terça** → sexta envia no máximo mais uma; semana com 2 → nenhuma (Task 6, CA05).

---

### Task 1: `GET/PUT /settings`

**Files:**
- Create: `app/Enums/UnitSystem.php`, `app/Http/Controllers/Api/V1/SettingsController.php`, `app/Http/Requests/SettingsRequest.php`, `app/Http/Resources/SettingsResource.php`
- Modify: `routes/api.php`, `.env.example`, `config/services.php` (nada) → `config/webpush.php` (publicado na Task 2; aqui só lemos `config('webpush.vapid.public_key')`)
- Test: `tests/Feature/Settings/SettingsTest.php`

**Interfaces:**
- Produces: `UnitSystem` (`metric`, `imperial`); rotas `GET/PUT /api/v1/settings` (grupo `auth:sanctum`, sem `onboarded`); `SettingsResource` no formato do §5.

- [ ] **Step 1: Branch e teste que deve falhar**

```bash
cd /home/alvez/atividade-extensionista/backend
git switch plano-06b-evolucao-telas && git switch -c plano-07a-configuracoes-api
```

`tests/Feature/Settings/SettingsTest.php`:
```php
<?php

use App\Models\User;

beforeEach(function () {
    config(['webpush.vapid.public_key' => 'BChaveDeTeste']);
    $this->user = login(User::factory()->create()); // onboarding não é exigido
});

it('devolve o padrão: métrico, lembrete e resumo ligados, dicas desligadas', function () {
    $this->getJson('/api/v1/settings')->assertOk()->assertExactJson(['data' => [
        'unit_system' => 'metric',
        'notifications' => ['meal_reminders' => true, 'weekly_summary' => true, 'tips' => false],
        'push' => ['vapid_public_key' => 'BChaveDeTeste', 'subscriptions' => 0],
    ]]);
});

it('PUT parcial muda só o que veio', function () {
    $this->putJson('/api/v1/settings', ['notifications' => ['tips' => true]])->assertOk()
        ->assertJsonPath('data.notifications', ['meal_reminders' => true, 'weekly_summary' => true, 'tips' => true])
        ->assertJsonPath('data.unit_system', 'metric');

    $this->putJson('/api/v1/settings', ['unit_system' => 'imperial'])->assertOk()->assertJsonPath('data.notifications.tips', true);

    expect($this->user->settings->fresh()->unit_system)->toBe('imperial')
        ->and($this->getJson('/api/v1/me')->json('data.settings.unit_system'))->toBe('imperial');
});

it('valida unidade e booleanos', function (array $corpo, string $campo) {
    $this->putJson('/api/v1/settings', $corpo)->assertUnprocessable()->assertJsonValidationErrors($campo);
})->with([
    [['unit_system' => 'stone'], 'unit_system'],
    [['notifications' => ['tips' => 'sim']], 'notifications.tips'],
]);

it('sem sessão: 401', function () {
    forgetServerState();

    $this->getJson('/api/v1/settings')->assertUnauthorized();
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Settings/SettingsTest.php`
Expected: FAIL — rota inexistente.

- [ ] **Step 2: Implementar**

`app/Enums/UnitSystem.php`:
```php
<?php

namespace App\Enums;

/** RN39 — preferência de exibição; a API continua métrica. */
enum UnitSystem: string
{
    case Metric = 'metric';
    case Imperial = 'imperial';
}
```

`app/Http/Requests/SettingsRequest.php`:
```php
<?php

namespace App\Http\Requests;

use App\Enums\UnitSystem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** RF26/RF30 — campos parciais. */
class SettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unit_system' => ['sometimes', Rule::enum(UnitSystem::class)],
            'notifications' => ['sometimes', 'array'],
            'notifications.meal_reminders' => ['sometimes', 'boolean'],
            'notifications.weekly_summary' => ['sometimes', 'boolean'],
            'notifications.tips' => ['sometimes', 'boolean'],
        ];
    }
}
```

`app/Http/Resources/SettingsResource.php`:
```php
<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** `GET/PUT /settings` (spec 06 §5). @mixin User */
class SettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = $this->settings;

        return [
            'unit_system' => $settings->unit_system,
            'notifications' => [
                'meal_reminders' => $settings->notify_meal_reminders,
                'weekly_summary' => $settings->notify_weekly_summary,
                'tips' => $settings->notify_tips,
            ],
            'push' => [
                'vapid_public_key' => config('webpush.vapid.public_key'),
                'subscriptions' => $this->pushSubscriptions()->count(),
            ],
        ];
    }
}
```

`app/Http/Controllers/Api/V1/SettingsController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Http\Resources\SettingsResource;
use App\Models\User;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(Request $request): SettingsResource
    {
        /** @var User $user */
        $user = $request->user();

        return new SettingsResource($user->load('settings'));
    }

    public function update(SettingsRequest $request): SettingsResource
    {
        /** @var User $user */
        $user = $request->user();
        $avisos = $request->validated('notifications', []);
        $mudancas = array_filter([
            'unit_system' => $request->validated('unit_system'),
            'notify_meal_reminders' => $avisos['meal_reminders'] ?? null,
            'notify_weekly_summary' => $avisos['weekly_summary'] ?? null,
            'notify_tips' => $avisos['tips'] ?? null,
        ], fn ($valor) => $valor !== null);
        $user->settings()->update($mudancas);

        return new SettingsResource($user->load('settings'));
    }
}
```

`routes/api.php` — `use App\Http\Controllers\Api\V1\SettingsController;` e, dentro de `auth:sanctum` (fora de `onboarded`):
```php
        Route::get('settings', [SettingsController::class, 'show']);
        Route::put('settings', [SettingsController::class, 'update']);
```

(O `pushSubscriptions()` vem do trait da Task 2. Para esta task passar sozinha, faça a Task 2 Step 2 — instalar o pacote e pôr o trait no `User` — antes de rodar o Step 3; ou rode as Tasks 1 e 2 juntas. Registre a ruling se juntar.)

- [ ] **Step 3: Rodar, lint e commit** (depois da Task 2 Step 2)

Run: `docker compose run --rm api bash -c "php artisan test tests/Feature/Settings && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(configuracoes): GET/PUT /settings (RF26, RF30)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Web Push — pacote, inscrições e conta apagada

**Files:**
- Create: `app/Http/Controllers/Api/V1/PushSubscriptionController.php`, `app/Http/Requests/PushSubscriptionRequest.php`, migration publicada `create_push_subscriptions_table`, `config/webpush.php`
- Modify: `composer.json`/`composer.lock`, `app/Models/User.php` (trait `HasPushSubscriptions`), `app/Services/Account/AccountService.php`, `routes/api.php`, `.env.example`, `tests/Feature/Auth/DeleteAccountTest.php`
- Test: `tests/Feature/Settings/PushSubscriptionTest.php`

**Interfaces:**
- Produces: rotas `POST/DELETE /api/v1/push-subscriptions`; `User::pushSubscriptions()`, `User::updatePushSubscription()`, `User::deletePushSubscription()` (trait); env `VAPID_*`.

- [ ] **Step 1: Teste que deve falhar**

`tests/Feature/Settings/PushSubscriptionTest.php`:
```php
<?php

use App\Models\User;
use NotificationChannels\WebPush\PushSubscription;

beforeEach(fn () => $this->user = login(User::factory()->create()));

function inscricao(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc'): array
{
    return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BPublica', 'auth' => 'segredo'], 'content_encoding' => 'aes128gcm'];
}

it('guarda a inscrição deste navegador (201) e o GET /settings conta', function () {
    $this->postJson('/api/v1/push-subscriptions', inscricao())->assertCreated()->assertExactJson(['data' => ['subscribed' => true]]);

    expect($this->getJson('/api/v1/settings')->json('data.push.subscriptions'))->toBe(1)
        ->and(PushSubscription::sole()->only(['endpoint', 'public_key', 'auth_token', 'content_encoding']))
        ->toBe(['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'public_key' => 'BPublica', 'auth_token' => 'segredo', 'content_encoding' => 'aes128gcm']);
});

it('mesmo endpoint de novo atualiza, sem duplicar', function () {
    $this->postJson('/api/v1/push-subscriptions', inscricao())->assertCreated();
    $this->postJson('/api/v1/push-subscriptions', [...inscricao(), 'keys' => ['p256dh' => 'BNova', 'auth' => 'novo']])->assertCreated();

    expect(PushSubscription::count())->toBe(1)->and(PushSubscription::sole()->public_key)->toBe('BNova');
});

it('mesmo navegador, outra conta: a inscrição troca de dono', function () {
    $this->postJson('/api/v1/push-subscriptions', inscricao())->assertCreated();
    $outra = login(User::factory()->create());
    $this->postJson('/api/v1/push-subscriptions', inscricao())->assertCreated();

    expect(PushSubscription::sole()->subscribable_id)->toBe($outra->id)
        ->and($this->user->pushSubscriptions()->count())->toBe(0);
});

it('DELETE apaga só a deste navegador e é idempotente (CA07)', function () {
    $this->postJson('/api/v1/push-subscriptions', inscricao())->assertCreated();
    $this->postJson('/api/v1/push-subscriptions', inscricao('https://fcm.googleapis.com/fcm/send/outro'))->assertCreated();

    $this->deleteJson('/api/v1/push-subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])->assertNoContent();
    $this->deleteJson('/api/v1/push-subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])->assertNoContent();

    expect(PushSubscription::pluck('endpoint')->all())->toBe(['https://fcm.googleapis.com/fcm/send/outro']);
});

it('valida o corpo', function (array $corpo, string $campo) {
    $this->postJson('/api/v1/push-subscriptions', $corpo)->assertUnprocessable()->assertJsonValidationErrors($campo);
})->with([
    'http sem s' => [inscricao('http://push.example/abc'), 'endpoint'],
    'sem chaves' => [['endpoint' => 'https://push.example/abc'], 'keys.p256dh'],
    'codificação estranha' => [[...inscricao(), 'content_encoding' => 'gzip'], 'content_encoding'],
]);
```

Em `tests/Feature/Auth/DeleteAccountTest.php`: no dataset, acrescentar
```php
    'push_subscriptions' => ['push_subscriptions', 'subscribable_id'],
    'sent_notifications' => ['sent_notifications', 'user_id'],
```
e, no arranjo do primeiro teste, antes do `deleteJson`:
```php
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'BPublica', 'segredo', 'aes128gcm');
    DB::table('sent_notifications')->insert(['user_id' => $user->id, 'type' => 'meal_reminder', 'reference' => '2026-09-28:almoco', 'sent_at' => now()]);
```
(A linha do `sent_notifications` só passa depois da Task 3; até lá, rode o `DeleteAccountTest` com `--filter push`.)

Run: `docker compose run --rm api php artisan test tests/Feature/Settings/PushSubscriptionTest.php`
Expected: FAIL — rota/classe inexistentes.

- [ ] **Step 2: Instalar o pacote e implementar**

```bash
docker compose run --rm api composer require laravel-notification-channels/webpush
docker compose run --rm api php artisan vendor:publish --provider="NotificationChannels\WebPush\WebPushServiceProvider" --tag="migrations"
docker compose run --rm api php artisan vendor:publish --provider="NotificationChannels\WebPush\WebPushServiceProvider" --tag="config"
```
Expected: `composer.json` com o pacote; migration `*_create_push_subscriptions_table.php` e `config/webpush.php` criados. (Se as tags tiverem outro nome na versão instalada, rode `php artisan vendor:publish --provider=...` sem `--tag`; ledger.)

`.env.example` — acrescentar ao fim:
```dotenv
# Web Push (spec 06). Gere as chaves com `php artisan webpush:vapid` — ficam só no .env, nunca no repo.
VAPID_SUBJECT=mailto:contato@pratoforte.app
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
```

`app/Models/User.php` — `use NotificationChannels\WebPush\HasPushSubscriptions;` e, na classe, `use HasPushSubscriptions;` junto dos outros traits.

`app/Http/Requests/PushSubscriptionRequest.php`:
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Inscrição Web Push deste navegador (spec 06 §5). */
class PushSubscriptionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->isMethod('DELETE')) {
            return ['endpoint' => ['required', 'string', 'max:500']];
        }

        return [
            'endpoint' => ['required', 'url', 'max:500', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['sometimes', 'in:aesgcm,aes128gcm'],
        ];
    }
}
```

`app/Http/Controllers/Api/V1/PushSubscriptionController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PushSubscriptionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PushSubscriptionController extends Controller
{
    /** Upsert por endpoint; se era de outra conta neste navegador, passa a ser desta. */
    public function store(PushSubscriptionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->updatePushSubscription(
            $request->string('endpoint')->toString(),
            $request->string('keys.p256dh')->toString(),
            $request->string('keys.auth')->toString(),
            $request->string('content_encoding', 'aes128gcm')->toString(),
        );

        return response()->json(['data' => ['subscribed' => true]], 201);
    }

    /** CA07 — sai no logout; idempotente. */
    public function destroy(PushSubscriptionRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->deletePushSubscription($request->string('endpoint')->toString());

        return response()->noContent();
    }
}
```

`routes/api.php` — `use App\Http\Controllers\Api\V1\PushSubscriptionController;` e, junto das rotas de `settings`:
```php
        Route::post('push-subscriptions', [PushSubscriptionController::class, 'store']);
        Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy']);
```

`app/Services/Account/AccountService.php` — em `delete()`, antes de `$user->delete();`:
```php
            $user->pushSubscriptions()->delete(); // morph sem FK: não sai no cascade
```

(Se `updatePushSubscription` da versão instalada não trocar o dono quando o endpoint é de outra conta, faça no controller: `PushSubscription::where('endpoint', $endpoint)->where('subscribable_id', '!=', $user->id)->delete();` antes de chamar; ledger.)

- [ ] **Step 3: Rodar, lint e commit** (e o Step 3 da Task 1)

Run: `docker compose run --rm api bash -c "php artisan migrate --force && php artisan test tests/Feature/Settings && php artisan test tests/Feature/Auth/DeleteAccountTest.php --filter push && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(configuracoes): inscrições Web Push e canal VAPID (RF26, CA07)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `sent_notifications`, `AwakeWindow` e `NotificationLedger`

**Files:**
- Create: `database/migrations/2026_10_03_000100_create_sent_notifications_table.php`, `app/Services/Notifications/AwakeWindow.php`, `app/Services/Notifications/NotificationLedger.php`
- Test: `tests/Unit/Notifications/AwakeWindowTest.php`, `tests/Feature/Notifications/NotificationLedgerTest.php`

**Interfaces:**
- Produces: `AwakeWindow::contains(string $wake, string $sleep, CarbonImmutable $moment): bool` (horas `H:i` ou `H:i:s`); `NotificationLedger::claim(User $user, string $type, string $reference, ?string $rule = null): bool` (true só na primeira vez); `NotificationLedger::countSince(User $user, string $type, CarbonImmutable $since): int`; `NotificationLedger::ruleSentSince(User $user, string $rule, CarbonImmutable $since): bool`.

- [ ] **Step 1: Testes que devem falhar**

`tests/Unit/Notifications/AwakeWindowTest.php`:
```php
<?php

use App\Services\Notifications\AwakeWindow;
use Carbon\CarbonImmutable;

it('dentro e fora da janela acordado', function (string $acorda, string $dorme, string $hora, bool $dentro) {
    expect(AwakeWindow::contains($acorda, $dorme, CarbonImmutable::parse("2026-09-28 {$hora}")))->toBe($dentro);
})->with([
    'meio da manhã' => ['06:20', '23:00', '10:00', true],
    'antes de acordar' => ['06:20', '23:00', '06:05', false],
    'na hora de acordar' => ['06:20', '23:00', '06:20', true],
    'depois de dormir' => ['06:20', '23:00', '23:15', false],
    'dorme depois da meia-noite, 23:15' => ['08:00', '01:00', '23:15', true],
    'dorme depois da meia-noite, 00:30' => ['08:00', '01:00', '00:30', true],
    'dorme depois da meia-noite, 03:00' => ['08:00', '01:00', '03:00', false],
    'com segundos do banco' => ['06:20:00', '23:00:00', '12:15', true],
]);
```

`tests/Feature/Notifications/NotificationLedgerTest.php`:
```php
<?php

use App\Models\User;
use App\Services\Notifications\NotificationLedger;
use Carbon\CarbonImmutable;

it('reserva uma vez só por referência', function () {
    $user = User::factory()->create();
    $ledger = app(NotificationLedger::class);

    expect($ledger->claim($user, 'meal_reminder', '2026-09-28:almoco'))->toBeTrue()
        ->and($ledger->claim($user, 'meal_reminder', '2026-09-28:almoco'))->toBeFalse()
        ->and($ledger->claim($user, 'meal_reminder', '2026-09-28:jantar'))->toBeTrue()
        ->and($ledger->claim(User::factory()->create(), 'meal_reminder', '2026-09-28:almoco'))->toBeTrue();
});

it('conta envios desde uma data e lembra a regra da dica', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-29 18:00'));
    $user = User::factory()->create();
    $ledger = app(NotificationLedger::class);
    $ledger->claim($user, 'tip', '2026-W40:2', 'sem-pesagem');

    expect($ledger->countSince($user, 'tip', CarbonImmutable::parse('2026-09-28')))->toBe(1)
        ->and($ledger->ruleSentSince($user, 'sem-pesagem', CarbonImmutable::parse('2026-09-15')))->toBeTrue()
        ->and($ledger->ruleSentSince($user, 'proteina', CarbonImmutable::parse('2026-09-15')))->toBeFalse();
});
```

Run: `docker compose run --rm api php artisan test tests/Unit/Notifications tests/Feature/Notifications`
Expected: FAIL — classes/tabela inexistentes.

- [ ] **Step 2: Implementar**

`database/migrations/2026_10_03_000100_create_sent_notifications_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RN38: nunca a mesma notificação duas vezes — o índice único é a trava.
        Schema::create('sent_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('reference', 40);
            $table->string('rule', 30)->nullable();
            $table->timestamp('sent_at');
            $table->unique(['user_id', 'type', 'reference']);
            $table->index(['user_id', 'type', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_notifications');
    }
};
```

`app/Services/Notifications/AwakeWindow.php`:
```php
<?php

namespace App\Services\Notifications;

use Carbon\CarbonImmutable;

/** RN38 — nada antes de acordar nem depois de dormir (dormir depois da meia-noite como no RN12). Puro. */
final class AwakeWindow
{
    public static function contains(string $wake, string $sleep, CarbonImmutable $moment): bool
    {
        $minutos = fn (string $hora) => (int) substr($hora, 0, 2) * 60 + (int) substr($hora, 3, 2);
        $acorda = $minutos($wake);
        $dorme = $minutos($sleep);
        $agora = $moment->hour * 60 + $moment->minute;

        return $dorme > $acorda
            ? $agora >= $acorda && $agora <= $dorme
            : $agora >= $acorda || $agora <= $dorme;
    }
}
```

`app/Services/Notifications/NotificationLedger.php`:
```php
<?php

namespace App\Services\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Registro do que já foi enviado (`sent_notifications`): trava anti-duplicata e contagens. */
final class NotificationLedger
{
    /** true só para quem reservou primeiro (duas execuções ao mesmo tempo: uma ganha). */
    public function claim(User $user, string $type, string $reference, ?string $rule = null): bool
    {
        return DB::table('sent_notifications')->insertOrIgnore([
            'user_id' => $user->id, 'type' => $type, 'reference' => $reference, 'rule' => $rule, 'sent_at' => now(),
        ]) === 1;
    }

    public function countSince(User $user, string $type, CarbonImmutable $since): int
    {
        return DB::table('sent_notifications')->where('user_id', $user->id)->where('type', $type)->where('sent_at', '>=', $since)->count();
    }

    public function ruleSentSince(User $user, string $rule, CarbonImmutable $since): bool
    {
        return DB::table('sent_notifications')->where('user_id', $user->id)->where('rule', $rule)->where('sent_at', '>=', $since)->exists();
    }
}
```

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm api bash -c "php artisan test tests/Unit/Notifications tests/Feature/Notifications tests/Feature/Auth/DeleteAccountTest.php && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(notificacoes): registro anti-duplicata e janela acordado (RN38)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Lembrete de refeição — `MealReminder` e `notifications:meal-reminders`

**Files:**
- Create: `app/Notifications/MealReminder.php`, `app/Console/Commands/SendMealReminders.php`, `app/Services/Notifications/EligibleUsers.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Notifications/MealReminderCommandTest.php`

**Interfaces:**
- Consumes: `AwakeWindow`, `NotificationLedger` (Task 3); `DayMaterializer::build`, `MealSummary::of`; `User::pushSubscriptions()`.
- Produces: `EligibleUsers::for(string $settingColumn): Builder<User>` (onboarding concluído + aviso ligado + ≥ 1 inscrição); `MealReminder(string $slot, string $name, string $time, string $summary)` com `toWebPush`; comando `notifications:meal-reminders` agendado a cada minuto.

- [ ] **Step 1: Teste que deve falhar**

`tests/Feature/Notifications/MealReminderCommandTest.php`:
```php
<?php

use App\Models\User;
use App\Notifications\MealReminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedCatalog();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 07:00', 'America/Sao_Paulo')); // segunda
    $this->user = User::factory()->onboarded()->create(); // acorda 06:20, dorme 23:00
    $this->user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'BPublica', 'segredo', 'aes128gcm');
    planoPronto($this->user);
    $this->almoco = collect(app(\App\Services\Days\DayMaterializer::class)->meals($this->user, CarbonImmutable::today()))->firstWhere('slot', 'almoco');
});

function rodarAs(string $hora): void
{
    test()->travelTo(CarbonImmutable::parse("2026-09-28 {$hora}", 'America/Sao_Paulo'));
    test()->artisan('notifications:meal-reminders')->assertSuccessful();
}

it('15 minutos antes do almoço não feito, avisa uma vez (CA03)', function () {
    $quinzeAntes = CarbonImmutable::parse('2026-09-28 '.substr($this->almoco->time, 0, 5), 'America/Sao_Paulo')->subMinutes(15)->format('H:i');

    rodarAs($quinzeAntes);
    rodarAs($quinzeAntes); // cron repetido no mesmo minuto
    rodarAs(CarbonImmutable::parse("2026-09-28 {$quinzeAntes}")->addMinute()->format('H:i'));

    Notification::assertSentToTimes($this->user, MealReminder::class, 1);
    Notification::assertSentTo($this->user, MealReminder::class, function (MealReminder $aviso) {
        $push = $aviso->toWebPush($this->user, $aviso)->toArray();

        return $push['title'] === 'Almoço às '.substr($this->almoco->time, 0, 5)
            && $push['data']['url'] === '/dieta/almoco'
            && $push['tag'] === 'refeicao-almoco'
            && $push['body'] !== '';
    });
});

it('refeição já feita não avisa (CA04)', function () {
    $this->almoco->update(['done_at' => now()]);
    rodarAs(CarbonImmutable::parse('2026-09-28 '.substr($this->almoco->time, 0, 5))->subMinutes(15)->format('H:i'));

    Notification::assertNothingSent();
});

it('não avisa sem inscrição, com aviso desligado, sem onboarding ou fora da janela', function (Closure $preparar) {
    $preparar($this->user);
    rodarAs(CarbonImmutable::parse('2026-09-28 '.substr($this->almoco->time, 0, 5))->subMinutes(15)->format('H:i'));

    Notification::assertNothingSent();
})->with([
    'sem inscrição' => [fn (User $u) => $u->pushSubscriptions()->delete()],
    'desligado' => [fn (User $u) => $u->settings()->update(['notify_meal_reminders' => false])],
    'sem onboarding' => [fn (User $u) => $u->profile()->update(['onboarding_completed_at' => null])],
    'dorme cedo' => [fn (User $u) => $u->profile()->update(['wake_time' => '06:00', 'sleep_time' => '11:00'])],
]);

it('sem plano ativo: pula sem erro', function () {
    $this->user->mealPlans()->update(['is_active' => false]);
    $this->user->dayMeals()->delete();

    rodarAs('12:15');

    Notification::assertNothingSent();
});

it('dia ainda não materializado: usa a prévia do plano e não grava o dia', function () {
    $this->user->dayMeals()->delete();
    rodarAs(CarbonImmutable::parse('2026-09-28 '.substr($this->almoco->time, 0, 5))->subMinutes(15)->format('H:i'));

    Notification::assertSentToTimes($this->user, MealReminder::class, 1);
    expect($this->user->dayMeals()->count())->toBe(0);
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Notifications/MealReminderCommandTest.php`
Expected: FAIL — comando inexistente.

- [ ] **Step 2: Implementar**

`app/Services/Notifications/EligibleUsers.php`:
```php
<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** RN38 — onboarding concluído, aviso ligado e ao menos uma inscrição Web Push. */
final class EligibleUsers
{
    /**
     * @param  'notify_meal_reminders'|'notify_weekly_summary'|'notify_tips'  $setting
     * @return Builder<User>
     */
    public static function for(string $setting): Builder
    {
        return User::query()
            ->whereHas('profile', fn ($q) => $q->whereNotNull('onboarding_completed_at'))
            ->whereHas('settings', fn ($q) => $q->where($setting, true))
            ->whereHas('pushSubscriptions')
            ->with('profile');
    }
}
```

`app/Notifications/MealReminder.php`:
```php
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** RF27 — "{Refeição} às {hora}" / "{resumo}"; toque abre a refeição. */
class MealReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $slot,
        public readonly string $name,
        public readonly string $time,
        public readonly string $summary,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title("{$this->name} às {$this->time}")
            ->body($this->summary)
            ->tag("refeicao-{$this->slot}")
            ->data(['url' => "/dieta/{$this->slot}"]);
    }
}
```

`app/Console/Commands/SendMealReminders.php`:
```php
<?php

namespace App\Console\Commands;

use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Models\User;
use App\Notifications\MealReminder;
use App\Services\Days\DayMaterializer;
use App\Services\Notifications\AwakeWindow;
use App\Services\Notifications\EligibleUsers;
use App\Services\Notifications\NotificationLedger;
use App\Services\Nutrition\MealSummary;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/** RF27 — a cada minuto: refeições de hoje, não feitas, que começam daqui a 15 minutos. */
class SendMealReminders extends Command
{
    protected $signature = 'notifications:meal-reminders';

    protected $description = 'Envia o lembrete 15 minutos antes de cada refeição de hoje ainda não feita';

    public function handle(DayMaterializer $days, NotificationLedger $ledger): int
    {
        $agora = CarbonImmutable::now()->startOfMinute();
        $alvo = $agora->addMinutes(15)->format('H:i');
        $hoje = CarbonImmutable::today();

        EligibleUsers::for('notify_meal_reminders')->chunkById(200, function (Collection $usuarios) use ($days, $ledger, $agora, $alvo, $hoje) {
            /** @var User $user */
            foreach ($usuarios as $user) {
                $profile = $user->profile;
                if (! AwakeWindow::contains((string) $profile->wake_time, (string) $profile->sleep_time, $agora)) {
                    continue;
                }
                $refeicao = $this->mealAt($user, $days, $hoje, $alvo);
                if ($refeicao === null || $refeicao->isDone()) {
                    continue;
                }
                if ($ledger->claim($user, 'meal_reminder', "{$hoje->toDateString()}:{$refeicao->slot}")) {
                    $user->notify(new MealReminder(
                        $refeicao->slot,
                        $refeicao->name,
                        $alvo,
                        MealSummary::of($refeicao->items->map(fn (DayMealItem $item) => $item->food->name)->values()->all()),
                    ));
                }
            }
        });

        return self::SUCCESS;
    }

    /** A refeição de hoje nesse horário: a gravada, ou a prévia do plano ativo (sem gravar o dia). */
    private function mealAt(User $user, DayMaterializer $days, CarbonImmutable $hoje, string $hora): ?DayMeal
    {
        $gravadas = $user->dayMeals()->whereDate('date', $hoje)->with('items.food')->get();
        if ($gravadas->isEmpty()) {
            $plano = $user->activePlan()->with('meals.items.food')->first();
            if ($plano === null) {
                return null;
            }
            $gravadas = $days->build($user, $plano, $hoje, save: false);
        }

        return $gravadas->first(fn (DayMeal $meal) => substr((string) $meal->time, 0, 5) === $hora);
    }
}
```

`routes/console.php` — acrescentar:
```php
Schedule::command('notifications:meal-reminders')->everyMinute()->withoutOverlapping();
```

(Se o `DayMeal` da prévia vier sem `items.food` carregado, a `build` já devolve com os alimentos do plano — confira; se `time` vier como `Carbon`, use `->format('H:i')` e registre a ruling.)

- [ ] **Step 3: Rodar, lint e commit**

Run: `docker compose run --rm api bash -c "php artisan test tests/Feature/Notifications && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(notificacoes): lembrete 15 minutos antes da refeição (RF27, CA03, CA04)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Resumo da semana — `WeeklySummaryBuilder`, `WeeklySummary` e `notifications:weekly-summary`

**Files:**
- Create: `app/Services/Notifications/WeeklySummaryBuilder.php`, `app/Notifications/WeeklySummary.php`, `app/Console/Commands/SendWeeklySummary.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Notifications/WeeklySummaryCommandTest.php`

**Interfaces:**
- Consumes: `EligibleUsers`, `NotificationLedger`, `AwakeWindow`; `DayTotals`.
- Produces: `WeeklySummaryBuilder::body(User $user, CarbonImmutable $sunday): ?string` (null sem refeição feita na semana); `WeeklySummary(string $body)`; comando agendado domingo 20:00.

- [ ] **Step 1: Teste que deve falhar**

`tests/Feature/Notifications/WeeklySummaryCommandTest.php`:
```php
<?php

use App\Models\User;
use App\Notifications\WeeklySummary;
use App\Services\Notifications\WeeklySummaryBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedCatalog();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-21 07:00', 'America/Sao_Paulo')); // segunda
    $this->user = User::factory()->onboarded()->create();
    $this->user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'BPublica', 'segredo', 'aes128gcm');
    planoPronto($this->user);
});

/** Marca refeições num dia da semana de 21 a 27/09. */
function feitasEm(string $data, array $slots): void
{
    test()->travelTo(CarbonImmutable::parse("{$data} 21:00", 'America/Sao_Paulo'));
    login(test()->user);
    test()->getJson('/api/v1/days/today')->assertOk();
    foreach ($slots as $slot) {
        test()->patchJson("/api/v1/days/today/meals/{$slot}", ['done' => true])->assertOk();
    }
}

it('monta "{n} de 7 dias completos · média de {p} g de proteína · peso +x kg"', function () {
    $todas = ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar'];
    feitasEm('2026-09-21', $todas);
    feitasEm('2026-09-22', ['cafe']);
    $this->user->weighIns()->create(['date' => '2026-09-14', 'weight_kg' => 58.0]);
    $this->user->weighIns()->create(['date' => '2026-09-26', 'weight_kg' => 58.4]);

    $texto = app(WeeklySummaryBuilder::class)->body($this->user->fresh(), CarbonImmutable::parse('2026-09-27'));

    expect($texto)->toMatch('/^1 de 7 dias completos · média de \d+ g de proteína · peso \+0,4 kg$/');
});

it('sem pesagem na semana, sem a parte do peso; sem refeição feita, sem resumo', function () {
    feitasEm('2026-09-23', ['cafe']);

    expect(app(WeeklySummaryBuilder::class)->body($this->user->fresh(), CarbonImmutable::parse('2026-09-27')))->toMatch('/^0 de 7 dias completos · média de \d+ g de proteína$/')
        ->and(app(WeeklySummaryBuilder::class)->body(User::factory()->onboarded()->create(), CarbonImmutable::parse('2026-09-27')))->toBeNull();
});

it('domingo 20:00 envia uma vez, com título e link da Evolução', function () {
    feitasEm('2026-09-23', ['cafe']);
    $this->travelTo(CarbonImmutable::parse('2026-09-27 20:00', 'America/Sao_Paulo'));

    $this->artisan('notifications:weekly-summary')->assertSuccessful();
    $this->artisan('notifications:weekly-summary')->assertSuccessful();

    Notification::assertSentToTimes($this->user, WeeklySummary::class, 1);
    Notification::assertSentTo($this->user, WeeklySummary::class, function (WeeklySummary $aviso) {
        $push = $aviso->toWebPush($this->user, $aviso)->toArray();

        return $push['title'] === 'Sua semana no Prato Forte' && $push['data']['url'] === '/evolucao' && $push['tag'] === 'resumo-semana';
    });
});

it('quem já dormiu às 20:00 não recebe', function () {
    feitasEm('2026-09-23', ['cafe']);
    $this->user->profile()->update(['wake_time' => '05:00', 'sleep_time' => '19:30']);
    $this->travelTo(CarbonImmutable::parse('2026-09-27 20:00', 'America/Sao_Paulo'));

    $this->artisan('notifications:weekly-summary')->assertSuccessful();

    Notification::assertNothingSent();
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Notifications/WeeklySummaryCommandTest.php`
Expected: FAIL — classes inexistentes.

- [ ] **Step 2: Implementar**

`app/Services/Notifications/WeeklySummaryBuilder.php`:
```php
<?php

namespace App\Services\Notifications;

use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Models\User;
use App\Services\Nutrition\DayTotals;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** RF28 — o corpo do resumo de domingo (semana ISO de segunda a domingo). */
final class WeeklySummaryBuilder
{
    public function body(User $user, CarbonImmutable $sunday): ?string
    {
        $segunda = $sunday->startOfWeek(CarbonInterface::MONDAY); // explícito: o locale pt_BR pode começar no domingo
        $refeicoes = $user->dayMeals()
            ->whereBetween('date', [$segunda->toDateString(), $sunday->toDateString()])
            ->with('items.food')
            ->get()
            ->groupBy(fn (DayMeal $meal) => $meal->date->toDateString());

        $comFeita = $refeicoes->filter(fn ($dia) => $dia->contains(fn (DayMeal $meal) => $meal->isDone()));
        if ($comFeita->isEmpty()) {
            return null;
        }

        $completos = $refeicoes->filter(fn ($dia) => $dia->every(fn (DayMeal $meal) => $meal->isDone()))->count();
        $proteina = (int) round($comFeita->avg(fn ($dia) => DayTotals::sum($dia->filter(fn (DayMeal $meal) => $meal->isDone())
            ->flatMap(fn (DayMeal $meal) => $meal->items->map(fn (DayMealItem $item) => DayTotals::item($item->food, (float) $item->grams)))
            ->values()->all())['protein']));

        $texto = "{$completos} de 7 dias completos · média de {$proteina} g de proteína";
        $variacao = $this->weightChange($user, $segunda, $sunday);

        return $variacao === null ? $texto : "{$texto} · peso {$variacao} kg";
    }

    /** Última pesagem da semana − última antes dela, com sinal e uma casa ("+0,4"). */
    private function weightChange(User $user, CarbonImmutable $segunda, CarbonImmutable $sunday): ?string
    {
        $naSemana = $user->weighIns()->whereBetween('date', [$segunda->toDateString(), $sunday->toDateString()])->orderByDesc('date')->value('weight_kg');
        $antes = $user->weighIns()->where('date', '<', $segunda->toDateString())->orderByDesc('date')->value('weight_kg');
        if ($naSemana === null || $antes === null) {
            return null;
        }
        $diferenca = round((float) $naSemana - (float) $antes, 1);

        return ($diferenca > 0 ? '+' : ($diferenca < 0 ? '−' : '')).number_format(abs($diferenca), 1, ',', '');
    }
}
```

`app/Notifications/WeeklySummary.php`:
```php
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** RF28 — "Sua semana no Prato Forte"; toque abre a Evolução. */
class WeeklySummary extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $body) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)->title('Sua semana no Prato Forte')->body($this->body)->tag('resumo-semana')->data(['url' => '/evolucao']);
    }
}
```

`app/Console/Commands/SendWeeklySummary.php`:
```php
<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\WeeklySummary;
use App\Services\Notifications\AwakeWindow;
use App\Services\Notifications\EligibleUsers;
use App\Services\Notifications\NotificationLedger;
use App\Services\Notifications\WeeklySummaryBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/** RF28 — domingo 20:00, para quem marcou ao menos uma refeição na semana. */
class SendWeeklySummary extends Command
{
    protected $signature = 'notifications:weekly-summary';

    protected $description = 'Envia o resumo da semana (domingo à noite)';

    public function handle(WeeklySummaryBuilder $builder, NotificationLedger $ledger): int
    {
        $agora = CarbonImmutable::now();
        $referencia = $agora->format('o-\WW');

        EligibleUsers::for('notify_weekly_summary')->chunkById(200, function (Collection $usuarios) use ($builder, $ledger, $agora, $referencia) {
            /** @var User $user */
            foreach ($usuarios as $user) {
                if (! AwakeWindow::contains((string) $user->profile->wake_time, (string) $user->profile->sleep_time, $agora)) {
                    continue;
                }
                $texto = $builder->body($user, $agora->startOfDay());
                if ($texto !== null && $ledger->claim($user, 'weekly_summary', $referencia)) {
                    $user->notify(new WeeklySummary($texto));
                }
            }
        });

        return self::SUCCESS;
    }
}
```

`routes/console.php` — acrescentar:
```php
Schedule::command('notifications:weekly-summary')->weeklyOn(0, '20:00');
```

- [ ] **Step 3: Rodar, lint e commit**

Run: `docker compose run --rm api bash -c "php artisan test tests/Feature/Notifications && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(notificacoes): resumo da semana no domingo (RF28)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Dicas do Nutri — `TipSelector`, `NutriTip` e `notifications:tips`

**Files:**
- Create: `app/Services/Notifications/TipSelector.php`, `app/Notifications/NutriTip.php`, `app/Console/Commands/SendNutriTips.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Notifications/TipSelectorTest.php`, `tests/Feature/Notifications/TipsCommandTest.php`

**Interfaces:**
- Consumes: `NotificationLedger`, `EligibleUsers`, `AwakeWindow`; `AdherenceCalculator`, `DayTotals`.
- Produces: `TipSelector::pick(User $user, CarbonImmutable $today): ?array{rule: string, text: string, url: string}`; `NutriTip(string $text, string $url)`; comando terça e sexta 18:00.

- [ ] **Step 1: Testes que devem falhar**

`tests/Feature/Notifications/TipSelectorTest.php`:
```php
<?php

use App\Models\User;
use App\Services\Notifications\NotificationLedger;
use App\Services\Notifications\TipSelector;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-21 07:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create()); // meta 62 kg
    planoPronto($this->user);
    $this->user->weighIns()->create(['date' => '2026-09-21', 'weight_kg' => 58.4]);
});

function diaCom(string $data, array $slots): void
{
    test()->travelTo(CarbonImmutable::parse("{$data} 21:00", 'America/Sao_Paulo'));
    test()->getJson('/api/v1/days/today')->assertOk();
    foreach ($slots as $slot) {
        test()->patchJson("/api/v1/days/today/meals/{$slot}", ['done' => true])->assertOk();
    }
}

$todas = ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar'];

it('proteína abaixo de 90% nos últimos 7 dias vem primeiro', function () {
    diaCom('2026-09-22', ['cafe']);

    expect(app(TipSelector::class)->pick($this->user->fresh(), CarbonImmutable::parse('2026-09-25')))
        ->toBe(['rule' => 'proteina', 'text' => 'Faltou proteína nesta semana. Um ovo a mais no café já ajuda.', 'url' => '/nutri']);
});

it('mesma refeição sem marcar 3 vezes', function () use ($todas) {
    foreach (['2026-09-21', '2026-09-22', '2026-09-23'] as $data) {
        diaCom($data, array_values(array_diff($todas, ['lanche'])));
    }
    app(NotificationLedger::class)->claim($this->user, 'tip', '2026-W39:5', 'proteina'); // sem o lanche a proteína pode ficar < 90%: tira a regra 1 do caminho

    expect(app(TipSelector::class)->pick($this->user->fresh(), CarbonImmutable::parse('2026-09-25')))
        ->toMatchArray(['rule' => 'refeicao-esquecida', 'url' => '/nutri'])
        ->and(app(TipSelector::class)->pick($this->user->fresh(), CarbonImmutable::parse('2026-09-25'))['text'])->toMatch('/^O lanche( da manhã)? ficou de fora 3 vezes esta semana\. Quer pedir ao Nutri uma opção mais prática\?$/');
});

it('meta definida e nenhuma pesagem há 7 dias', function () use ($todas) {
    diaCom('2026-09-28', $todas);

    expect(app(TipSelector::class)->pick($this->user->fresh(), CarbonImmutable::parse('2026-09-29'))['rule'] ?? null)->toBe('sem-pesagem');
});

it('sequência de 5 dias completos', function () use ($todas) {
    foreach (['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'] as $data) {
        diaCom($data, $todas);
    }

    expect(app(TipSelector::class)->pick($this->user->fresh(), CarbonImmutable::parse('2026-09-26')))
        ->toBe(['rule' => 'sequencia', 'text' => '5 dias seguidos com tudo feito. Segue assim!', 'url' => '/evolucao']);
});

it('regra enviada nos últimos 14 dias é pulada; nenhuma aplicável ⇒ null', function () {
    diaCom('2026-09-22', ['cafe']);
    app(NotificationLedger::class)->claim($this->user, 'tip', '2026-W39:2', 'proteina');

    expect(app(TipSelector::class)->pick($this->user->fresh(), CarbonImmutable::parse('2026-09-25')))->toBeNull();
});
```

`tests/Feature/Notifications/TipsCommandTest.php`:
```php
<?php

use App\Models\User;
use App\Notifications\NutriTip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedCatalog();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-21 07:00', 'America/Sao_Paulo'));
    $this->user = User::factory()->onboarded()->create();
    $this->user->settings()->update(['notify_tips' => true]);
    $this->user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'BPublica', 'segredo', 'aes128gcm');
    planoPronto($this->user); // sem pesagem: a regra "sem-pesagem" vale a partir de 28/09
    $this->user->weighIns()->create(['date' => '2026-09-14', 'weight_kg' => 58.4]);
});

function dicasAs(string $quando): void
{
    test()->travelTo(CarbonImmutable::parse($quando, 'America/Sao_Paulo'));
    test()->artisan('notifications:tips')->assertSuccessful();
}

it('no máximo 2 dicas na semana e nunca a mesma regra em 14 dias (CA05)', function () {
    dicasAs('2026-09-22 18:00'); // terça: sem-pesagem
    dicasAs('2026-09-22 18:00'); // repetido: nada
    dicasAs('2026-09-25 18:00'); // sexta: sem-pesagem já saiu; outra regra? nenhuma ⇒ nada

    Notification::assertSentToTimes($this->user, NutriTip::class, 1);
    Notification::assertSentTo($this->user, NutriTip::class, fn (NutriTip $dica) => $dica->toWebPush($this->user, $dica)->toArray()['data']['url'] === '/evolucao/peso');
});

it('dicas desligadas (padrão) não enviam', function () {
    $this->user->settings()->update(['notify_tips' => false]);
    dicasAs('2026-09-22 18:00');

    Notification::assertNothingSent();
});

it('com duas dicas já na semana, a sexta não envia', function () {
    \Illuminate\Support\Facades\DB::table('sent_notifications')->insert([
        ['user_id' => $this->user->id, 'type' => 'tip', 'reference' => 'a', 'rule' => 'x', 'sent_at' => '2026-09-21 18:00:00'],
        ['user_id' => $this->user->id, 'type' => 'tip', 'reference' => 'b', 'rule' => 'y', 'sent_at' => '2026-09-22 18:00:00'],
    ]);
    dicasAs('2026-09-25 18:00');

    Notification::assertNothingSent();
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Notifications/TipSelectorTest.php tests/Feature/Notifications/TipsCommandTest.php`
Expected: FAIL — classes inexistentes.

- [ ] **Step 2: Implementar**

`app/Services/Notifications/TipSelector.php`:
```php
<?php

namespace App\Services\Notifications;

use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Models\User;
use App\Services\Nutrition\DayTotals;
use App\Services\Progress\AdherenceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * RF29 — a primeira regra de dica que se aplica e não saiu nos últimos 14 dias. Sem IA.
 * "Últimos 7 dias" = de hoje − 7 a ontem.
 */
final class TipSelector
{
    public function __construct(
        private readonly NotificationLedger $ledger,
        private readonly AdherenceCalculator $adherence,
    ) {}

    /**
     * @return array{rule: string, text: string, url: string}|null
     */
    public function pick(User $user, CarbonImmutable $today): ?array
    {
        $desde = $today->subDays(7);
        $refeicoes = $user->dayMeals()
            ->whereBetween('date', [$desde->toDateString(), $today->subDay()->toDateString()])
            ->with('items.food')
            ->get();

        $candidatas = [
            fn () => $this->protein($user, $refeicoes),
            fn () => $this->skippedMeal($refeicoes),
            fn () => $this->noWeighIn($user, $today),
            fn () => $this->streak($user, $today),
        ];
        foreach ($candidatas as $candidata) {
            $dica = $candidata();
            if ($dica !== null && ! $this->ledger->ruleSentSince($user, $dica['rule'], $today->subDays(14))) {
                return $dica;
            }
        }

        return null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, DayMeal>  $refeicoes
     * @return array{rule: string, text: string, url: string}|null
     */
    private function protein(User $user, $refeicoes): ?array
    {
        $meta = $user->activePlan()->value('target_protein_g');
        $porDia = $refeicoes->filter(fn (DayMeal $meal) => $meal->isDone())
            ->groupBy(fn (DayMeal $meal) => $meal->date->toDateString())
            ->map(fn ($meals) => DayTotals::sum($meals->flatMap(fn (DayMeal $meal) => $meal->items->map(
                fn (DayMealItem $item) => DayTotals::item($item->food, (float) $item->grams),
            ))->values()->all())['protein']);
        if (! $meta || $porDia->isEmpty() || $porDia->avg() >= 0.9 * $meta) {
            return null;
        }

        return ['rule' => 'proteina', 'text' => 'Faltou proteína nesta semana. Um ovo a mais no café já ajuda.', 'url' => '/nutri'];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, DayMeal>  $refeicoes
     * @return array{rule: string, text: string, url: string}|null
     */
    private function skippedMeal($refeicoes): ?array
    {
        $esquecida = $refeicoes->reject(fn (DayMeal $meal) => $meal->isDone())
            ->groupBy('slot')
            ->map(fn ($meals) => ['name' => $meals->first()->name, 'n' => $meals->count()])
            ->filter(fn (array $slot) => $slot['n'] >= 3)
            ->sortByDesc('n')
            ->first();
        if ($esquecida === null) {
            return null;
        }
        $nome = mb_strtolower($esquecida['name']);

        return ['rule' => 'refeicao-esquecida', 'text' => "O {$nome} ficou de fora {$esquecida['n']} vezes esta semana. Quer pedir ao Nutri uma opção mais prática?", 'url' => '/nutri'];
    }

    /**
     * @return array{rule: string, text: string, url: string}|null
     */
    private function noWeighIn(User $user, CarbonImmutable $today): ?array
    {
        if ($user->profile->goal_weight_kg === null) {
            return null;
        }
        $ultima = $user->weighIns()->max('date');
        if ($ultima !== null && CarbonImmutable::parse($ultima)->greaterThan($today->subDays(7))) {
            return null;
        }

        return ['rule' => 'sem-pesagem', 'text' => 'Faz uma semana sem pesagem. Amanhã cedo, antes do café?', 'url' => '/evolucao/peso'];
    }

    /**
     * @return array{rule: string, text: string, url: string}|null
     */
    private function streak(User $user, CarbonImmutable $today): ?array
    {
        $dias = [];
        foreach (DB::table('day_meals')->where('user_id', $user->id)->where('date', '<=', $today->toDateString())
            ->groupBy('date')->selectRaw('date, count(*) as total, sum(done_at is not null) as done')->get() as $linha) {
            $dias[CarbonImmutable::parse($linha->date)->toDateString()] = ['total' => (int) $linha->total, 'done' => (int) $linha->done];
        }
        $n = $this->adherence->compute($dias, $today)['streak'];

        return $n >= 5 ? ['rule' => 'sequencia', 'text' => "{$n} dias seguidos com tudo feito. Segue assim!", 'url' => '/evolucao'] : null;
    }
}
```

`app/Notifications/NutriTip.php`:
```php
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** RF29 — dica curta; toque abre a tela relacionada. */
class NutriTip extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $text, public readonly string $url) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)->title('Dica do Nutri')->body($this->text)->tag('dica')->data(['url' => $this->url]);
    }
}
```

`app/Console/Commands/SendNutriTips.php`:
```php
<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\NutriTip;
use App\Services\Notifications\AwakeWindow;
use App\Services\Notifications\EligibleUsers;
use App\Services\Notifications\NotificationLedger;
use App\Services\Notifications\TipSelector;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/** RF29 — terça e sexta 18:00; no máximo 2 por semana (CA05). */
class SendNutriTips extends Command
{
    protected $signature = 'notifications:tips';

    protected $description = 'Envia a dica do Nutri quando uma regra se aplica (máximo 2 por semana)';

    public function handle(TipSelector $tips, NotificationLedger $ledger): int
    {
        $agora = CarbonImmutable::now();
        $referencia = $agora->format('o-\WW').':'.$agora->dayOfWeekIso;

        EligibleUsers::for('notify_tips')->chunkById(200, function (Collection $usuarios) use ($tips, $ledger, $agora, $referencia) {
            /** @var User $user */
            foreach ($usuarios as $user) {
                if (! AwakeWindow::contains((string) $user->profile->wake_time, (string) $user->profile->sleep_time, $agora)
                    || $ledger->countSince($user, 'tip', $agora->startOfWeek(CarbonInterface::MONDAY)) >= 2) {
                    continue;
                }
                $dica = $tips->pick($user, $agora->startOfDay());
                if ($dica !== null && $ledger->claim($user, 'tip', $referencia, $dica['rule'])) {
                    $user->notify(new NutriTip($dica['text'], $dica['url']));
                }
            }
        });

        return self::SUCCESS;
    }
}
```

`routes/console.php` — acrescentar:
```php
Schedule::command('notifications:tips')->days([2, 5])->at('18:00');
```

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm api bash -c "php artisan test tests/Feature/Notifications && php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(notificacoes): dicas do Nutri por regra, no máximo duas por semana (RF29, CA05)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Inscrição recusada com 410 é apagada (CA08) e o agendador documentado

**Files:**
- Create: `tests/Feature/Notifications/ExpiredSubscriptionTest.php`
- Modify: `README.md` do backend (seção "Agendador e fila")

**Interfaces:**
- Consumes: `WebPushChannel` do pacote, `MealReminder`.

- [ ] **Step 1: Teste (comportamento do pacote — confirma a integração)**

`tests/Feature/Notifications/ExpiredSubscriptionTest.php`:
```php
<?php

use App\Models\User;
use App\Notifications\MealReminder;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;

it('o serviço de push responde 410: a inscrição é apagada (CA08)', function () {
    config(['queue.default' => 'sync']);
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/velha', 'BPublica', 'segredo', 'aes128gcm');
    $push = Mockery::mock(WebPush::class);
    $push->shouldReceive('queueNotification')->once();
    $push->shouldReceive('flush')->once()->andReturnUsing(function () {
        yield new MessageSentReport(new Request('POST', 'https://fcm.googleapis.com/fcm/send/velha'), new Response(410), false, 'Gone');
    });
    app()->instance(WebPush::class, $push);

    $user->notify(new MealReminder('almoco', 'Almoço', '12:30', 'Arroz, feijão e frango'));

    expect($user->pushSubscriptions()->count())->toBe(0);
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Notifications/ExpiredSubscriptionTest.php`
Expected: PASS (o pacote apaga a inscrição expirada). Se falhar porque o `WebPushChannel` da versão instalada não resolve `WebPush` pelo contêiner, injete com `app()->bind(WebPushChannel::class, fn () => new WebPushChannel($push, app(ReportHandlerInterface::class)))` no teste; se o pacote não apagar, implemente um listener do evento `NotificationFailed`/relatório que apague `isSubscriptionExpired()` — ledger. (Teste de integração com o pacote: não há código de produção novo a fazer falhar antes; registre no ledger que foi GREEN de primeira por design.)

- [ ] **Step 2: Documentar o agendador**

No `README.md` do backend, acrescentar a seção:
```markdown
## Agendador, fila e Web Push

- O contêiner `scheduler` roda `php artisan schedule:work`; em servidor, use o cron `* * * * * php /caminho/artisan schedule:run >> /dev/null 2>&1`.
- O contêiner `queue` roda `php artisan queue:work`: os avisos (`MealReminder`, `WeeklySummary`, `NutriTip`) saem pela fila.
- Chaves VAPID: `php artisan webpush:vapid` grava `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY` no `.env` (nunca no repo). Sem elas, `GET /settings` devolve `vapid_public_key: null` e o app não oferece avisos.
- Agenda: lembrete a cada minuto; resumo domingo 20:00; dicas terça e sexta 18:00 (fuso `America/Sao_Paulo`).
```

- [ ] **Step 3: Suíte, lint e commit**

Run: `docker compose run --rm api bash -c "php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: tudo verde.

```bash
git add -A && git commit -m "test(notificacoes): inscrição recusada com 410 é apagada (CA08); agendador documentado

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
