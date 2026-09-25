# Plano 01 — Backend: fundação + contas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Subir o repositório `backend` (Laravel 12 + MySQL 8 em Docker) com o formato de erro único da API e o módulo de contas completo: cadastro, login, logout, `/me`, recuperar senha, trocar senha e apagar conta.

**Architecture:** Laravel convencional — Rota → Form Request → Controller fino → Service → Model → Resource. Autenticação por sessão com Sanctum em modo SPA (cookie `HttpOnly`, CSRF). Todo erro de `/api/*` sai no formato `{message, code, errors?, details?}` via um único renderizador registrado em `bootstrap/app.php`. Testes com Pest contra MySQL real.

**Tech Stack:** PHP 8.3, Laravel 12, MySQL 8.0, Laravel Sanctum 4, Pest 3, Larastan 3, Pint, Docker Compose, Mailpit, GitHub Actions.

**Spec:** `specs/01-contas-autenticacao/spec.md`, com a fundação em `specs/00-fundacao/{api-convencoes-e-erros,autenticacao-autorizacao,arquitetura-backend,seguranca,modelo-de-dados,estrategia-de-testes,regras-de-negocio}.md` (RN01–RN07, RN43, RN44). Roteiro geral: `docs/superpowers/plans/2026-09-24-00-roteiro.md`.

**Onde rodar os comandos:** o WSL não tem PHP; tudo roda em containers. **Todo comando parte de `/home/alvez/atividade-extensionista/backend`**, salvo quando o passo disser outra coisa. O repositório git deste plano é `backend/` (repositório novo, separado do front).

## Global Constraints

- PHP 8.3 · Laravel `^12.0` · MySQL `8.0` · collation `utf8mb4_0900_ai_ci` · Pest 3.
- `APP_TIMEZONE=America/Sao_Paulo`, `APP_LOCALE=pt_BR`; instantes em ISO 8601 com fuso (`2026-09-23T10:00:00-03:00`).
- Prefixo da API: `/api/v1`. Chaves JSON em `snake_case`. Sucesso sempre dentro de `data`.
- Erro sempre `{ "message": "…pt-BR…", "code": "CODIGO", "errors"?: {campo: [..]}, "details"?: {..} }`; o front decide por `code`, nunca pelo texto. `500` nunca expõe a mensagem da exceção.
- Sanctum SPA (cookie de sessão, driver `database`), sem tokens JWT. Um único papel: usuário (sem `role`, sem `is_admin`).
- Recurso de outro usuário responde **404** (RN43).
- Sem Repositories, sem Events/Listeners; `$fillable` explícito em todo model; `Model::shouldBeStrict(! app()->isProduction())`.
- Nenhum segredo no repositório (RN44): `.env` fora do git; a chave de IA do legado Node deve ser revogada.
- Mensagens ao usuário exatamente como nas specs: "Esse e-mail já tem conta.", "Confira o e-mail.", "Escreva seu nome.", "Use 8 ou mais caracteres, com letra e número.", "Para continuar, aceite o termo.", "A senha atual não confere.", "E-mail ou senha incorretos.", "Esse link expirou. Peça outro.", "Se houver uma conta com esse e-mail, enviamos um link.", "Senha redefinida.", "Senha trocada.".
- Rate limits: `login` 5/min (e-mail + IP) · `register` 3/min (IP) · `password` 3/h forgot, 5/h reset (e-mail + IP) · `api` 120/min (usuário ou IP).
- Link de redefinição: `${FRONTEND_URL}/senha/redefinir?token=…&email=…`, 60 min, uso único.
- Versão vigente do termo: `2026-09`.

## Review Focus

1. **E-mail no login em outra caixa ou com espaços** (`"  CAMILA@Exemplo.com "`) → entra normalmente, como no cadastro (teste em Task 5).
2. **Senha com acentos** (`ação12345`) → cadastro aceita e o login funciona depois; nada de "letra" só ASCII (testes em Task 4 e Task 5).
3. **Requisição sem `Accept: application/json`** (form, curl) para `/api/*` → erro no mesmo JSON padrão, nunca HTML nem redirect (teste em Task 2).
4. **Campos extras no corpo** (`id`, `consented_at`, `is_admin`, `remember_token`) → ignorados, nunca gravados (teste em Task 4).
5. **Recuperar senha com e-mail em outra caixa/espaços** → o link chega à conta certa (teste em Task 6).

Além disso, `email` enviado como array não pode derrubar o rate limiter com 500 (tratado no código da Task 2, testado na Task 5).

---

## Mapa de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `compose.yaml`, `docker/php/Dockerfile`, `docker/mysql/init.sql` | ambiente local: PHP 8.3 CLI, MySQL 8 (bancos `prato_forte` e `prato_forte_test`), Mailpit |
| `bootstrap/app.php` | rotas, Sanctum stateful, throttle da API, renderização de erros |
| `app/Enums/ErrorCode.php` | todos os códigos de erro com status HTTP e mensagem pt-BR |
| `app/Enums/OnboardingStep.php` | etapas do onboarding, em ordem (usado para `next_step`) |
| `app/Exceptions/DomainException.php` | exceção de regra de negócio (`ErrorCode` + `details`) |
| `app/Exceptions/ApiExceptionRenderer.php` | converte qualquer exceção de `/api/*` no formato único |
| `app/Providers/AppServiceProvider.php` | modo estrito do Eloquent, rate limiters |
| `config/prato.php` | `frontend_url`, `terms_version` |
| `app/Models/{User,Profile,UserSetting}.php` + factories | contas, perfil 1:1, configurações 1:1 |
| `app/Services/Account/AccountService.php` | cadastrar (transação usuário+perfil+config) e apagar conta |
| `app/Http/Requests/Concerns/{NormalizesEmail,PasswordRules}.php` | e-mail minúsculo/sem espaço; regra RN02 com mensagens |
| `app/Http/Requests/Auth/*Request.php` | um Form Request por ação |
| `app/Http/Controllers/Api/V1/Auth/*Controller.php` | controllers finos |
| `app/Http/Resources/UserResource.php` | contrato JSON do usuário |
| `app/Notifications/ResetPasswordNotification.php` | e-mail pt-BR com link para o front |
| `routes/api.php` | rotas `/api/v1` |
| `tests/Pest.php` | base dos testes + helpers `login()`, `fakeSession()`, `followSession()`, `registerPayload()` |
| `.github/workflows/ci.yml`, `phpstan.neon` | CI: Pint, Larastan, Pest, `composer audit` |

---

### Task 1: Repositório, Docker, Laravel 12 e Pest contra MySQL

**Files:**
- Create: `backend/compose.yaml`, `backend/docker/php/Dockerfile`, `backend/docker/mysql/init.sql`
- Create (gerado): esqueleto Laravel 12 em `backend/`
- Modify: `backend/.env`, `backend/.env.example`, `backend/config/app.php`, `backend/phpunit.xml`
- Create: `backend/tests/Pest.php`, `backend/tests/Feature/ConfigTest.php`
- Copy: `specs/` → `backend/specs/`, `docs/superpowers/plans/` → `backend/docs/superpowers/plans/`

**Interfaces:**
- Consumes: nada.
- Produces: comando de teste `docker compose run --rm api php artisan test`; banco de teste `prato_forte_test`; `tests/Pest.php` com `RefreshDatabase` em `Feature` e cabeçalhos `Origin`/`Referer` do front (`http://localhost:3000`) em todo teste de Feature.

- [ ] **Step 1: Criar a pasta e os arquivos do Docker**

```bash
mkdir -p /home/alvez/atividade-extensionista/backend/docker/php /home/alvez/atividade-extensionista/backend/docker/mysql
cd /home/alvez/atividade-extensionista/backend
```

`docker/php/Dockerfile`:
```dockerfile
FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev \
    && docker-php-ext-install pdo_mysql zip intl bcmath pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer
WORKDIR /app
```

`docker/mysql/init.sql`:
```sql
CREATE DATABASE IF NOT EXISTS prato_forte_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON prato_forte_test.* TO 'prato'@'%';
```

`compose.yaml`:
```yaml
services:
  api:
    build: ./docker/php
    user: "1000:1000"
    working_dir: /app
    volumes:
      - .:/app
    ports:
      - "8000:8000"
    command: php artisan serve --host=0.0.0.0 --port=8000
    depends_on:
      mysql:
        condition: service_healthy

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: prato_forte
      MYSQL_USER: prato
      MYSQL_PASSWORD: prato
    ports:
      - "3307:3306"
    volumes:
      - mysql-data:/var/lib/mysql
      - ./docker/mysql/init.sql:/docker-entrypoint-initdb.d/init.sql:ro
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-uroot", "-proot"]
      interval: 5s
      timeout: 5s
      retries: 30

  mailpit:
    image: axllent/mailpit
    ports:
      - "8025:8025"
      - "1025:1025"

volumes:
  mysql-data:
```
(`user: "1000:1000"` = usuário do WSL, para os arquivos gerados não ficarem com dono `root`.)

- [ ] **Step 2: Construir a imagem**

Run: `docker compose build api`
Expected: termina com `Built` (ou `naming to docker.io/library/backend-api`), sem erro.

- [ ] **Step 3: Gerar o esqueleto Laravel 12**

O `create-project` exige pasta vazia, então gera numa subpasta e move:
```bash
docker compose run --rm --no-deps api bash -c 'composer create-project laravel/laravel:^12.0 _skeleton && shopt -s dotglob && mv _skeleton/* . && rmdir _skeleton'
```
Expected: `Application key set successfully.` e depois `ls artisan composer.json` lista os dois arquivos.

Run: `docker compose run --rm --no-deps api php artisan --version`
Expected: `Laravel Framework 12.x.y`

- [ ] **Step 4: Trocar PHPUnit por Pest**

```bash
docker compose run --rm --no-deps api composer remove phpunit/phpunit --dev --no-interaction
docker compose run --rm --no-deps api composer require pestphp/pest:^3.0 pestphp/pest-plugin-laravel:^3.0 --dev --with-all-dependencies --no-interaction
docker compose run --rm --no-deps api vendor/bin/pest --init
rm -f tests/Feature/ExampleTest.php tests/Unit/ExampleTest.php
```
Expected: `vendor/bin/pest` existe; `tests/Pest.php` criado.

- [ ] **Step 5: Escrever `tests/Pest.php` e o teste de configuração (que deve falhar)**

`tests/Pest.php`:
```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function () {
        // O Sanctum só abre sessão para requisições vindas do front ("stateful"), como no navegador.
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/']);
    })
    ->in('Feature');

uses(TestCase::class)->in('Unit');
```

`tests/Feature/ConfigTest.php`:
```php
<?php

use Illuminate\Support\Facades\DB;

it('usa o fuso e o idioma de Capivari de Baixo', function () {
    expect(config('app.timezone'))->toBe('America/Sao_Paulo')
        ->and(app()->getLocale())->toBe('pt_BR');
});

it('testa contra MySQL 8 com collation sem acento e sem caixa', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getConfig('collation'))->toBe('utf8mb4_0900_ai_ci')
        ->and(DB::connection()->getDatabaseName())->toBe('prato_forte_test');
});
```

- [ ] **Step 6: Rodar e ver falhar**

Run: `docker compose run --rm api php artisan test tests/Feature/ConfigTest.php`
Expected: FAIL — `Failed asserting that 'UTC' is identical to 'America/Sao_Paulo'` e o driver `sqlite` em vez de `mysql`.

- [ ] **Step 7: Configurar ambiente, fuso, idioma e banco de teste**

Ajustar `.env` e `.env.example` (troca a linha se existir, mesmo comentada; senão acrescenta):
```bash
set_env() { f=$1; k=$2; v=$3; if grep -qE "^#? ?$k=" "$f"; then sed -i -E "s|^#? ?$k=.*|$k=$v|" "$f"; else echo "$k=$v" >> "$f"; fi; }
for f in .env .env.example; do
  set_env $f APP_NAME '"Prato Forte"'
  set_env $f APP_URL http://localhost:8000
  set_env $f APP_LOCALE pt_BR
  set_env $f APP_FALLBACK_LOCALE pt_BR
  set_env $f APP_FAKER_LOCALE pt_BR
  set_env $f APP_TIMEZONE America/Sao_Paulo
  set_env $f FRONTEND_URL http://localhost:3000
  set_env $f DB_CONNECTION mysql
  set_env $f DB_HOST mysql
  set_env $f DB_PORT 3306
  set_env $f DB_DATABASE prato_forte
  set_env $f DB_USERNAME prato
  set_env $f DB_PASSWORD prato
  set_env $f DB_COLLATION utf8mb4_0900_ai_ci
  set_env $f SESSION_DRIVER database
  set_env $f SESSION_LIFETIME 43200
  set_env $f SANCTUM_STATEFUL_DOMAINS localhost:3000
  set_env $f QUEUE_CONNECTION database
  set_env $f MAIL_MAILER smtp
  set_env $f MAIL_HOST mailpit
  set_env $f MAIL_PORT 1025
  set_env $f MAIL_FROM_ADDRESS '"nao-responda@pratoforte.test"'
  set_env $f MAIL_FROM_NAME '"Prato Forte"'
done
sed -i "s|'timezone' => .*|'timezone' => env('APP_TIMEZONE', 'America/Sao_Paulo'),|" config/app.php
rm -f database/database.sqlite
```

Substituir `phpunit.xml` inteiro:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="APP_MAINTENANCE_DRIVER" value="file"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="DB_CONNECTION" value="mysql"/>
        <env name="DB_DATABASE" value="prato_forte_test"/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="SESSION_DRIVER" value="database"/>
    </php>
</phpunit>
```
(`SESSION_DRIVER=database` nos testes é proposital: os testes de sessão conferem linhas na tabela `sessions`.)

- [ ] **Step 8: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Feature/ConfigTest.php`
Expected: PASS (2 tests). A primeira execução sobe o MySQL e pode levar ~30 s.

- [ ] **Step 9: Copiar specs e planos, criar o repositório e commitar**

```bash
cp -r ../specs ./specs
mkdir -p docs/superpowers
cp -r ../docs/superpowers/plans docs/superpowers/plans
git init -b main
git add .
git ls-files | grep -qx '.env' && echo "ERRO: .env no stage" || echo "ok: .env fora do git"
git commit -m "chore: esqueleto Laravel 12 com Docker, Pest e MySQL de teste

Inclui as specs e os planos do projeto (fonte da verdade a partir daqui).

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git switch -c plano-01-fundacao-contas
```
Expected: `ok: .env fora do git`; commit criado; branch `plano-01-fundacao-contas` ativa.
A partir daqui, as specs são editadas em `backend/specs/` (as cópias da raiz ficam obsoletas).

---

### Task 2: Formato único de erro da API

**Files:**
- Run: `php artisan install:api` (instala Sanctum e cria `routes/api.php`)
- Create: `app/Enums/ErrorCode.php`, `app/Exceptions/DomainException.php`, `app/Exceptions/ApiExceptionRenderer.php`
- Modify: `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `routes/api.php`
- Create (publicado): `lang/pt_BR/*`
- Test: `tests/Unit/Enums/ErrorCodeTest.php`, `tests/Feature/Api/ErrorFormatTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - `enum App\Enums\ErrorCode: string` com `status(): int` e `message(): string`; casos usados adiante: `Unauthenticated`, `NotFound`, `ValidationError`, `InvalidCredentials`, `InvalidResetToken`, `TooManyRequests`, `ServerError` (e os de regra das features futuras).
  - `new App\Exceptions\DomainException(ErrorCode $errorCode, array $details = [], ?string $message = null)` — services lançam; o renderizador responde.
  - Rate limiters nomeados `api`, `register`, `login`, `password`.
  - `Model::shouldBeStrict()` ligado fora de produção.

- [ ] **Step 1: Instalar a API do Sanctum e as traduções pt-BR**

```bash
docker compose run --rm api php artisan install:api --without-migration-prompt --no-interaction
docker compose run --rm api composer require lucascudo/laravel-pt-br-localization --dev --no-interaction
docker compose run --rm api php artisan vendor:publish --tag=laravel-pt-br-localization
ls lang/pt_BR/validation.php routes/api.php config/sanctum.php
```
Expected: os três arquivos listados.

- [ ] **Step 2: Escrever os testes que devem falhar**

`tests/Unit/Enums/ErrorCodeTest.php`:
```php
<?php

use App\Enums\ErrorCode;

it('tem status HTTP de erro e mensagem em português para todo código', function (ErrorCode $code) {
    expect($code->status())->toBeGreaterThanOrEqual(400)->toBeLessThan(600)
        ->and($code->message())->not->toBeEmpty();
})->with(ErrorCode::cases());

it('usa os status da tabela de códigos', function () {
    expect(ErrorCode::Unauthenticated->status())->toBe(401)
        ->and(ErrorCode::NotFound->status())->toBe(404)
        ->and(ErrorCode::NoActivePlan->status())->toBe(409)
        ->and(ErrorCode::InvalidCredentials->status())->toBe(422)
        ->and(ErrorCode::TooManyRequests->status())->toBe(429)
        ->and(ErrorCode::ServerError->status())->toBe(500)
        ->and(ErrorCode::AiUnavailable->status())->toBe(503);
});
```

`tests/Feature/Api/ErrorFormatTest.php`:
```php
<?php

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::prefix('api/v1/_teste')->group(function () {
        Route::get('dominio', fn () => throw new DomainException(ErrorCode::NoActivePlan, ['plan_status' => 'generating', 'plan_id' => 42]));
        Route::get('quebra', fn () => throw new RuntimeException('segredo do banco'));
        Route::post('valida', fn (Request $request) => $request->validate(['altura' => 'required|integer']));
        Route::get('privado', fn () => 'ok')->middleware('auth:sanctum');
        Route::get('limitado', fn () => 'ok')->middleware('throttle:1,1');
    });
});

it('responde 404 NOT_FOUND para rota inexistente', function () {
    $this->getJson('/api/v1/nao-existe')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Não encontramos o que você procurou.', 'code' => 'NOT_FOUND']);
});

it('renderiza exceção de domínio com status, código e detalhes', function () {
    $this->getJson('/api/v1/_teste/dominio')
        ->assertStatus(409)
        ->assertExactJson([
            'message' => 'Seu plano ainda não está pronto.',
            'code' => 'NO_ACTIVE_PLAN',
            'details' => ['plan_status' => 'generating', 'plan_id' => 42],
        ]);
});

it('renderiza validação com mensagem geral e erros por campo em português', function () {
    $this->postJson('/api/v1/_teste/valida', [])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Confira os campos destacados.')
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.altura.0', fn (string $message) => str_contains($message, 'obrigatório'));
});

it('responde JSON mesmo sem Accept: application/json', function () {
    $this->post('/api/v1/_teste/valida', [])
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('code', 'VALIDATION_ERROR');

    $this->get('/api/v1/_teste/privado')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('responde 401 UNAUTHENTICATED sem sessão', function () {
    $this->getJson('/api/v1/_teste/privado')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Sua sessão expirou. Entre de novo.', 'code' => 'UNAUTHENTICATED']);
});

it('responde 429 com o tempo de espera', function () {
    $this->getJson('/api/v1/_teste/limitado')->assertOk();

    $this->getJson('/api/v1/_teste/limitado')
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
        ->assertJsonPath('details.retry_after', fn (int $seconds) => $seconds > 0 && $seconds <= 60)
        ->assertHeader('Retry-After');
});

it('não expõe a mensagem da exceção em erro 500', function () {
    config(['app.debug' => false]);

    $this->getJson('/api/v1/_teste/quebra')
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Algo deu errado do nosso lado. Tente de novo.', 'code' => 'SERVER_ERROR']);
});
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `docker compose run --rm api php artisan test tests/Unit/Enums tests/Feature/Api`
Expected: FAIL — `Class "App\Enums\ErrorCode" not found`.

- [ ] **Step 4: Implementar `ErrorCode`, `DomainException` e o renderizador**

`app/Enums/ErrorCode.php`:
```php
<?php

namespace App\Enums;

/** Tabela de `specs/00-fundacao/api-convencoes-e-erros.md` §4. O front decide pelo código, nunca pelo texto. */
enum ErrorCode: string
{
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case OnboardingIncomplete = 'ONBOARDING_INCOMPLETE';
    case NoActivePlan = 'NO_ACTIVE_PLAN';
    case PlanAlreadyGenerating = 'PLAN_ALREADY_GENERATING';
    case DayNotEditable = 'DAY_NOT_EDITABLE';
    case NothingToUndo = 'NOTHING_TO_UNDO';
    case MealAlreadyDone = 'MEAL_ALREADY_DONE';
    case ActionAlreadyApplied = 'ACTION_ALREADY_APPLIED';
    case ActionExpired = 'ACTION_EXPIRED';
    case SubstitutionNotAllowed = 'SUBSTITUTION_NOT_ALLOWED';
    case AlreadyResponded = 'ALREADY_RESPONDED';
    case ValidationError = 'VALIDATION_ERROR';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case InvalidResetToken = 'INVALID_RESET_TOKEN';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case AiUnavailable = 'AI_UNAVAILABLE';
    case ServerError = 'SERVER_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::Unauthenticated => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::ValidationError, self::InvalidCredentials, self::InvalidResetToken => 422,
            self::TooManyRequests => 429,
            self::ServerError => 500,
            self::AiUnavailable => 503,
            default => 409,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::Unauthenticated => 'Sua sessão expirou. Entre de novo.',
            self::Forbidden => 'Não deu para concluir. Recarregue a página e tente de novo.',
            self::NotFound => 'Não encontramos o que você procurou.',
            self::OnboardingIncomplete => 'Termine de montar seu perfil para continuar.',
            self::NoActivePlan => 'Seu plano ainda não está pronto.',
            self::PlanAlreadyGenerating => 'Seu plano já está sendo montado.',
            self::DayNotEditable => 'Esse dia não pode mais ser alterado.',
            self::NothingToUndo => 'Não há nada para desfazer.',
            self::MealAlreadyDone => 'Essa refeição já foi marcada como feita.',
            self::ActionAlreadyApplied => 'Essa sugestão já foi aplicada.',
            self::ActionExpired => 'Essa sugestão era para outro dia.',
            self::SubstitutionNotAllowed => 'Essa troca não está disponível. Veja as opções de novo.',
            self::AlreadyResponded => 'Você já respondeu. Obrigado!',
            self::ValidationError => 'Confira os campos destacados.',
            self::InvalidCredentials => 'E-mail ou senha incorretos.',
            self::InvalidResetToken => 'Esse link expirou. Peça outro.',
            self::TooManyRequests => 'Muitas tentativas seguidas. Tente de novo em alguns segundos.',
            self::AiUnavailable => 'O Nutri não respondeu agora. Tente de novo.',
            self::ServerError => 'Algo deu errado do nosso lado. Tente de novo.',
        };
    }
}
```

`app/Exceptions/DomainException.php`:
```php
<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/** Regra de negócio violada. Services lançam; `ApiExceptionRenderer` responde. */
final class DomainException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly ErrorCode $errorCode,
        public readonly array $details = [],
        ?string $message = null,
    ) {
        parent::__construct($message ?? $errorCode->message());
    }
}
```

`app/Exceptions/ApiExceptionRenderer.php`:
```php
<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Único ponto que decide o corpo de erro de `/api/*` (`api-convencoes-e-erros.md` §3). */
final class ApiExceptionRenderer
{
    public function render(Throwable $e): ?JsonResponse
    {
        return match (true) {
            $e instanceof DomainException => $this->respond($e->errorCode, $e->getMessage(), details: $e->details),
            $e instanceof ValidationException => $this->respond(ErrorCode::ValidationError, errors: $e->errors()),
            $e instanceof AuthenticationException => $this->respond(ErrorCode::Unauthenticated),
            $e instanceof HttpExceptionInterface => $this->fromHttp($e),
            (bool) config('app.debug') => null, // em dev, deixa o Laravel mostrar a exceção
            default => $this->respond(ErrorCode::ServerError),
        };
    }

    private function fromHttp(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();

        if ($status === 429) {
            $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return $this->respond(
                ErrorCode::TooManyRequests,
                "Muitas tentativas seguidas. Tente de novo em {$retryAfter} segundos.",
                details: ['retry_after' => $retryAfter],
            )->withHeaders($e->getHeaders());
        }

        return match ($status) {
            401 => $this->respond(ErrorCode::Unauthenticated),
            403, 419 => $this->respond(ErrorCode::Forbidden),
            404, 405 => $this->respond(ErrorCode::NotFound),
            default => $this->respond(ErrorCode::ServerError, status: $status),
        };
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, mixed>  $details
     */
    private function respond(ErrorCode $code, ?string $message = null, array $errors = [], array $details = [], ?int $status = null): JsonResponse
    {
        $body = ['message' => $message ?? $code->message(), 'code' => $code->value];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        if ($details !== []) {
            $body['details'] = $details;
        }

        return new JsonResponse($body, $status ?? $code->status());
    }
}
```

- [ ] **Step 5: Ligar em `bootstrap/app.php`, no provider e nas rotas**

`bootstrap/app.php` (substituir inteiro):
```php
<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\DomainException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->throttleApi();
        // A API não tem rota "login" web: sem isso, 401 sem Accept JSON vira "Route [login] not defined".
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(DomainException::class);

        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*')
            ? app(ApiExceptionRenderer::class)->render($e)
            : null);
    })->create();
```

`app/Providers/AppServiceProvider.php` (substituir inteiro):
```php
<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Pega N+1 e atributo inexistente em dev/teste.
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->configureRateLimiting();
    }

    /** Limites de `specs/00-fundacao/seguranca.md` §8. */
    private function configureRateLimiting(): void
    {
        $emailAndIp = function (Request $request): string {
            $email = $request->input('email');

            return (is_string($email) ? Str::lower(trim($email)) : '').'|'.$request->ip();
        };

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($emailAndIp($request)));
        RateLimiter::for('password', fn (Request $request) => $request->is('api/v1/password/forgot')
            ? Limit::perHour(3)->by('forgot|'.$emailAndIp($request))
            : Limit::perHour(5)->by('reset|'.$emailAndIp($request)));
    }
}
```

`routes/api.php` (substituir inteiro):
```php
<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        //
    });
});
```

- [ ] **Step 6: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Unit/Enums tests/Feature/Api`
Expected: PASS (todos). Se `errors.altura.0` vier em inglês, confira `APP_LOCALE=pt_BR` no `.env` e se `lang/pt_BR/validation.php` existe.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(api): formato único de erro, Sanctum SPA e rate limiters

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Usuário, perfil e configurações (tabelas, models, factories)

**Files:**
- Modify: `database/migrations/0001_01_01_000000_create_users_table.php`
- Create: `database/migrations/2026_09_24_000100_create_profiles_table.php`, `database/migrations/2026_09_24_000200_create_user_settings_table.php`
- Create: `app/Enums/OnboardingStep.php`, `app/Models/Profile.php`, `app/Models/UserSetting.php`, `config/prato.php`
- Modify: `app/Models/User.php`, `database/factories/UserFactory.php`
- Create: `database/factories/ProfileFactory.php`, `database/factories/UserSettingFactory.php`
- Modify: `tests/Pest.php`
- Test: `tests/Unit/Models/ProfileTest.php`, `tests/Feature/Models/UserTest.php`

**Interfaces:**
- Consumes: Task 2 (`Model::shouldBeStrict`).
- Produces:
  - `User::profile(): HasOne<Profile>`, `User::settings(): HasOne<UserSetting>`, `User::endSessions(?string $except = null): void`.
  - `Profile::isOnboarded(): bool`, `Profile::nextStep(): ?OnboardingStep`.
  - `enum OnboardingStep: string` — `objetivo, dados, atividade, preferencias, restricoes, rotina, resumo` (nessa ordem).
  - Factories: `User::factory()` sempre cria perfil vazio + configurações padrão; senha `senha1234`; estados `onboarded()` e `withCompletedSteps(array $steps)`.
  - `config('prato.frontend_url')`, `config('prato.terms_version')` (`'2026-09'`).
  - Helpers de teste `login(?User): User` e `fakeSession(User, string $id): void`.
  - Casts de enum de domínio (`goal`, `sex`…) **não** entram aqui — chegam no Plano 03, junto com os enums.

- [ ] **Step 1: Escrever os testes que devem falhar**

`tests/Unit/Models/ProfileTest.php`:
```php
<?php

use App\Enums\OnboardingStep;
use App\Models\Profile;

it('começa pelo objetivo', function () {
    expect((new Profile)->nextStep())->toBe(OnboardingStep::Objetivo);
});

it('aponta a primeira etapa ainda não salva, na ordem do fluxo', function () {
    $profile = new Profile(['completed_steps' => ['objetivo', 'dados', 'preferencias']]);

    expect($profile->nextStep())->toBe(OnboardingStep::Atividade)
        ->and($profile->isOnboarded())->toBeFalse();
});

it('volta ao resumo quando todas as etapas foram salvas mas não concluiu', function () {
    $steps = array_map(fn (OnboardingStep $step) => $step->value, OnboardingStep::cases());

    expect((new Profile(['completed_steps' => $steps]))->nextStep())->toBe(OnboardingStep::Resumo);
});

it('não tem próxima etapa depois de concluído', function () {
    $profile = new Profile(['onboarding_completed_at' => now()]);

    expect($profile->isOnboarded())->toBeTrue()
        ->and($profile->nextStep())->toBeNull();
});
```

`tests/Feature/Models/UserTest.php`:
```php
<?php

use App\Models\Profile;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Support\Facades\DB;

it('grava o e-mail em minúsculas e sem espaços (RN01)', function () {
    $user = User::factory()->create(['email' => '  Camila.Reus@Gmail.COM ']);

    expect($user->fresh()->email)->toBe('camila.reus@gmail.com');
});

it('nasce com perfil vazio e configurações padrão', function () {
    $user = User::factory()->create();

    expect($user->profile()->count())->toBe(1)
        ->and($user->profile->completed_steps)->toBe([])
        ->and($user->profile->isOnboarded())->toBeFalse()
        ->and($user->settings->unit_system)->toBe('metric')
        ->and($user->settings->notify_meal_reminders)->toBeTrue()
        ->and($user->settings->notify_tips)->toBeFalse();
});

it('cria o perfil completo com onboarded()', function () {
    $user = User::factory()->onboarded()->create();

    expect(Profile::where('user_id', $user->id)->count())->toBe(1)
        ->and($user->profile->isOnboarded())->toBeTrue();
});

it('apaga perfil e configurações junto com o usuário (RN06)', function () {
    $user = User::factory()->create();

    $user->delete();

    expect(Profile::count())->toBe(0)
        ->and(UserSetting::count())->toBe(0);
});

it('não expõe senha nem token ao serializar', function () {
    expect(User::factory()->create()->toArray())->not->toHaveKeys(['password', 'remember_token']);
});

it('encerra as sessões do usuário, podendo poupar a atual', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    fakeSession($user, 'celular');
    fakeSession($user, 'notebook');
    fakeSession($other, 'de-outra-pessoa');

    $user->endSessions(except: 'notebook');

    expect(DB::table('sessions')->pluck('id')->sort()->values()->all())->toBe(['de-outra-pessoa', 'notebook']);
});
```
Atualizar `tests/Pest.php` (substituir inteiro):
```php
<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function () {
        // O Sanctum só abre sessão para requisições vindas do front ("stateful"), como no navegador.
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/']);
    })
    ->in('Feature');

uses(TestCase::class)->in('Unit');

/** Autentica um usuário (criado se não vier) na guarda de sessão. Senha das factories: senha1234. */
function login(?User $user = null): User
{
    $user ??= User::factory()->create();
    test()->actingAs($user, 'web');

    return $user;
}

/** Simula outra sessão aberta do usuário (outro celular ou navegador). */
function fakeSession(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => null,
        'user_agent' => null,
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose run --rm api php artisan test tests/Unit/Models tests/Feature/Models`
Expected: FAIL — `Class "App\Models\Profile" not found`.

- [ ] **Step 3: Migrations**

Em `database/migrations/0001_01_01_000000_create_users_table.php`, substituir só o `Schema::create('users', …)` (as tabelas `password_reset_tokens` e `sessions` do mesmo arquivo ficam como estão):
```php
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamp('consented_at');
            $table->string('terms_version', 20);
            $table->rememberToken();
            $table->timestamps();
        });
```

`database/migrations/2026_09_24_000100_create_profiles_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('preferred_name', 40)->nullable();
            $table->string('goal', 20)->nullable();
            $table->string('sex', 10)->nullable();
            $table->unsignedTinyInteger('age')->nullable();
            $table->unsignedSmallInteger('height_cm')->nullable();
            $table->decimal('start_weight_kg', 5, 1)->nullable();
            $table->decimal('goal_weight_kg', 5, 1)->nullable();
            $table->string('goal_weight_source', 10)->nullable();
            $table->string('activity_level', 10)->nullable();
            $table->string('work_posture', 12)->nullable();
            $table->time('wake_time')->nullable();
            $table->time('training_time')->nullable();
            $table->time('sleep_time')->nullable();
            $table->json('training_days');
            $table->string('lunch_place', 12)->nullable();
            $table->json('other_restrictions');
            $table->json('completed_steps');
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
```

`database/migrations/2026_09_24_000200_create_user_settings_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('unit_system', 10)->default('metric');
            $table->boolean('notify_meal_reminders')->default(true);
            $table->boolean('notify_weekly_summary')->default(true);
            $table->boolean('notify_tips')->default(false);
            $table->timestamp('usability_invite_dismissed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
```

- [ ] **Step 4: Enum, config e models**

`app/Enums/OnboardingStep.php`:
```php
<?php

namespace App\Enums;

/** Etapas do onboarding na ordem do fluxo (RN08). */
enum OnboardingStep: string
{
    case Objetivo = 'objetivo';
    case Dados = 'dados';
    case Atividade = 'atividade';
    case Preferencias = 'preferencias';
    case Restricoes = 'restricoes';
    case Rotina = 'rotina';
    case Resumo = 'resumo';
}
```

`config/prato.php`:
```php
<?php

return [
    // Endereço do front: links de e-mail apontam para cá.
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    // Versão vigente do termo de uso de dados (RN03). Mudou o texto do termo? Mude aqui e no front.
    'terms_version' => '2026-09',
];
```

`app/Models/User.php` (substituir inteiro):
```php
<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'consented_at', 'terms_version'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'consented_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** RN01 — e-mail sempre minúsculo e sem espaços nas pontas. */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => Str::lower(trim($value)));
    }

    /** @return HasOne<Profile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /** @return HasOne<UserSetting, $this> */
    public function settings(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    /** Derruba as sessões abertas do usuário (RN05, RN06, RF04), poupando `$except` se vier. */
    public function endSessions(?string $except = null): void
    {
        DB::table('sessions')
            ->where('user_id', $this->id)
            ->when($except, fn ($query) => $query->where('id', '!=', $except))
            ->delete();
    }
}
```

`app/Models/Profile.php`:
```php
<?php

namespace App\Models;

use App\Enums\OnboardingStep;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'preferred_name', 'goal', 'sex', 'age', 'height_cm', 'start_weight_kg', 'goal_weight_kg',
        'goal_weight_source', 'activity_level', 'work_posture', 'wake_time', 'training_time',
        'sleep_time', 'training_days', 'lunch_place', 'other_restrictions', 'completed_steps',
        'onboarding_completed_at',
    ];

    protected $attributes = [
        'training_days' => '[]',
        'other_restrictions' => '[]',
        'completed_steps' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'start_weight_kg' => 'decimal:1',
            'goal_weight_kg' => 'decimal:1',
            'training_days' => 'array',
            'other_restrictions' => 'array',
            'completed_steps' => 'array',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOnboarded(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    /** Primeira etapa ainda não salva; `resumo` se todas foram salvas; `null` se concluído. */
    public function nextStep(): ?OnboardingStep
    {
        if ($this->isOnboarded()) {
            return null;
        }

        foreach (OnboardingStep::cases() as $step) {
            if (! in_array($step->value, $this->completed_steps, true)) {
                return $step;
            }
        }

        return OnboardingStep::Resumo;
    }
}
```

`app/Models/UserSetting.php`:
```php
<?php

namespace App\Models;

use Database\Factories\UserSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    /** @use HasFactory<UserSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'unit_system', 'notify_meal_reminders', 'notify_weekly_summary', 'notify_tips',
        'usability_invite_dismissed_at',
    ];

    // Iguais aos defaults da migration, para o model recém-criado já ter os valores em memória.
    protected $attributes = [
        'unit_system' => 'metric',
        'notify_meal_reminders' => true,
        'notify_weekly_summary' => true,
        'notify_tips' => false,
    ];

    protected function casts(): array
    {
        return [
            'notify_meal_reminders' => 'boolean',
            'notify_weekly_summary' => 'boolean',
            'notify_tips' => 'boolean',
            'usability_invite_dismissed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Factories**

`database/factories/UserFactory.php` (substituir inteiro):
```php
<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('senha1234'),
            'consented_at' => now(),
            'terms_version' => config('prato.terms_version'),
            'remember_token' => Str::random(10),
        ];
    }

    /** Todo usuário tem perfil e configurações, como no cadastro real. */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if (! $user->profile()->exists()) {
                Profile::factory()->for($user)->create();
            }

            if (! $user->settings()->exists()) {
                UserSetting::factory()->for($user)->create();
            }
        });
    }

    public function onboarded(): static
    {
        return $this->has(Profile::factory()->onboarded(), 'profile');
    }

    /** @param  list<string>  $steps */
    public function withCompletedSteps(array $steps): static
    {
        return $this->has(Profile::factory()->state(['completed_steps' => $steps]), 'profile');
    }
}
```

`database/factories/ProfileFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\OnboardingStep;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sempre use com ->for($user) (ou via UserFactory): o perfil não cria usuário sozinho.
 *
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    public function definition(): array
    {
        return [];
    }

    /** Perfil da Camila do mock, com onboarding concluído. */
    public function onboarded(): static
    {
        return $this->state(fn () => [
            'goal' => 'ganhar-massa',
            'sex' => 'feminino',
            'age' => 27,
            'height_cm' => 164,
            'start_weight_kg' => 58.4,
            'goal_weight_kg' => 62.0,
            'goal_weight_source' => 'user',
            'activity_level' => 'moderado',
            'work_posture' => 'sentada',
            'wake_time' => '06:20',
            'training_time' => '19:00',
            'sleep_time' => '23:00',
            'training_days' => [1, 3, 5],
            'lunch_place' => 'marmita',
            'completed_steps' => array_map(fn (OnboardingStep $step) => $step->value, OnboardingStep::cases()),
            'onboarding_completed_at' => now(),
        ]);
    }
}
```

`database/factories/UserSettingFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\UserSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserSetting> */
class UserSettingFactory extends Factory
{
    public function definition(): array
    {
        return [];
    }
}
```

- [ ] **Step 6: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test`
Expected: PASS (todos, incluindo Tasks 1–2).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(contas): tabelas e models de usuário, perfil e configurações

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Cadastro — `POST /api/v1/register` (RF01)

**Files:**
- Create: `app/Http/Requests/Concerns/NormalizesEmail.php`, `app/Http/Requests/Concerns/PasswordRules.php`, `app/Http/Requests/Auth/RegisterRequest.php`
- Create: `app/Services/Account/AccountService.php`, `app/Http/Resources/UserResource.php`, `app/Http/Controllers/Api/V1/Auth/RegisterController.php`
- Modify: `routes/api.php`, `tests/Pest.php`
- Test: `tests/Feature/Auth/RegisterTest.php`

**Interfaces:**
- Consumes: `User`, `Profile::nextStep()`, `config('prato.terms_version')`, limiter `register`.
- Produces:
  - `trait NormalizesEmail` (em `prepareForValidation`: e-mail minúsculo e sem espaços).
  - `PasswordRules::rules(): array` e `PasswordRules::messages(string $field = 'password'): array` (RN02).
  - `AccountService::register(array{name,email,password,terms_version} $data): User` (com relação `profile` carregada).
  - `UserResource` → `{id, name, email, preferred_name, onboarding_completed, next_step, created_at, settings?}`; `settings` só sai se a relação estiver carregada.
  - Helper de teste `registerPayload(array $overrides = []): array`.

- [ ] **Step 1: Escrever os testes que devem falhar**

Acrescentar ao fim de `tests/Pest.php`:
```php

/** Corpo válido de cadastro; sobrescreva o que o teste quiser variar. */
function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Camila Réus',
        'email' => 'camila.reus@gmail.com',
        'password' => 'senha1234',
        'password_confirmation' => 'senha1234',
        'terms_accepted' => true,
        'terms_version' => '2026-09',
    ], $overrides);
}
```

`tests/Feature/Auth/RegisterTest.php`:
```php
<?php

use App\Models\User;
use Carbon\CarbonImmutable;

it('cria a conta, o perfil vazio e as configurações, e já entra (CA01)', function () {
    $this->postJson('/api/v1/register', registerPayload())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Camila Réus')
        ->assertJsonPath('data.email', 'camila.reus@gmail.com')
        ->assertJsonPath('data.preferred_name', 'Camila')
        ->assertJsonPath('data.onboarding_completed', false)
        ->assertJsonPath('data.next_step', 'objetivo')
        ->assertJsonStructure(['data' => ['id', 'created_at']])
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.settings');

    $user = User::sole();
    expect($user->consented_at)->not->toBeNull()
        ->and($user->terms_version)->toBe('2026-09')
        ->and($user->profile()->exists())->toBeTrue()
        ->and($user->settings()->value('unit_system'))->toBe('metric');
    $this->assertAuthenticatedAs($user, 'web');
});

it('devolve created_at em ISO 8601 no fuso de São Paulo', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 10:00:00', 'America/Sao_Paulo'));

    $this->postJson('/api/v1/register', registerPayload())
        ->assertJsonPath('data.created_at', '2026-09-23T10:00:00-03:00');
});

it('grava o e-mail em minúsculas e sem espaços (RN01)', function () {
    $this->postJson('/api/v1/register', registerPayload(['email' => '  Camila.Reus@Gmail.COM ']))
        ->assertCreated()
        ->assertJsonPath('data.email', 'camila.reus@gmail.com');
});

it('recusa e-mail já cadastrado em qualquer caixa (CA02)', function () {
    User::factory()->create(['email' => 'camila.reus@gmail.com']);

    $this->postJson('/api/v1/register', registerPayload(['email' => 'CAMILA.REUS@gmail.com']))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.email.0', 'Esse e-mail já tem conta.');

    expect(User::count())->toBe(1);
});

it('valida cada campo com a mensagem da spec', function (array $overrides, string $field, string $message) {
    $this->postJson('/api/v1/register', registerPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$field}.0", $message);

    expect(User::count())->toBe(0);
})->with([
    'nome vazio' => [['name' => ''], 'name', 'Escreva seu nome.'],
    'nome de 1 letra' => [['name' => 'C'], 'name', 'Escreva seu nome.'],
    'e-mail inválido' => [['email' => 'camila@'], 'email', 'Confira o e-mail.'],
    'senha curta' => [['password' => 'abc1', 'password_confirmation' => 'abc1'], 'password', 'Use 8 ou mais caracteres, com letra e número.'],
    'senha sem número' => [['password' => 'senhasenha', 'password_confirmation' => 'senhasenha'], 'password', 'Use 8 ou mais caracteres, com letra e número.'],
    'senha sem letra' => [['password' => '12345678', 'password_confirmation' => '12345678'], 'password', 'Use 8 ou mais caracteres, com letra e número.'],
    'senha com mais de 72' => [['password' => str_repeat('a1', 37), 'password_confirmation' => str_repeat('a1', 37)], 'password', 'Use no máximo 72 caracteres.'],
    'confirmação diferente' => [['password_confirmation' => 'outra1234'], 'password', 'As senhas não conferem.'],
    'termo não aceito' => [['terms_accepted' => false], 'terms_accepted', 'Para continuar, aceite o termo.'],
    'versão antiga do termo' => [['terms_version' => '2025-01'], 'terms_version', 'Atualize a página e aceite o termo de novo.'],
]);

it('aceita senha com acentos', function () {
    $this->postJson('/api/v1/register', registerPayload(['password' => 'ação12345', 'password_confirmation' => 'ação12345']))
        ->assertCreated();
});

it('ignora campos que o cliente não pode definir', function () {
    $this->postJson('/api/v1/register', registerPayload([
        'id' => 999,
        'consented_at' => '2000-01-01 00:00:00',
        'is_admin' => true,
        'remember_token' => 'forjado',
    ]))->assertCreated();

    $user = User::sole();
    expect($user->id)->not->toBe(999)
        ->and($user->consented_at->isToday())->toBeTrue()
        ->and($user->remember_token)->not->toBe('forjado');
});

it('limita cadastros a 3 por minuto por IP', function () {
    foreach (range(1, 3) as $i) {
        $this->postJson('/api/v1/register', registerPayload(['email' => "pessoa{$i}@exemplo.com"]))->assertCreated();
    }

    $this->postJson('/api/v1/register', registerPayload(['email' => 'pessoa4@exemplo.com']))
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose run --rm api php artisan test tests/Feature/Auth/RegisterTest.php`
Expected: FAIL — 404 `NOT_FOUND` em `/api/v1/register`.

- [ ] **Step 3: Regras compartilhadas de e-mail e senha**

`app/Http/Requests/Concerns/NormalizesEmail.php`:
```php
<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Str;

/** RN01 — valida e busca o e-mail já normalizado (minúsculo, sem espaços). */
trait NormalizesEmail
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }
}
```

`app/Http/Requests/Concerns/PasswordRules.php`:
```php
<?php

namespace App\Http\Requests\Concerns;

/**
 * RN02 — 8 a 72 caracteres (limite do bcrypt), ao menos uma letra (qualquer alfabeto) e um número.
 * Regras explícitas em vez de Password::defaults() para controlar a mensagem exata da spec.
 */
final class PasswordRules
{
    /** @return list<string> */
    public static function rules(): array
    {
        return ['required', 'string', 'min:8', 'max:72', 'regex:/\pL/u', 'regex:/\d/', 'confirmed'];
    }

    /** @return array<string, string> */
    public static function messages(string $field = 'password'): array
    {
        // Específicas antes do curinga: o Laravel usa a primeira chave que casa.
        return [
            "{$field}.confirmed" => 'As senhas não conferem.',
            "{$field}.max" => 'Use no máximo 72 caracteres.',
            "{$field}.*" => 'Use 8 ou mais caracteres, com letra e número.',
        ];
    }
}
```

- [ ] **Step 4: Request, service, resource, controller e rota**

`app/Http/Requests/Auth/RegisterRequest.php`:
```php
<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesEmail;
use App\Http\Requests\Concerns\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    use NormalizesEmail;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => PasswordRules::rules(),
            'terms_accepted' => ['accepted'],
            'terms_version' => ['required', 'string', Rule::in([config('prato.terms_version')])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.*' => 'Escreva seu nome.',
            'email.unique' => 'Esse e-mail já tem conta.',
            'email.*' => 'Confira o e-mail.',
            'terms_accepted.accepted' => 'Para continuar, aceite o termo.',
            'terms_version.*' => 'Atualize a página e aceite o termo de novo.',
            ...PasswordRules::messages(),
        ];
    }
}
```

`app/Services/Account/AccountService.php`:
```php
<?php

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccountService
{
    /**
     * RF01 — usuário + perfil vazio + configurações padrão, tudo ou nada.
     *
     * @param  array{name: string, email: string, password: string, terms_version: string}  $data
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'consented_at' => now(),
                'terms_version' => $data['terms_version'],
            ]);

            $user->setRelation('profile', $user->profile()->create());
            $user->settings()->create();

            return $user;
        });
    }
}
```

`app/Http/Resources/UserResource.php`:
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin \App\Models\User — exige a relação `profile` carregada. */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'preferred_name' => $this->profile->preferred_name ?? Str::before($this->name, ' '),
            'onboarding_completed' => $this->profile->isOnboarded(),
            'next_step' => $this->profile->nextStep()?->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'settings' => $this->whenLoaded('settings', fn () => [
                'unit_system' => $this->settings->unit_system,
            ]),
        ];
    }
}
```

`app/Http/Controllers/Api/V1/Auth/RegisterController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\Account\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, AccountService $accounts): JsonResponse
    {
        $user = $accounts->register($request->validated());

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        return (new UserResource($user))->response()->setStatusCode(201);
    }
}
```

`routes/api.php` (substituir inteiro):
```php
<?php

use App\Http\Controllers\Api\V1\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('register', RegisterController::class)->middleware('throttle:register');

    Route::middleware('auth:sanctum')->group(function () {
        //
    });
});
```

- [ ] **Step 5: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Feature/Auth/RegisterTest.php`
Expected: PASS (todos os casos do dataset inclusive).

- [ ] **Step 6: Rodar a suíte inteira e commitar**

Run: `docker compose run --rm api php artisan test`
Expected: PASS.

```bash
git add -A
git commit -m "feat(contas): cadastro com perfil e configurações padrão (RF01)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Login, logout, `/me`, CORS e proteção das rotas (RF02, RF03)

**Files:**
- Create: `app/Http/Requests/Auth/LoginRequest.php`, `app/Http/Controllers/Api/V1/Auth/SessionController.php`, `app/Http/Controllers/Api/V1/Auth/MeController.php`
- Create (publicado e editado): `config/cors.php`
- Modify: `routes/api.php`, `tests/Pest.php`
- Test: `tests/Feature/Auth/LoginTest.php`, `tests/Feature/Auth/LogoutTest.php`, `tests/Feature/Auth/MeTest.php`, `tests/Feature/Api/SpaTest.php`, `tests/Feature/Security/AuthenticationRequiredTest.php`

**Interfaces:**
- Consumes: `UserResource`, `NormalizesEmail`, `DomainException(ErrorCode::InvalidCredentials)`, limiter `login`, factories `onboarded()`/`withCompletedSteps()`.
- Produces:
  - `POST /api/v1/login` → 200 `UserResource`; `POST /api/v1/logout` → 204; `GET /api/v1/me` → 200 `UserResource` com `settings`.
  - Helper de teste `followSession(TestResponse $response): void` (as próximas requisições usam o cookie de sessão da resposta, e as guardas em memória são esquecidas).
  - `AuthenticationRequiredTest` varre **todas** as rotas `api/v1` com `auth:sanctum` — rotas dos próximos planos ficam cobertas sem mexer no teste.
  - Fora deste plano: remover a inscrição Web Push do navegador no logout (RF03) chega com a tabela `push_subscriptions` no Plano 07.

- [ ] **Step 1: Escrever os testes que devem falhar**

Acrescentar ao `tests/Pest.php` o import `use Illuminate\Testing\TestResponse;` e, no fim:
```php

/** As próximas requisições mandam o cookie de sessão desta resposta, como o navegador faria. */
function followSession(TestResponse $response): void
{
    $name = config('session.cookie');
    test()->withCookie($name, $response->getCookie($name)->getValue());
    app('auth')->forgetGuards();
}
```

`tests/Feature/Auth/LoginTest.php`:
```php
<?php

use App\Models\User;

it('entra e indica o destino pelo onboarding (CA03)', function (User $user, bool $completed, ?string $nextStep) {
    $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'senha1234'])
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.onboarding_completed', $completed)
        ->assertJsonPath('data.next_step', $nextStep);

    $this->assertAuthenticatedAs($user, 'web');
})->with([
    'onboarding completo' => [fn () => User::factory()->onboarded()->create(), true, null],
    'parado em atividade' => [fn () => User::factory()->withCompletedSteps(['objetivo', 'dados'])->create(), false, 'atividade'],
]);

it('mantém a sessão nas próximas requisições', function () {
    $user = User::factory()->create();

    $login = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'senha1234'])->assertOk();
    followSession($login);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', $user->email);
});

it('aceita o e-mail em outra caixa e com espaços', function () {
    $user = User::factory()->create(['email' => 'camila@exemplo.com']);

    $this->postJson('/api/v1/login', ['email' => '  CAMILA@Exemplo.com ', 'password' => 'senha1234'])->assertOk();

    $this->assertAuthenticatedAs($user, 'web');
});

it('aceita senha com acentos', function () {
    $user = User::factory()->create(['password' => 'ação12345']);

    $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'ação12345'])->assertOk();
});

it('não revela se o e-mail existe (CA04)', function () {
    User::factory()->create(['email' => 'camila@exemplo.com']);

    $wrongPassword = $this->postJson('/api/v1/login', ['email' => 'camila@exemplo.com', 'password' => 'errada123']);
    $noAccount = $this->postJson('/api/v1/login', ['email' => 'ninguem@exemplo.com', 'password' => 'errada123']);

    foreach ([$wrongPassword, $noAccount] as $response) {
        $response->assertUnprocessable()
            ->assertExactJson(['message' => 'E-mail ou senha incorretos.', 'code' => 'INVALID_CREDENTIALS']);
    }
    $this->assertGuest('web');
});

it('pede os campos vazios', function () {
    $this->postJson('/api/v1/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.email.0', 'Confira o e-mail.')
        ->assertJsonPath('errors.password.0', 'Digite sua senha.');
});

it('responde 422, não 500, quando o e-mail vem como lista', function () {
    $this->postJson('/api/v1/login', ['email' => ['a@b.com'], 'password' => 'senha1234'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_ERROR');
});

it('bloqueia a 6ª tentativa em 1 minuto', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/login', ['email' => 'camila@exemplo.com', 'password' => 'errada123'])->assertUnprocessable();
    }

    $this->postJson('/api/v1/login', ['email' => 'camila@exemplo.com', 'password' => 'errada123'])
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
        ->assertJsonPath('details.retry_after', fn (int $seconds) => $seconds > 0 && $seconds <= 60);
});
```

`tests/Feature/Auth/LogoutTest.php`:
```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

it('encerra a sessão no servidor (RF03)', function () {
    $user = User::factory()->create();
    $login = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'senha1234'])->assertOk();
    followSession($login);

    $this->postJson('/api/v1/logout')->assertNoContent();

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();

    // O navegador ainda manda o cookie antigo: não pode valer mais.
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/me')->assertUnauthorized();
});
```

`tests/Feature/Auth/MeTest.php`:
```php
<?php

use App\Models\User;

it('devolve o usuário com o estado do onboarding e as configurações', function () {
    $user = login(User::factory()->create(['name' => 'Camila Réus']));

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.preferred_name', 'Camila')
        ->assertJsonPath('data.onboarding_completed', false)
        ->assertJsonPath('data.next_step', 'objetivo')
        ->assertJsonPath('data.settings.unit_system', 'metric')
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token')
        ->assertJsonMissingPath('data.consented_at');
});

it('usa o nome preferido quando o usuário escolheu um', function () {
    $user = login();
    $user->profile->update(['preferred_name' => 'Mila']);

    $this->getJson('/api/v1/me')->assertJsonPath('data.preferred_name', 'Mila');
});

it('responde 401 sem sessão', function () {
    $this->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Sua sessão expirou. Entre de novo.', 'code' => 'UNAUTHENTICATED']);
});
```

`tests/Feature/Api/SpaTest.php`:
```php
<?php

it('entrega o cookie CSRF para o front', function () {
    $this->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN');
});

it('libera CORS com credenciais só para o front', function () {
    $this->getJson('/api/v1/me')
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');

    $this->withHeader('Origin', 'https://site-malicioso.test')
        ->getJson('/api/v1/me')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});
```

`tests/Feature/Security/AuthenticationRequiredTest.php`:
```php
<?php

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

it('responde 401 em toda rota protegida quando não há sessão (RN07)', function () {
    // Valores válidos para os parâmetros restritos das rotas dos próximos planos.
    $params = ['date' => 'today', 'slot' => 'almoco', 'step' => 'objetivo'];

    $protected = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route) => str_starts_with($route->uri(), 'api/v1/')
            && in_array('auth:sanctum', $route->gatherMiddleware(), true));

    expect($protected)->not->toBeEmpty();

    foreach ($protected as $route) {
        $method = collect($route->methods())->reject(fn (string $m) => $m === 'HEAD')->first();
        $uri = preg_replace_callback('/\{(\w+)\??\}/', fn (array $m) => $params[$m[1]] ?? '1', $route->uri());

        expect($this->json($method, '/'.$uri)->status())->toBe(401, "{$method} /{$uri}");
    }
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose run --rm api php artisan test tests/Feature/Auth tests/Feature/Api/SpaTest.php tests/Feature/Security`
Expected: FAIL — `/api/v1/login` e `/api/v1/me` com 404; `AuthenticationRequiredTest` falha em `not->toBeEmpty()`.

- [ ] **Step 3: Request, controllers, rotas e CORS**

`app/Http/Requests/Auth/LoginRequest.php`:
```php
<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    use NormalizesEmail;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.*' => 'Confira o e-mail.',
            'password.*' => 'Digite sua senha.',
        ];
    }

    /** @return array{email: string, password: string} */
    public function credentials(): array
    {
        return ['email' => $this->string('email')->value(), 'password' => $this->string('password')->value()];
    }
}
```

`app/Http/Controllers/Api/V1/Auth/SessionController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    /** RF02 — "lembrar de mim" sempre ligado: o público usa o celular pessoal. */
    public function store(LoginRequest $request): UserResource
    {
        if (! Auth::guard('web')->attempt($request->credentials(), remember: true)) {
            throw new DomainException(ErrorCode::InvalidCredentials);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();

        return new UserResource($user->load('profile'));
    }

    /** RF03 */
    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
```

`app/Http/Controllers/Api/V1/Auth/MeController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UserResource($user->load(['profile', 'settings']));
    }
}
```

`routes/api.php` (substituir inteiro):
```php
<?php

use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('register', RegisterController::class)->middleware('throttle:register');
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [SessionController::class, 'destroy']);
        Route::get('me', MeController::class);
    });
});
```

Publicar e substituir `config/cors.php`:
```bash
docker compose run --rm api php artisan config:publish cors
```
```php
<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
```

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Feature/Auth tests/Feature/Api tests/Feature/Security`
Expected: PASS.

- [ ] **Step 5: Suíte inteira e commit**

Run: `docker compose run --rm api php artisan test`
Expected: PASS.

```bash
git add -A
git commit -m "feat(contas): login, logout, /me e CORS do SPA (RF02, RF03)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Recuperar senha (RF04)

**Files:**
- Create: `app/Http/Requests/Auth/ForgotPasswordRequest.php`, `app/Http/Requests/Auth/ResetPasswordRequest.php`
- Create: `app/Http/Controllers/Api/V1/Auth/PasswordResetController.php`, `app/Notifications/ResetPasswordNotification.php`
- Modify: `app/Models/User.php` (método `sendPasswordResetNotification`), `routes/api.php`
- Test: `tests/Feature/Auth/PasswordResetTest.php`

**Interfaces:**
- Consumes: `NormalizesEmail`, `PasswordRules`, `User::endSessions()`, `ErrorCode::InvalidResetToken`, limiter `password`, `config('prato.frontend_url')`.
- Produces:
  - `POST /api/v1/password/forgot` → 200 `{message}`, sempre; `POST /api/v1/password/reset` → 200 `{message: "Senha redefinida."}` ou 422 `INVALID_RESET_TOKEN`.
  - `ResetPasswordNotification::url(User $notifiable): string` — link do front.

- [ ] **Step 1: Escrever os testes que devem falhar**

`tests/Feature/Auth/PasswordResetTest.php`:
```php
<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

function resetPayload(string $token, array $overrides = []): array
{
    return array_merge([
        'token' => $token,
        'email' => 'camila@exemplo.com',
        'password' => 'novaSenha9',
        'password_confirmation' => 'novaSenha9',
    ], $overrides);
}

it('envia o link do front para quem tem conta, mesmo com e-mail em outra caixa', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'camila@exemplo.com']);

    $this->postJson('/api/v1/password/forgot', ['email' => '  Camila@Exemplo.com '])
        ->assertOk()
        ->assertExactJson(['message' => 'Se houver uma conta com esse e-mail, enviamos um link.']);

    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user) {
        $url = $notification->url($user);

        return str_starts_with($url, 'http://localhost:3000/senha/redefinir?token=')
            && str_contains($url, 'email=camila%40exemplo.com');
    });
});

it('escreve o e-mail em português com o link', function () {
    $user = User::factory()->create(['name' => 'Camila Réus']);

    $mail = (new ResetPasswordNotification('tok123'))->toMail($user);

    expect($mail->subject)->toBe('Prato Forte — crie uma senha nova')
        ->and($mail->greeting)->toBe('Oi, Camila!')
        ->and($mail->actionText)->toBe('Criar senha nova')
        ->and($mail->actionUrl)->toContain('/senha/redefinir?token=tok123');
});

it('responde igual para e-mail sem conta e não envia nada (CA05)', function () {
    Notification::fake();

    $this->postJson('/api/v1/password/forgot', ['email' => 'ninguem@exemplo.com'])
        ->assertOk()
        ->assertExactJson(['message' => 'Se houver uma conta com esse e-mail, enviamos um link.']);

    Notification::assertNothingSent();
});

it('redefine a senha, encerra todas as sessões e não entra sozinho', function () {
    $user = User::factory()->create(['email' => 'camila@exemplo.com']);
    fakeSession($user, 'sessao-antiga');
    $token = Password::createToken($user);

    $this->postJson('/api/v1/password/reset', resetPayload($token))
        ->assertOk()
        ->assertExactJson(['message' => 'Senha redefinida.']);

    expect(Hash::check('novaSenha9', $user->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'sessao-antiga')->exists())->toBeFalse();
    $this->assertGuest('web');
});

it('recusa link com mais de 60 minutos (CA06)', function () {
    $user = User::factory()->create(['email' => 'camila@exemplo.com']);
    $token = Password::createToken($user);
    $this->travel(61)->minutes();

    $this->postJson('/api/v1/password/reset', resetPayload($token))
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Esse link expirou. Peça outro.', 'code' => 'INVALID_RESET_TOKEN']);
});

it('recusa link já usado (CA06)', function () {
    $user = User::factory()->create(['email' => 'camila@exemplo.com']);
    $token = Password::createToken($user);
    $this->postJson('/api/v1/password/reset', resetPayload($token))->assertOk();

    $this->postJson('/api/v1/password/reset', resetPayload($token, ['password' => 'outra1234', 'password_confirmation' => 'outra1234']))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'INVALID_RESET_TOKEN');
});

it('aplica a regra de senha na redefinição (RN02)', function () {
    $user = User::factory()->create(['email' => 'camila@exemplo.com']);

    $this->postJson('/api/v1/password/reset', resetPayload(Password::createToken($user), ['password' => 'curta', 'password_confirmation' => 'curta']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'Use 8 ou mais caracteres, com letra e número.');
});

it('limita pedidos de link a 3 por hora', function () {
    Notification::fake();

    foreach (range(1, 3) as $attempt) {
        $this->postJson('/api/v1/password/forgot', ['email' => 'camila@exemplo.com'])->assertOk();
    }

    $this->postJson('/api/v1/password/forgot', ['email' => 'camila@exemplo.com'])
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose run --rm api php artisan test tests/Feature/Auth/PasswordResetTest.php`
Expected: FAIL — `Class "App\Notifications\ResetPasswordNotification" not found`.

- [ ] **Step 3: Notificação e gancho no `User`**

`app/Notifications/ResetPasswordNotification.php`:
```php
<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** RF04 — enviada na hora (sem fila), para não depender do worker em recuperação de acesso. */
class ResetPasswordNotification extends Notification
{
    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Prato Forte — crie uma senha nova')
            ->greeting('Oi, '.Str::before($notifiable->name, ' ').'!')
            ->line('Recebemos um pedido para trocar a senha da sua conta no Prato Forte.')
            ->action('Criar senha nova', $this->url($notifiable))
            ->line('O link vale por 60 minutos e só pode ser usado uma vez.')
            ->line('Se não foi você, é só ignorar este e-mail: sua senha continua a mesma.')
            ->salutation('Equipe Prato Forte');
    }

    public function url(User $notifiable): string
    {
        return rtrim((string) config('prato.frontend_url'), '/').'/senha/redefinir?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);
    }
}
```

Em `app/Models/User.php`, acrescentar o import `use App\Notifications\ResetPasswordNotification;` e o método (depois de `settings()`):
```php
    /** Usa o e-mail pt-BR com link para o front, no lugar do padrão do Laravel. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
```

- [ ] **Step 4: Requests, controller e rotas**

`app/Http/Requests/Auth/ForgotPasswordRequest.php`:
```php
<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    use NormalizesEmail;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['email.*' => 'Confira o e-mail.'];
    }
}
```

`app/Http/Requests/Auth/ResetPasswordRequest.php`:
```php
<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesEmail;
use App\Http\Requests\Concerns\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordRequest extends FormRequest
{
    use NormalizesEmail;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => PasswordRules::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'token.*' => 'Esse link expirou. Peça outro.',
            'email.*' => 'Confira o e-mail.',
            ...PasswordRules::messages(),
        ];
    }
}
```

`app/Http/Controllers/Api/V1/Auth/PasswordResetController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /** RN04 — a resposta é a mesma exista ou não a conta (o status do broker é ignorado de propósito). */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(['email' => $request->validated('email')]);

        return response()->json(['message' => 'Se houver uma conta com esse e-mail, enviamos um link.']);
    }

    /** RF04 — troca a senha, derruba todas as sessões e não autentica. */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->setRememberToken(Str::random(60));
                $user->save();
                $user->endSessions();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new DomainException(ErrorCode::InvalidResetToken);
        }

        return response()->json(['message' => 'Senha redefinida.']);
    }
}
```

`routes/api.php` — acrescentar o import `use App\Http\Controllers\Api\V1\Auth\PasswordResetController;` e, logo depois da rota `login`:
```php
    Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:password');
    Route::post('password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:password');
```

- [ ] **Step 5: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Feature/Auth/PasswordResetTest.php`
Expected: PASS.

- [ ] **Step 6: Conferência manual no Mailpit (1 vez)**

```bash
docker compose up -d
docker compose exec api php artisan migrate --force
docker compose exec api php artisan tinker --execute="App\Models\User::factory()->create(['email' => 'teste@pratoforte.test']);"
curl -s -X POST http://localhost:8000/api/v1/password/forgot -H 'Accept: application/json' -H 'Content-Type: application/json' -d '{"email":"teste@pratoforte.test"}'
```
Expected: o `curl` imprime `{"message":"Se houver uma conta com esse e-mail, enviamos um link."}`; em http://localhost:8025 aparece "Prato Forte — crie uma senha nova" em português, com botão "Criar senha nova" apontando para `http://localhost:3000/senha/redefinir?...`. Encerre com `docker compose down`.

- [ ] **Step 7: Suíte inteira e commit**

Run: `docker compose run --rm api php artisan test`
Expected: PASS.

```bash
git add -A
git commit -m "feat(contas): recuperar senha com link para o front (RF04)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Trocar senha e apagar conta (RF05, RF06)

**Files:**
- Create: `app/Http/Requests/Auth/UpdatePasswordRequest.php`, `app/Http/Requests/Auth/DeleteAccountRequest.php`
- Create: `app/Http/Controllers/Api/V1/Auth/PasswordController.php`, `app/Http/Controllers/Api/V1/Auth/AccountController.php`
- Modify: `app/Services/Account/AccountService.php` (método `delete`), `routes/api.php`
- Test: `tests/Feature/Auth/UpdatePasswordTest.php`, `tests/Feature/Auth/DeleteAccountTest.php`

**Interfaces:**
- Consumes: `PasswordRules`, `User::endSessions()`, `AccountService`, helpers `login()`, `fakeSession()`, `registerPayload()`.
- Produces:
  - `PUT /api/v1/me/password` → 200 `{message: "Senha trocada."}`; `DELETE /api/v1/me` → 204.
  - `AccountService::delete(User $user): void`.
  - `DeleteAccountTest` com a **lista de tabelas por usuário**: todo plano que criar tabela com `user_id` acrescenta a tabela nela (roteiro, regra geral).

- [ ] **Step 1: Escrever os testes que devem falhar**

`tests/Feature/Auth/UpdatePasswordTest.php`:
```php
<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('troca a senha e derruba as outras sessões, mantendo a atual (CA07)', function () {
    $user = login();
    fakeSession($user, 'outro-navegador');

    $this->putJson('/api/v1/me/password', [
        'current_password' => 'senha1234',
        'password' => 'novaSenha9',
        'password_confirmation' => 'novaSenha9',
    ])->assertOk()->assertExactJson(['message' => 'Senha trocada.']);

    expect(Hash::check('novaSenha9', $user->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'outro-navegador')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
});

it('exige a senha atual correta (RN05)', function () {
    $user = login();

    $this->putJson('/api/v1/me/password', [
        'current_password' => 'errada123',
        'password' => 'novaSenha9',
        'password_confirmation' => 'novaSenha9',
    ])->assertUnprocessable()->assertJsonPath('errors.current_password.0', 'A senha atual não confere.');

    expect(Hash::check('senha1234', $user->fresh()->password))->toBeTrue();
});

it('aplica a regra de senha nova (RN02)', function () {
    login();

    $this->putJson('/api/v1/me/password', [
        'current_password' => 'senha1234',
        'password' => 'semnumero',
        'password_confirmation' => 'semnumero',
    ])->assertUnprocessable()->assertJsonPath('errors.password.0', 'Use 8 ou mais caracteres, com letra e número.');
});
```

`tests/Feature/Auth/DeleteAccountTest.php`:
```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Tabelas com dados do usuário e a coluna que aponta para ele (RN06).
 * Plano novo criou tabela com user_id? Acrescente aqui.
 */
dataset('tabelas do usuário', [
    'users' => ['users', 'id'],
    'profiles' => ['profiles', 'user_id'],
    'user_settings' => ['user_settings', 'user_id'],
    'sessions' => ['sessions', 'user_id'],
]);

it('não deixa nenhuma linha do usuário para trás (CA08)', function (string $table, string $column) {
    $user = login(User::factory()->onboarded()->create(['email' => 'camila@exemplo.com']));
    fakeSession($user, 'outro-celular');
    Password::createToken($user);

    $this->deleteJson('/api/v1/me', ['password' => 'senha1234'])->assertNoContent();

    expect(DB::table($table)->where($column, $user->id)->exists())->toBeFalse("sobrou linha em {$table}")
        ->and(DB::table('password_reset_tokens')->where('email', 'camila@exemplo.com')->exists())->toBeFalse();
    $this->assertGuest('web');
})->with('tabelas do usuário');

it('libera o e-mail para um novo cadastro (CA08)', function () {
    login(User::factory()->create(['email' => 'camila.reus@gmail.com']));
    $this->deleteJson('/api/v1/me', ['password' => 'senha1234'])->assertNoContent();
    app('auth')->forgetGuards();

    $this->postJson('/api/v1/register', registerPayload())->assertCreated();
});

it('exige a senha correta e não apaga nada', function () {
    $user = login();

    $this->deleteJson('/api/v1/me', ['password' => 'errada123'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'A senha não confere.');

    expect(User::whereKey($user->id)->exists())->toBeTrue();
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose run --rm api php artisan test tests/Feature/Auth/UpdatePasswordTest.php tests/Feature/Auth/DeleteAccountTest.php`
Expected: FAIL — 404 em `/api/v1/me/password` e 405→`NOT_FOUND` em `DELETE /api/v1/me`.

- [ ] **Step 3: Requests, service, controllers e rotas**

`app/Http/Requests/Auth/UpdatePasswordRequest.php`:
```php
<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePasswordRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => PasswordRules::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.*' => 'A senha atual não confere.',
            ...PasswordRules::messages(),
        ];
    }
}
```

`app/Http/Requests/Auth/DeleteAccountRequest.php`:
```php
<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class DeleteAccountRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'current_password:web']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['password.*' => 'A senha não confere.'];
    }
}
```

Em `app/Services/Account/AccountService.php`, acrescentar o método depois de `register()`:
```php
    /** RN06 — apaga o usuário e tudo dele. As FKs em cascata cuidam das tabelas de domínio. */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->endSessions();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });
    }
```

`app/Http/Controllers/Api/V1/Auth/PasswordController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PasswordController extends Controller
{
    /** RF05 / RN05 — as outras sessões caem; a atual continua. */
    public function __invoke(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->update(['password' => $request->validated('password')]);
        $user->endSessions(except: $request->session()->getId());

        return response()->json(['message' => 'Senha trocada.']);
    }
}
```

`app/Http/Controllers/Api/V1/Auth/AccountController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DeleteAccountRequest;
use App\Models\User;
use App\Services\Account\AccountService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    /** RF06 */
    public function destroy(DeleteAccountRequest $request, AccountService $accounts): Response
    {
        /** @var User $user */
        $user = $request->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $accounts->delete($user);

        return response()->noContent();
    }
}
```

`routes/api.php` (versão final deste plano — substituir inteiro):
```php
<?php

use App\Http\Controllers\Api\V1\Auth\AccountController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('register', RegisterController::class)->middleware('throttle:register');
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:login');
    Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:password');
    Route::post('password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:password');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [SessionController::class, 'destroy']);
        Route::get('me', MeController::class);
        Route::put('me/password', PasswordController::class);
        Route::delete('me', [AccountController::class, 'destroy']);
    });
});
```

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Feature/Auth tests/Feature/Security`
Expected: PASS — `AuthenticationRequiredTest` agora cobre também `PUT /me/password` e `DELETE /me`.

- [ ] **Step 5: Suíte inteira e commit**

Run: `docker compose run --rm api php artisan test`
Expected: PASS.

```bash
git add -A
git commit -m "feat(contas): trocar senha e apagar conta com todos os dados (RF05, RF06)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Qualidade e CI (Pint, Larastan, audit, GitHub Actions)

**Files:**
- Create: `phpstan.neon`, `.github/workflows/ci.yml`
- Modify: `composer.json` (scripts `lint`), `README.md`

**Interfaces:**
- Consumes: tudo acima.
- Produces: `composer lint` (Pint `--test` + Larastan nível 6); workflow `ci` que roda em todo push/PR do repo backend. O Plano 02 vai precisar da imagem/serviços daqui para o job `e2e` do front.

- [ ] **Step 1: Instalar Larastan e configurar**

```bash
docker compose run --rm api composer require --dev larastan/larastan:^3.0 --no-interaction
```

`phpstan.neon`:
```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 6
    paths:
        - app
    ignoreErrors:
        # Tipar o conteúdo de todo array (rules(), toArray()...) não compensa neste projeto.
        - identifier: missingType.iterableValue
```

Em `composer.json`, dentro de `"scripts"`, acrescentar:
```json
        "lint": [
            "vendor/bin/pint --test",
            "vendor/bin/phpstan analyse --no-progress --memory-limit=1G"
        ],
```

- [ ] **Step 2: Formatar e analisar**

```bash
docker compose run --rm --no-deps api vendor/bin/pint
docker compose run --rm --no-deps api composer lint
```
Expected: Pint `PASS`; PHPStan `[OK] No errors`. Se o PHPStan apontar erro, corrija o tipo na linha indicada (ex.: faltou `/** @var User $user */`) — não acrescente em `ignoreErrors`.

- [ ] **Step 3: Workflow do GitHub Actions**

`.github/workflows/ci.yml`:
```yaml
name: ci

on:
  push:
  pull_request:

jobs:
  backend:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: prato_forte_test
          MYSQL_USER: prato
          MYSQL_PASSWORD: prato
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping -h localhost -uroot -proot"
          --health-interval=5s
          --health-timeout=5s
          --health-retries=30
    env:
      DB_HOST: 127.0.0.1
      DB_PORT: 3306
      DB_USERNAME: prato
      DB_PASSWORD: prato
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo_mysql, intl, zip, bcmath
          coverage: none
      - run: composer install --no-interaction --prefer-dist --no-progress
      - run: cp .env.example .env && php artisan key:generate
      - run: composer lint
      - run: php artisan test
      - run: composer audit
```
(As variáveis `DB_*` do job têm precedência sobre o `.env` copiado, que aponta para o host `mysql` do Docker.)

- [ ] **Step 4: README do backend**

Substituir `README.md` inteiro:
````markdown
# Prato Forte — API (Laravel 12)

Backend do projeto de extensão UNINTER "Dietas saudáveis usando tecnologia de ponta".
Specs em `specs/` (comece por `specs/README.md`); planos em `docs/superpowers/plans/`.

## Rodar local (Docker)

```bash
cp .env.example .env
docker compose run --rm api composer install
docker compose run --rm api php artisan key:generate
docker compose up -d                       # API em :8000, Mailpit em :8025, MySQL em :3307
docker compose exec api php artisan migrate
```

## Testes e qualidade

```bash
docker compose run --rm api php artisan test          # Pest contra MySQL (banco prato_forte_test)
docker compose run --rm --no-deps api composer lint   # Pint + Larastan nível 6
```

## Segredos

Nunca versione `.env`. A chave de IA usada no protótipo Node foi exposta e deve ser revogada; a nova vai só no ambiente (`AI_API_KEY`, a partir do Plano 04).
````

- [ ] **Step 5: Suíte final e commit**

Run: `docker compose run --rm api php artisan test && docker compose run --rm --no-deps api composer lint && docker compose run --rm --no-deps api composer audit`
Expected: testes PASS, lint OK, `No security vulnerability advisories found`.

```bash
git add -A
git commit -m "ci: Pint, Larastan nível 6, audit e workflow do backend

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 6: Conferir a DoD da spec 01 (parte backend)**

Conferir, marcando em `specs/01-contas-autenticacao/spec.md` §9 só o que é do backend:
- CA02, CA04, CA05, CA06, CA07, CA08 cobertos por teste de Feature (CA01, CA03 e CA09 dependem das telas do Plano 02; o lado API de CA01/CA03 já está coberto).
- Endpoints, Form Requests, rate limiters, `ResetPasswordNotification` pt-BR ✔.
- Feature tests: sucesso, 422, 401, 429, cascade de exclusão ✔.

Nenhum commit extra se nada mudou na spec.
