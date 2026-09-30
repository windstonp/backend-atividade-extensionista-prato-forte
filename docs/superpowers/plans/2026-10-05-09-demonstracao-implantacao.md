# Plano 09 — Demonstração e implantação — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deixar o Prato Forte pronto para a apresentação na Zfit e para subir num servidor: `DemoSeeder` completo (Camila com 28 dias de uso e um usuário parado no onboarding), `php artisan ai:smoke` para conferir a IA de verdade antes da demonstração, ícones PNG e versão do app no front, aviso "não substitui acompanhamento profissional" (P4) e o checklist de implantação, com as pendências P1–P5 conferidas.

**Architecture:** Backend: `DemoSeeder` usa a IA falsa e o `PlanService` (como o `E2ESeeder`) para o plano, grava pesagens e materializa 28 dias com `DayMaterializer::build(save: true)` marcando as refeições no padrão de constância do mock; `DatabaseSeeder` só chama o `DemoSeeder` em `local`/`staging`. `ai:smoke` manda uma pergunta mínima pelo `AiClient` e imprime modelo, latência e tokens. Front: ícones PNG gerados do `icone.svg` pelo Playwright (script em `scripts/`), `apple-touch-icon`, `NEXT_PUBLIC_APP_VERSION` lida do `package.json`. Documento `docs/implantacao.md` com o passo a passo e a tabela das pendências.

**Tech Stack:** Laravel 12 / Pest (backend); Next 16 / Vitest / Playwright (front).

**Spec:** roteiro (`docs/superpowers/plans/2026-09-24-00-roteiro.md`, Plano 09), `specs/00-fundacao/modelo-de-dados.md` §"Desenvolvimento e demonstração", `specs/00-fundacao/estrategia-de-testes.md` §IA (smoke), `specs/00-fundacao/requisitos-nao-funcionais.md` RNF-OPE-01…05, `specs/99-inconsistencias.md` §D (P1–P5).

**Onde rodar:** backend branch `plano-09-demonstracao` saindo de `plano-08b-validacao-telas`; front branch `plano-09-demonstracao` saindo de `plano-08b-validacao-telas`.

## Decisões deste plano (rulings sobre a spec)

1. **Plano da Camila** vem da IA falsa (determinística, sem custo) pelo `PlanService`, em vez de montar à mão o `mockDayPlan` — mesmo resultado para a demonstração, sem código paralelo.
2. **Senha da demonstração** `demo1234` (a spec); só roda fora de produção (`app()->environment(['local', 'staging'])`).
3. **Termo (P3/P4):** a frase "O Prato Forte não substitui o acompanhamento de um(a) nutricionista." entra no termo e no rodapé de Configurações. A versão continua `2026-10` (08 ainda não foi publicado).
4. **Versão:** `package.json` vai a `1.0.0`; `NEXT_PUBLIC_APP_VERSION` usa a variável do ambiente ou, sem ela, a do `package.json`.
5. **Ícones:** PNG 192, 512 e 180 (Apple) gerados uma vez a partir do `icone.svg` e versionados; o SVG continua como ícone `any`.

## Global Constraints

- `DemoSeeder` idempotente para a demonstração (rodar de novo sobre `migrate:fresh`), sem IA real, sem segredo.
- `ai:smoke` nunca imprime a chave nem o conteúdo além da resposta curta; com `AI_DRIVER=fake` avisa e termina com sucesso.
- Nada de segredo no repo; `.env` nunca versionado.

## Review Focus

1. **`db:seed` em produção** ⇒ não cria as contas de demonstração (Task 1).
2. **Constância da Camila** ⇒ bate com o padrão do mock (21 dias completos, sequência 3) (Task 1).
3. **IA fora do ar no `ai:smoke`** ⇒ mensagem clara e código de saída ≠ 0, sem stack trace (Task 2).
4. **Versão sem variável de ambiente** ⇒ rodapé mostra "Versão 1.0.0" (Task 4).

---

### Task 1: `DemoSeeder` (backend)

**Files:**
- Create: `database/seeders/DemoSeeder.php`, `tests/Feature/Seeders/DemoSeederTest.php`
- Modify: `database/seeders/DatabaseSeeder.php`

- [ ] **Step 1: Branch e teste que deve falhar**

```bash
cd /home/alvez/atividade-extensionista/backend
git switch plano-08b-validacao-telas && git switch -c plano-09-demonstracao
```

`tests/Feature/Seeders/DemoSeederTest.php`:
```php
<?php

use App\Models\User;
use App\Services\Progress\ProgressService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'America/Sao_Paulo')));

it('cria a Camila do mock com plano, pesagens, 28 dias de uso e duas conversas', function () {
    $this->seed(DemoSeeder::class);

    $camila = User::where('email', 'camila@demo.pratoforte.test')->sole();
    expect(Hash::check('demo1234', $camila->password))->toBeTrue()
        ->and($camila->profile->onboarding_completed_at)->not->toBeNull()
        ->and($camila->activePlan()->exists())->toBeTrue()
        ->and($camila->restrictions()->pluck('slug')->all())->toBe(['castanhas'])
        ->and($camila->dislikedFoods()->pluck('slug')->sort()->values()->all())->toBe(['figado-bovino', 'jilo'])
        ->and($camila->weighIns()->orderBy('date')->pluck('weight_kg')->all())->toBe([56.8, 57.0, 57.5, 57.6, 58.0, 58.4])
        ->and($camila->conversations()->count())->toBe(2)
        ->and($camila->conversations()->whereNotNull('summary')->count())->toBe(2);

    $progresso = app(ProgressService::class)->show($camila, '6w', CarbonImmutable::today());
    expect($progresso['adherence']['complete_days'])->toBe(21)->and($progresso['adherence']['streak'])->toBe(3)
        ->and($progresso['weight']['forecast'])->not->toBeNull();
});

it('cria o usuário parado na etapa atividade', function () {
    $this->seed(DemoSeeder::class);

    $novo = User::where('email', 'novo@demo.pratoforte.test')->sole();
    expect($novo->profile->onboarding_completed_at)->toBeNull()
        ->and($novo->profile->nextStep()?->value)->toBe('atividade');
});

it('o DatabaseSeeder só chama a demonstração fora de produção', function () {
    app()->detectEnvironment(fn () => 'production');
    $this->seed(DatabaseSeeder::class);
    expect(User::where('email', 'camila@demo.pratoforte.test')->exists())->toBeFalse();

    app()->detectEnvironment(fn () => 'local');
    $this->seed(DatabaseSeeder::class);
    expect(User::where('email', 'camila@demo.pratoforte.test')->exists())->toBeTrue();
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Seeders/DemoSeederTest.php`
Expected: FAIL — `DemoSeeder` inexistente.

- [ ] **Step 2: Implementar**

`database/seeders/DemoSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Ai\AiClient;
use App\Ai\FakeAiClient;
use App\Ai\LoggingAiClient;
use App\Models\Food;
use App\Models\PantryItem;
use App\Models\Restriction;
use App\Models\User;
use App\Services\Days\DayMaterializer;
use App\Services\Plans\PlanService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demonstração na Zfit (modelo-de-dados.md): a Camila do mock com um mês de uso e um usuário
 * parado no onboarding. Sem IA de verdade. Senha: demo1234. Rode sobre banco limpo:
 * php artisan migrate:fresh --seed (em local/staging).
 */
class DemoSeeder extends Seeder
{
    /** Padrão de constância do mock, de hoje − 28 até ontem (hoje fica "hoje"). */
    private const CONSTANCIA = [
        'c', 'c', 'p', 'c', 'c', 'v', 'c',
        'c', 'p', 'c', 'c', 'c', 'c', 'v',
        'c', 'c', 'c', 'p', 'c', 'c', 'c',
        'c', 'c', 'v', 'c', 'c', 'c',
    ];

    public function run(): void
    {
        $this->call(CatalogSeeder::class);
        config(['queue.default' => 'sync']);
        app()->instance(AiClient::class, new LoggingAiClient(app(FakeAiClient::class)));
        $hoje = CarbonImmutable::today();

        $camila = User::factory()->onboarded()->create(['name' => 'Camila Réus', 'email' => 'camila@demo.pratoforte.test', 'password' => 'demo1234', 'created_at' => $hoje->subDays(40)]);
        $camila->profile->update(['preferred_name' => 'Camila', 'start_weight_kg' => 56.8, 'onboarding_completed_at' => $hoje->subDays(35)]);
        $camila->restrictions()->sync([Restriction::where('slug', 'castanhas')->sole()->id]);
        $camila->pantryItems()->sync(PantryItem::whereIn('slug', ['ovos', 'frango', 'arroz-e-feijao', 'batata-doce', 'banana', 'aveia', 'iogurte'])->pluck('id'));
        $camila->dislikedFoods()->sync(Food::whereIn('slug', ['figado-bovino', 'jilo'])->pluck('id'));

        $this->travel(fn () => app(PlanService::class)->requestGeneration($camila), $hoje->subDays(29));

        foreach ([56.8, 57.0, 57.5, 57.6, 58.0, 58.4] as $i => $kg) {
            $camila->weighIns()->create(['date' => $hoje->subDays(35 - $i * 7)->toDateString(), 'weight_kg' => $kg]);
        }

        $plano = $camila->activePlan()->with('meals.items.food')->sole();
        $dias = app(DayMaterializer::class);
        foreach (self::CONSTANCIA as $i => $status) {
            $data = $hoje->subDays(28 - $i);
            if ($status === 'v' && $i % 2 === 1) {
                continue; // dia sem abrir o app: nem materializa
            }
            $refeicoes = $dias->build($camila->fresh(), $plano, $data, save: true);
            $feitas = match ($status) {
                'c' => $refeicoes,
                'p' => $refeicoes->take(2),
                default => collect(),
            };
            foreach ($feitas as $meal) {
                $meal->update(['done_at' => $data->setTimeFromTimeString((string) $meal->time)]);
            }
        }

        $conversas = [
            ['Posso trocar o arroz por batata?', 'Pode. No almoço, 230 g de batata-doce entram no lugar dos 150 g de arroz.', 'Camila perguntou sobre trocar arroz por batata-doce no almoço; aceitou a troca.'],
            ['O que comer antes do treino das 19:00?', 'Banana com aveia uma hora antes funciona bem para você.', 'Camila treina às 19:00; pré-treino sugerido: banana com aveia.'],
        ];
        foreach ($conversas as $j => [$pergunta, $resposta, $resumo]) {
            $quando = $hoje->subDays(3 - $j)->setTime(12, 0);
            $conversa = $camila->conversations()->create(['title' => $pergunta, 'summary' => $resumo, 'last_message_at' => $quando]);
            $conversa->messages()->create(['role' => 'user', 'content' => $pergunta, 'created_at' => $quando]);
            $conversa->messages()->create(['role' => 'assistant', 'content' => $resposta, 'created_at' => $quando->addSeconds(8)]);
        }

        User::factory()->withCompletedSteps(['objetivo', 'dados'])->create(['name' => 'Nina Souza', 'email' => 'novo@demo.pratoforte.test', 'password' => 'demo1234'])
            ->profile->update(['goal' => 'perder-gordura', 'preferred_name' => 'Nina', 'age' => 30, 'height_cm' => 170, 'start_weight_kg' => 70.0, 'sex' => 'feminino']);
    }

    /** Roda `$acao` como se fosse `$quando` (o plano "nasce" antes dos dias de uso). */
    private function travel(callable $acao, CarbonImmutable $quando): void
    {
        CarbonImmutable::setTestNow($quando);
        \Illuminate\Support\Carbon::setTestNow($quando);
        try {
            $acao();
        } finally {
            CarbonImmutable::setTestNow();
            \Illuminate\Support\Carbon::setTestNow();
        }
    }
}
```
(Confira os nomes: `User::conversations()`, `NutriMessage` com `created_at` preenchível; se `created_at` não for `fillable`, crie e depois `forceFill`. Se o `build` exigir o perfil carregado, use `$camila->fresh(['profile'])`. Se `setTestNow` dentro do seeder atrapalhar um teste que também usa `travelTo`, guarde e restaure o `getTestNow()` anterior em vez de limpar — ledger.)

`database/seeders/DatabaseSeeder.php`:
```php
    /** Dados de referência (todos os ambientes) e, fora de produção, a demonstração. */
    public function run(): void
    {
        $this->call(CatalogSeeder::class);
        if (app()->environment(['local', 'staging'])) {
            $this->call(DemoSeeder::class);
        }
    }
```

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm api bash -c "php artisan test tests/Feature/Seeders && php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(demo): DemoSeeder com a Camila do mock e um onboarding parado

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `php artisan ai:smoke` (backend)

**Files:**
- Create: `app/Console/Commands/AiSmoke.php`, `tests/Feature/Ai/AiSmokeTest.php`

- [ ] **Step 1: Teste que deve falhar**

`tests/Feature/Ai/AiSmokeTest.php`:
```php
<?php

use App\Ai\AiClient;
use App\Ai\AiResult;
use App\Ai\AiUnavailableException;

it('com a IA falsa, avisa que não há o que testar', function () {
    config(['services.ai.driver' => 'fake']);

    $this->artisan('ai:smoke')->expectsOutputToContain('AI_DRIVER=fake')->assertSuccessful();
});

it('com a IA de verdade, pergunta, mostra modelo, tempo e tokens', function () {
    config(['services.ai.driver' => 'openai', 'services.ai.model_chat' => 'gpt-4o-mini']);
    app()->instance(AiClient::class, new class implements AiClient
    {
        public function chat(array $messages, \App\Ai\AiOptions $options): AiResult
        {
            return new AiResult('ok', 12, 1, 840);
        }
    });

    $this->artisan('ai:smoke')->expectsOutputToContain('gpt-4o-mini')->expectsOutputToContain('840 ms')->assertSuccessful();
});

it('IA fora do ar: mensagem clara e falha, sem stack trace', function () {
    config(['services.ai.driver' => 'openai']);
    app()->instance(AiClient::class, new class implements AiClient
    {
        public function chat(array $messages, \App\Ai\AiOptions $options): AiResult
        {
            throw new AiUnavailableException('HTTP 401');
        }
    });

    $this->artisan('ai:smoke')->expectsOutputToContain('A IA não respondeu')->assertFailed();
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Ai/AiSmokeTest.php`
Expected: FAIL — comando inexistente.

- [ ] **Step 2: Implementar**

`app/Console/Commands/AiSmoke.php`:
```php
<?php

namespace App\Console\Commands;

use App\Ai\AiClient;
use App\Ai\AiOptions;
use App\Ai\AiUnavailableException;
use Illuminate\Console\Command;

/** Confere a IA de verdade antes da demonstração (estrategia-de-testes.md §IA). Nunca roda nos testes automáticos. */
class AiSmoke extends Command
{
    protected $signature = 'ai:smoke';

    protected $description = 'Faz uma pergunta mínima à IA configurada e mostra se respondeu';

    public function handle(AiClient $ai): int
    {
        if (config('services.ai.driver') !== 'openai') {
            $this->warn('AI_DRIVER=fake: a IA é a falsa, não há o que testar. Configure AI_DRIVER=openai, AI_BASE_URL e AI_API_KEY no .env.');

            return self::SUCCESS;
        }

        $modelo = (string) config('services.ai.model_chat');
        try {
            $r = $ai->chat([['role' => 'user', 'content' => 'Responda apenas: ok']], new AiOptions('smoke', $modelo, 5, temperature: 0.0));
        } catch (AiUnavailableException $e) {
            $this->error("A IA não respondeu ({$e->getMessage()}). Confira AI_BASE_URL, AI_API_KEY e o saldo da conta.");

            return self::FAILURE;
        }

        $this->info("A IA respondeu: modelo {$modelo}, {$r->durationMs} ms, tokens {$r->promptTokens}+{$r->completionTokens}.");

        return self::SUCCESS;
    }
}
```
(`purpose` de `ai_requests` tem 10 caracteres — `smoke` cabe.)

- [ ] **Step 3: Rodar, lint e commit**

Run: `docker compose run --rm api bash -c "php artisan test tests/Feature/Ai && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(ia): php artisan ai:smoke para conferir a IA antes da demonstração

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Checklist de implantação e pendências conferidas (backend, docs)

**Files:**
- Create: `docs/implantacao.md`
- Modify: `README.md` (seção "Demonstração"), `specs/99-inconsistencias.md` (situação de P1–P5)

- [ ] **Step 1: Escrever `docs/implantacao.md`** com, nesta ordem:
  1. **Servidor** (RNF-OPE-02/03): PHP 8.3 com `pdo_mysql`, `intl`, `bcmath`, `zip`; MySQL 8; HTTPS (Let's Encrypt) no front e na API — obrigatório para Web Push e cookie `Secure`.
  2. **Backend `.env`**: `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` (`php artisan key:generate`), `APP_URL`, `FRONTEND_URL`, `SESSION_DOMAIN`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_SECURE_COOKIE=true`, banco, `MAIL_*` (Gmail com senha de app ou Brevo, RNF-OPE-04), `AI_DRIVER=openai` + `AI_BASE_URL` + `AI_API_KEY` (**chave nova**; a do protótipo Node foi exposta e precisa ser revogada), `VAPID_*` (`php artisan webpush:vapid`), `VALIDACAO_RODADA`/`VALIDACAO_INICIO`.
  3. **Primeira subida**: `composer install --no-dev --optimize-autoloader`, `php artisan migrate --force`, `php artisan db:seed --force` (em produção só o catálogo), `php artisan config:cache route:cache`, `php artisan ai:smoke`.
  4. **Processos**: cron `* * * * * php artisan schedule:run`; `php artisan queue:work --tries=1 --timeout=180` sob Supervisor/systemd (exemplo de unidade systemd).
  5. **Front**: `NEXT_PUBLIC_API_URL`, `NEXT_PUBLIC_APP_VERSION` (opcional), `npm ci && npm run build && npm start` (ou Vercel); o domínio do front em `FRONTEND_URL`/`SANCTUM_STATEFUL_DOMAINS`.
  6. **Antes da rodada de validação**: `ai:smoke`; conferir P3/P4/P5; abrir a rodada (README do backend).
  7. **Demonstração local**: `php artisan migrate:fresh --seed` com `APP_ENV=local` ⇒ `camila@demo.pratoforte.test` / `demo1234` (um mês de uso) e `novo@demo.pratoforte.test` / `demo1234` (onboarding parado).
- [ ] **Step 2: `README.md`** — seção "Demonstração" com o item 7 e link para `docs/implantacao.md`.
- [ ] **Step 3: `specs/99-inconsistencias.md`** — em cada P, uma linha "**Situação (Plano 09):** …":
  - P1: aplicada a recomendação (a) — Perfil diz "No Prato Forte desde {mês}"; confirmar com os autores.
  - P2: aplicada a recomendação — 18+ (RN09, `ProfileStepRequest`).
  - P3: termo provisório no cadastro (versão `2026-10`, `features/auth/termo.ts`); **o texto final é dos autores**.
  - P4: aviso "não substitui o acompanhamento de um(a) nutricionista" no termo e em Configurações (Plano 09); revisão por nutricionista continua pendente.
  - P5: coluna `source` em `database/data/foods.csv`; conferência com a TACO continua pendente.
- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "docs: checklist de implantação, demonstração e situação das pendências P1–P5

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Front — P4, versão e ícones PNG

**Files (repo front):**
- Create: `scripts/gerar-icones.mjs`, `public/icone-192.png`, `public/icone-512.png`, `public/apple-touch-icon.png`
- Modify: `src/features/auth/termo.ts`, `src/features/configuracoes/components/ConfiguracoesTela.tsx`, `src/app/manifest.ts`, `src/app/manifest.test.ts`, `src/app/layout.tsx` (ícone Apple), `next.config.ts`, `package.json` (versão), `src/features/configuracoes/components/ConfiguracoesTela.integration.test.tsx`

- [ ] **Step 1: Testes que devem falhar**

`src/app/manifest.test.ts` — trocar `icons` esperado por:
```ts
      icons: [
        { src: '/icone.svg', sizes: 'any', type: 'image/svg+xml' },
        { src: '/icone-192.png', sizes: '192x192', type: 'image/png' },
        { src: '/icone-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
      ],
```
`ConfiguracoesTela.integration.test.tsx` — acrescentar:
```tsx
  it('rodapé: não substitui nutricionista e mostra a versão do app', async () => {
    renderizar(<ConfiguracoesTela />);

    expect(await screen.findByText('O Prato Forte não substitui o acompanhamento de um(a) nutricionista.')).toBeInTheDocument();
    expect(screen.getByText(/^Versão \d+\.\d+\.\d+$/)).toBeInTheDocument();
  });
```

Run: `docker compose run --rm web npx vitest run --project unit --project integration src/app/manifest.test.ts src/features/configuracoes`
Expected: FAIL.

- [ ] **Step 2: Implementar**

- `package.json`: `"version": "1.0.0"`.
- `next.config.ts`:
```ts
import type { NextConfig } from "next";
import pacote from "./package.json" with { type: "json" };

const nextConfig: NextConfig = {
  // Versão no rodapé de Configurações: a do ambiente ou a do package.json.
  env: { NEXT_PUBLIC_APP_VERSION: process.env.NEXT_PUBLIC_APP_VERSION ?? pacote.version },
};

export default nextConfig;
```
(Se o import com `with` não compilar no `next.config.ts`, leia com `readFileSync` + `JSON.parse`; registre. No Vitest, `process.env.NEXT_PUBLIC_APP_VERSION` não passa pelo Next: defina `NEXT_PUBLIC_APP_VERSION` em `vitest.setup.ts` com a versão do `package.json`.)
- `ConfiguracoesTela.tsx`: no rodapé, antes da versão, `<p className="mt-2 text-[12.5px] text-fumo">O Prato Forte não substitui o acompanhamento de um(a) nutricionista.</p>`.
- `termo.ts`: acrescentar ao primeiro parágrafo (finalidade) a frase "O Prato Forte não substitui o acompanhamento de um(a) nutricionista." (versão continua `2026-10`).
- `scripts/gerar-icones.mjs`:
```js
// Gera os PNG do PWA a partir de public/icone.svg (rodar uma vez: node scripts/gerar-icones.mjs dentro do contêiner web).
import { readFileSync } from 'node:fs';
import { chromium } from '@playwright/test';

const svg = readFileSync('public/icone.svg', 'utf8');
const navegador = await chromium.launch();
const pagina = await navegador.newPage();
for (const [arquivo, lado] of [['icone-192.png', 192], ['icone-512.png', 512], ['apple-touch-icon.png', 180]]) {
  await pagina.setViewportSize({ width: lado, height: lado });
  await pagina.setContent(`<html><body style="margin:0">${svg.replace('<svg ', `<svg width="${lado}" height="${lado}" `)}</body></html>`);
  await pagina.screenshot({ path: `public/${arquivo}`, omitBackground: false });
}
await navegador.close();
```
  Rodar: `docker compose run --rm web node scripts/gerar-icones.mjs` ⇒ três PNG em `public/`.
- `manifest.ts`: `icons` como no teste.
- `layout.tsx` (raiz): em `metadata`, `icons: { apple: '/apple-touch-icon.png' }` e `appleWebApp: { capable: true, title: 'Prato Forte', statusBarStyle: 'default' }`.

- [ ] **Step 3: Rodar, suíte, build e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project unit --project integration src/app src/features/configuracoes && npm run lint && npm run typecheck && npm test && npm run build"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(demo): aviso de acompanhamento profissional, versão 1.0.0 e ícones PNG do PWA

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Tudo verde de ponta a ponta

- [ ] **Step 1:** Backend: `docker compose run --rm api bash -c "php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"` ⇒ verde.
- [ ] **Step 2:** Demonstração: `docker compose exec api php artisan migrate:fresh --seed` (com `APP_ENV=local`) ⇒ entrar como `camila@demo.pratoforte.test` no front e conferir Hoje, Evolução (constância e previsão) e Nutri (duas conversas); screenshot de cada.
- [ ] **Step 3:** E2E: `migrate:fresh --seeder=E2ESeeder --force` e `docker compose run --rm web npm run e2e` ⇒ todos verdes.
- [ ] **Step 4:** Ledger com os números; sem commit se nada mudou.
