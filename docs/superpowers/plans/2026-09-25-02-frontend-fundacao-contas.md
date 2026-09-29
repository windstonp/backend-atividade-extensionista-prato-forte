# Plano 02 — Frontend: fundação + biblioteca + contas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar ao front (Next.js 16) a base de testes e de dados reais — Storybook, Vitest, MSW, Playwright, cliente da API, guarda contra mocks (D12) — e entregar as telas de conta (cadastro, entrar, recuperar/redefinir senha, trocar senha, sair e apagar conta) ligadas à API do Plano 01.

**Architecture:** Toda I/O passa por `src/lib/api/` (cookie de sessão Sanctum, CSRF, snake↔camel, `ApiError`). Estado do servidor em TanStack Query (`['me']`); telas = contêiner (hooks + navegação) + formulário apresentacional (props `aoEnviar`), para o formulário ser testado em story e o contêiner em integração com MSW. `proxy.ts` é só a primeira barreira; a autoridade é `GET /me` nos layouts (`AuthGate`).

**Tech Stack:** Next.js 16.3.5, React 19.2, TypeScript 5, Tailwind 4, TanStack Query 5.103, Storybook 10.6 (`@storybook/nextjs-vite`, addon-vitest, addon-a11y, addon-docs), Vitest 4.1.11 (projetos unit/integration/storybook), Testing Library, MSW 2.15, Playwright 1.63, ESLint 9 + `eslint-config-next` 16.3.5, Docker (`mcr.microsoft.com/playwright:v1.63.0-noble`).

**Spec:** `specs/01-contas-autenticacao/spec.md` (telas N01–N04, N06, N07, S01, bloco "Sua conta" de S19; CA01–CA09) com a fundação em `specs/00-fundacao/{arquitetura-frontend,autenticacao-autorizacao,api-convencoes-e-erros,estrategia-de-testes}.md` e `specs/08-design-system/spec.md` (Button, Field/PasswordField, Sheet, Toast/Toaster, FormError). Todos os caminhos de spec/plano são relativos ao repo **backend** (`/home/alvez/atividade-extensionista/backend`). Roteiro: `docs/superpowers/plans/2026-09-24-00-roteiro.md`.

**Onde rodar:** o WSL não tem Node; tudo roda no container `web` (imagem do Playwright, com Node e navegadores). Salvo indicação, **todo comando parte de `/home/alvez/atividade-extensionista/frontend`** e o repositório git é o do front (`windstonp/frontend-atividade-extensionista-prato-forte`). A Task 9 também mexe no repo backend e diz quando.

## Global Constraints

- Versões exatas: `storybook`/`@storybook/*`/`eslint-plugin-storybook` **10.6.0**; `vitest`/`@vitest/browser-playwright` **4.1.11**; `playwright`/`@playwright/test` **1.63.0** (igual à tag da imagem Docker); `next` 16.3.5 (não mexer).
- Next 16: o antigo `middleware.ts` é **`src/proxy.ts`** (export `proxy`). Antes de escrever código Next, consultar `node_modules/next/dist/docs/` (regra do `AGENTS.md`).
- API: `process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000'` + `/api/v1`; `credentials: 'include'`; antes da 1ª escrita `GET /sanctum/csrf-cookie`; header `X-XSRF-TOKEN` do cookie `XSRF-TOKEN`; o front decide por `code`, **nunca** pelo texto.
- Cookie de sessão do Laravel: **`prato-forte-session`** (derivado de `APP_NAME="Prato Forte"`).
- D12: nenhum import de `src/mocks/**` / `mock-data` fora de `src/mocks/**`, `*.stories.tsx`, `*.test.ts(x)` — garantido por ESLint no CI. Exceções só no bloco "legado" do `eslint.config.mjs`, que **só encolhe**.
- D11: telas e componentes novos são refinados com o skill **`frontend-design:frontend-design`**, com **bastante animação** e fiéis à identidade do mock: tokens de `globals.css` (tinta, papel, gema como único acento, mata, alerta só para erro/alergia), Bricolage Grotesque nos títulos, Instrument Sans na interface, animações `animate-entra`/`animate-entra-lado`/`animate-pop`/`animate-balanca`/`cascata()`, sempre em `transform`/`opacity`, respeitando `prefers-reduced-motion`.
- Contraste (axe roda em toda story, `a11y.test = 'error'`): texto secundário em fundo claro é `text-fumo` (4,55:1) — **nunca `text-musgo` em fundo claro** (2,1:1); `musgo`/`salvia` só sobre `tinta`.
- Nomes e props em português (`variante`, `aoFechar`, `rotulo`, `carregando`), como o projeto já faz.
- Textos exatos da spec: "Vamos começar pela sua conta", "Assim seu plano fica salvo e você entra de qualquer celular.", "Criar conta"/"Criando…", "Já tenho conta", "Que bom te ver de novo", "Esqueci minha senha", "Entrar", "Vamos recuperar seu acesso", "Enviar link", "Confira seu e-mail", "Voltar para entrar", "Crie uma senha nova", "Salvar senha", "Esse link expirou. Peça outro.", "Pedir outro link", "Senha nova salva. Entre com ela.", "Trocar senha", "Senha trocada.", "Sair desta conta", "Apagar minha conta e meus dados", "Apagar sua conta?", "Não dá para desfazer.", "Apagar tudo", "Sua conta foi apagada.", "Li e aceito o termo de uso dos meus dados de saúde", "Ler o termo", "8 ou mais, com letra e número".
- Mensagens de validação iguais às do backend: "Escreva seu nome.", "Confira o e-mail.", "Use 8 ou mais caracteres, com letra e número.", "Use no máximo 72 caracteres.", "As senhas não conferem.", "Para continuar, aceite o termo.", "A senha atual não confere.", "A senha não confere.", "E-mail ou senha incorretos.".

## Review Focus

1. **`?voltar=` malicioso** (`//evil.com`, `/\evil.com`, `/<TAB>/evil.com`, `https://evil.com`, `hoje` sem barra) → o destino depois do login é sempre interno; na dúvida, `/hoje` (teste em Task 4, `destino.test.ts`).
2. **Duplo envio** (toque duplo em "Entrar"/"Criar conta") → uma única requisição; o botão fica `aria-busy` e desabilitado enquanto envia (teste em Task 6, `EntrarTela.integration.test.tsx`).
3. **API fora do ar / sem rede** no login e no cadastro → mensagem "Sem conexão. Confira a internet e tente de novo." e o formulário volta a aceitar envio, nunca fica travado (teste em Task 6).
4. **Sessão expirada no meio do uso** (401 em `/me` numa tela do app com busca na URL) → vai para `/entrar?voltar=` com caminho **e** busca, e volta para lá depois do login (testes em Task 5 e Task 6).
5. **E-mail com `+` no link de redefinição** (`camila%2Btreino%40gmail.com`) → o e-mail chega intacto ao backend (`camila+treino@gmail.com`), sem virar espaço (teste em Task 7).

---

## Mapa de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `compose.yaml` | serviço `web` (imagem Playwright 1.63, rede do host, usuário 1000) para dev, testes e E2E |
| `vitest.config.mts`, `vitest.setup.ts`, `vitest.integration.setup.ts`, `vitest.shims.d.ts` | projetos `unit` (jsdom), `integration` (jsdom + MSW), `storybook` (Chromium) |
| `.storybook/{main.ts,preview.tsx,preview-head.html}` | Storybook 10: stories viram testes, axe, fontes, alternador de movimento |
| `eslint.config.mjs` | Next + TS + Storybook + regra D12 + bloco "legado" (encolhe a cada plano) |
| `src/mocks/{server.ts,handlers/*,fixtures/*}` | MSW compartilhado; `mock-data.ts` passa a morar aqui |
| `src/lib/mock-api.ts` | ex-`lib/api.ts` (mocks das telas ainda não migradas) — legado |
| `src/lib/api/{client,case,errors,auth}.ts` | cliente HTTP, conversão de chaves, `ApiError`, funções de conta |
| `src/lib/query-client.tsx` | `Providers` (QueryClient + Toaster) |
| `src/lib/navegar.ts` | recarregar a página em outra URL (sair/apagar conta) |
| `src/components/ui/{Button,Field,PasswordField,FormError,Sheet,Toaster}.tsx` (+ stories) | primitivos usados pelas telas de conta |
| `src/components/app/TelaCarregando.tsx` | tela de espera dos guardas |
| `src/features/auth/{schemas,destino,termo,hooks}.ts` | validação espelho do backend, destinos/redirect seguro, termo, hooks de Query |
| `src/features/auth/components/*` | `AuthGate`, `Visitante` (redirect de logado + aviso de conta apagada), `AuthScreen`, formulários, `TermoSheet`, `DeleteAccountSheet`, `ContaSection`, contêineres `*Tela` |
| `src/proxy.ts` | sem cookie de sessão → `/entrar?voltar=` |
| `src/app/(publico)/{cadastro,entrar,senha/esqueci,senha/redefinir}/page.tsx`, `src/app/(app)/perfil/configuracoes/senha/page.tsx` | páginas finas |
| `src/test/{renderizar.tsx,next-navigation.ts}` | utilitários de teste |
| `playwright.config.ts`, `e2e/*` | E2E-01 (parte de conta), E2E-02, E2E-10 |
| `.github/workflows/ci.yml` | jobs `frontend` e `e2e` |
| backend: `database/seeders/E2ESeeder.php` | contas fixas dos E2E |

---

### Task 1: Ferramentas de teste, Storybook, ESLint e a guarda D12

**Files:**
- Create: `compose.yaml`, `vitest.config.mts`, `vitest.setup.ts`, `vitest.integration.setup.ts`, `vitest.shims.d.ts`, `.storybook/main.ts`, `.storybook/preview.tsx`, `.storybook/preview-head.html`, `eslint.config.mjs`, `src/mocks/server.ts`, `src/mocks/handlers/index.ts`, `src/lib/format.test.ts`, `src/components/ui/Button.stories.tsx`
- Move: `src/lib/mock-data.ts` → `src/mocks/fixtures/mock-data.ts`; `src/lib/api.ts` → `src/lib/mock-api.ts`
- Modify: `package.json`, `.gitignore`, `src/app/globals.css`, imports em `src/lib/plan-store.tsx`, `src/app/onboarding/gerando/page.tsx`, `src/app/onboarding/pronto/page.tsx`, `src/app/(app)/nutri/page.tsx`, `src/app/(app)/dieta/[refeicao]/page.tsx`
- Modify (repo backend): `specs/00-fundacao/estrategia-de-testes.md`, `specs/08-design-system/spec.md`, `specs/README.md`, `specs/00-fundacao/arquitetura-frontend.md`

**Interfaces:**
- Consumes: nada.
- Produces:
  - Scripts: `npm run lint`, `npm run typecheck`, `npm test` (os 3 projetos), `npm run test:unit`, `npm run test:integration`, `npm run test:storybook`, `npm run storybook`, `npm run e2e`.
  - Comando padrão: `docker compose run --rm web <comando>`.
  - `src/mocks/server.ts` exporta `server` (MSW node) com `handlers` de `src/mocks/handlers/index.ts`.
  - Alias `@/` → `src/` em Next, Vitest e Storybook.
  - Legado: `src/lib/mock-api.ts` substitui `src/lib/api.ts` (a pasta `src/lib/api/` é do cliente real).

- [ ] **Step 1: Branch e container**

```bash
cd /home/alvez/atividade-extensionista/frontend
git switch main && git pull && git switch -c plano-02-fundacao-contas
```

`compose.yaml`:
```yaml
services:
  web:
    image: mcr.microsoft.com/playwright:v1.63.0-noble
    user: "1000:1000"
    working_dir: /app
    network_mode: host # enxerga a API (localhost:8000) e o Mailpit (localhost:8025) do repo backend
    environment:
      HOME: /tmp
      NEXT_TELEMETRY_DISABLED: "1"
      STORYBOOK_DISABLE_TELEMETRY: "1"
    volumes:
      - .:/app
    command: npm run dev
```

- [ ] **Step 2: Reinstalar dependências no Linux do container e adicionar as novas**

```bash
docker compose run --rm web bash -c "rm -rf node_modules && npm ci"
docker compose run --rm web npm i -D --save-exact \
  storybook@10.6.0 @storybook/nextjs-vite@10.6.0 @storybook/addon-vitest@10.6.0 \
  @storybook/addon-a11y@10.6.0 @storybook/addon-docs@10.6.0 eslint-plugin-storybook@10.6.0 \
  vitest@4.1.11 @vitest/browser-playwright@4.1.11 playwright@1.63.0 @playwright/test@1.63.0
docker compose run --rm web npm i -D vite@^8.3.1 jsdom@^26.1.0 @testing-library/react@^16.3.3 \
  @testing-library/dom@^10 @testing-library/user-event@^14.6.7 @testing-library/jest-dom@^6.9.1 \
  msw@^2.15.0 eslint@^9.39.5 eslint-config-next@16.3.5
docker compose run --rm web npm i @tanstack/react-query@^5.103.2
docker compose run --rm web npm pkg set \
  scripts.lint="eslint ." scripts.typecheck="tsc --noEmit" scripts.test="vitest run" \
  scripts.test:unit="vitest run --project unit" scripts.test:integration="vitest run --project integration" \
  scripts.test:storybook="vitest run --project storybook" \
  scripts.storybook="storybook dev -p 6006 --no-open" scripts.build-storybook="storybook build" \
  scripts.e2e="playwright test"
```
Expected: cada `npm i` termina com `added N packages`; `package.json` com os scripts.

Acrescentar ao `.gitignore`:
```
# testes
/storybook-static
/playwright-report
/test-results
/blob-report
```

- [ ] **Step 3: Configurar Vitest, MSW e Storybook**

`vitest.config.mts`:
```ts
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { storybookTest } from '@storybook/addon-vitest/vitest-plugin';
import { playwright } from '@vitest/browser-playwright';
import { defineConfig } from 'vitest/config';

const dirname = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
  resolve: { alias: { '@': path.join(dirname, 'src') } },
  test: {
    projects: [
      {
        extends: true,
        test: {
          name: 'unit',
          environment: 'jsdom',
          include: ['src/**/*.test.{ts,tsx}'],
          exclude: ['src/**/*.integration.test.{ts,tsx}'],
          setupFiles: ['./vitest.setup.ts'],
        },
      },
      {
        extends: true,
        test: {
          name: 'integration',
          environment: 'jsdom',
          include: ['src/**/*.integration.test.{ts,tsx}'],
          setupFiles: ['./vitest.setup.ts', './vitest.integration.setup.ts'],
        },
      },
      {
        extends: true,
        plugins: [storybookTest({ configDir: path.join(dirname, '.storybook') })],
        test: {
          name: 'storybook',
          browser: {
            enabled: true,
            headless: true,
            provider: playwright({}),
            instances: [{ browser: 'chromium' }],
          },
        },
      },
    ],
  },
});
```

`vitest.setup.ts`:
```ts
import '@testing-library/jest-dom/vitest';
```

`vitest.integration.setup.ts`:
```ts
import { afterAll, afterEach, beforeAll } from 'vitest';
import { server } from './src/mocks/server';

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());
```

`vitest.shims.d.ts`:
```ts
/// <reference types="@vitest/browser-playwright" />
```

`src/mocks/handlers/index.ts`:
```ts
import type { RequestHandler } from 'msw';

/** Handlers padrão de todas as integrações. Cada teste troca o que precisar com `server.use()`. */
export const handlers: RequestHandler[] = [];
```

`src/mocks/server.ts`:
```ts
import { setupServer } from 'msw/node';
import { handlers } from './handlers';

export const server = setupServer(...handlers);
```

`.storybook/main.ts`:
```ts
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import type { StorybookConfig } from '@storybook/nextjs-vite';
import { mergeConfig } from 'vite';

const dirname = path.dirname(fileURLToPath(import.meta.url));

const config: StorybookConfig = {
  stories: ['../src/**/*.stories.@(ts|tsx)'],
  addons: ['@storybook/addon-vitest', '@storybook/addon-a11y', '@storybook/addon-docs'],
  framework: '@storybook/nextjs-vite',
  staticDirs: ['../public'],
  core: { disableTelemetry: true },
  viteFinal: async (vite) => mergeConfig(vite, { resolve: { alias: { '@': path.resolve(dirname, '../src') } } }),
};

export default config;
```

`.storybook/preview-head.html`:
```html
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link
  rel="stylesheet"
  href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@500;600;700&family=Instrument+Sans:wght@400;500;600&display=swap"
/>
<style>
  /* No app, next/font define estas variáveis no <html>. */
  :root {
    --font-bricolage: 'Bricolage Grotesque';
    --font-instrument: 'Instrument Sans';
  }
</style>
```

`.storybook/preview.tsx`:
```tsx
import type { Preview } from '@storybook/nextjs-vite';
import '../src/app/globals.css';

const preview: Preview = {
  parameters: {
    layout: 'fullscreen',
    // axe roda em toda story; violação reprova o teste.
    a11y: { test: 'error' },
  },
  globalTypes: {
    movimento: {
      description: 'Simula prefers-reduced-motion',
      toolbar: {
        title: 'Movimento',
        items: [
          { value: 'normal', title: 'Movimento normal' },
          { value: 'reduzido', title: 'Movimento reduzido' },
        ],
        dynamicTitle: true,
      },
    },
  },
  initialGlobals: { movimento: 'normal' },
  decorators: [
    (Story, { globals }) => {
      document.documentElement.dataset.movimento = globals.movimento === 'reduzido' ? 'reduzido' : '';
      return (
        <div className="min-h-dvh bg-papel p-5 text-tinta">
          <Story />
        </div>
      );
    },
  ],
};

export default preview;
```

Em `src/app/globals.css`, logo depois do bloco `@media (prefers-reduced-motion: reduce) { … }`, acrescentar:
```css
/* Storybook: o seletor "Movimento reduzido" força o mesmo estado final. */
[data-movimento="reduzido"] *,
[data-movimento="reduzido"] *::before,
[data-movimento="reduzido"] *::after {
  animation-duration: 0.01ms !important;
  animation-delay: 0ms !important;
  animation-iteration-count: 1 !important;
  transition-duration: 0.01ms !important;
}
```

- [ ] **Step 4: Primeiro teste de cada projeto (devem falhar antes do Step 5 só pelo motivo certo)**

`src/lib/format.test.ts` (teste de fumaça do projeto `unit`, sobre código que já existe):
```ts
import { describe, expect, it } from 'vitest';
import * as formato from './format';

describe('format', () => {
  it('exporta funções de formatação', () => {
    expect(Object.values(formato).some((f) => typeof f === 'function')).toBe(true);
  });
});
```

`src/components/ui/Button.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { Button } from './Button';

const meta = {
  title: 'UI/Button',
  component: Button,
  args: { children: 'Criar conta', onClick: fn() },
} satisfies Meta<typeof Button>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Primaria: Story = {
  play: async ({ canvasElement, args }) => {
    await userEvent.click(within(canvasElement).getByRole('button', { name: 'Criar conta' }));
    await expect(args.onClick).toHaveBeenCalledOnce();
  },
};
```

Run: `docker compose run --rm web npm test`
Expected: PASS — `unit` 1 teste, `storybook (chromium)` 1 teste (`Primaria`). O projeto `integration` não tem arquivos ainda (Vitest avisa `No test files found` para ele e segue). Se o Vitest encerrar com erro por falta de arquivos, rode `docker compose run --rm web npx vitest run --project unit --project storybook` e registre isso no ledger — a Task 2 cria o primeiro teste de integração.

- [ ] **Step 5: ESLint com a regra D12 — ver a guarda falhar**

`eslint.config.mjs`:
```js
import { defineConfig, globalIgnores } from 'eslint/config';
import nextVitals from 'eslint-config-next/core-web-vitals';
import nextTs from 'eslint-config-next/typescript';
import storybook from 'eslint-plugin-storybook';

export default defineConfig([
  ...nextVitals,
  ...nextTs,
  ...storybook.configs['flat/recommended'],
  globalIgnores([
    '.next/**',
    'out/**',
    'build/**',
    'next-env.d.ts',
    'storybook-static/**',
    'playwright-report/**',
    'test-results/**',
  ]),
  // D12 — dado mockado só em stories, testes e MSW.
  {
    files: ['src/**/*.{ts,tsx}'],
    ignores: ['src/mocks/**', '**/*.stories.tsx', '**/*.test.{ts,tsx}'],
    rules: {
      'no-restricted-imports': [
        'error',
        {
          patterns: [
            {
              group: ['@/mocks/*', '**/mocks/**', '**/mock-data'],
              message: 'Dado mockado só em stories, testes e MSW. Use src/lib/api.',
            },
          ],
        },
      ],
    },
  },
  // LEGADO — telas do protótipo ainda ligadas ao mock. Cada plano de feature REMOVE daqui os
  // arquivos que migrar; o Plano 08 apaga os dois blocos. Nada novo entra nesta lista.
  {
    files: ['src/lib/mock-api.ts', 'src/app/onboarding/pronto/page.tsx', 'src/app/(app)/nutri/page.tsx'],
    rules: { 'no-restricted-imports': 'off' },
  },
  {
    files: [
      'src/app/(app)/dieta/\\[refeicao\\]/page.tsx',
      'src/app/(app)/nutri/page.tsx',
      'src/app/onboarding/gerando/page.tsx',
      'src/components/ui/Sheet.tsx',
      'src/components/ui/Toast.tsx',
      'src/lib/motion.ts',
      'src/lib/onboarding-store.tsx',
      'src/lib/plan-store.tsx',
    ],
    rules: { 'react-hooks/set-state-in-effect': 'warn', 'react-hooks/purity': 'warn' },
  },
]);
```

Run: `docker compose run --rm web npm run lint`
Expected: FAIL — `no-restricted-imports` em `src/lib/api.ts` (importa `./mock-data`): o arquivo ainda tem o nome antigo, fora da lista de legado.

- [ ] **Step 6: Mover os mocks e corrigir os imports**

```bash
mkdir -p src/mocks/fixtures
git mv src/lib/mock-data.ts src/mocks/fixtures/mock-data.ts
git mv src/lib/api.ts src/lib/mock-api.ts
sed -i 's|from "./types"|from "@/lib/types"|' src/mocks/fixtures/mock-data.ts
sed -i 's|from "./mock-data"|from "@/mocks/fixtures/mock-data"|' src/lib/mock-api.ts
sed -i 's|from "./api"|from "./mock-api"|' src/lib/plan-store.tsx
sed -i 's|from "@/lib/api"|from "@/lib/mock-api"|' src/app/onboarding/gerando/page.tsx "src/app/(app)/nutri/page.tsx" "src/app/(app)/dieta/[refeicao]/page.tsx"
sed -i 's|from "@/lib/mock-data"|from "@/mocks/fixtures/mock-data"|' src/app/onboarding/pronto/page.tsx "src/app/(app)/nutri/page.tsx"
grep -rn 'lib/mock-data\|from "@/lib/api"\|from "./api"' src || echo "imports ok"
```
Expected: `imports ok`.

- [ ] **Step 7: Lint, tipos e testes verdes; a guarda pega import proibido**

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test"`
Expected: lint com 0 erros (warnings do legado permitidos), `tsc` sem erros, testes PASS.

Prova da guarda (não commitar o arquivo):
```bash
printf 'import { mockProfile } from "@/mocks/fixtures/mock-data";\nexport const x = mockProfile;\n' > src/lib/proibido.ts
docker compose run --rm web npx eslint src/lib/proibido.ts; echo "exit=$?"
rm src/lib/proibido.ts
```
Expected: `error … Dado mockado só em stories, testes e MSW. Use src/lib/api.` e `exit=1`.

- [ ] **Step 8: Build de produção continua de pé**

Run: `docker compose run --rm web npm run build`
Expected: `✓ Compiled successfully` e a lista de rotas.

- [ ] **Step 9: Atualizar as specs (repo backend) e commitar os dois repos**

No repo backend (`cd /home/alvez/atividade-extensionista/backend && git switch main && git pull && git switch -c plano-02-front` — a mesma branch recebe as specs das Tasks 1 e 5 e o seeder da Task 9):
- `specs/00-fundacao/estrategia-de-testes.md` §2: trocar `**Storybook 9**` por `**Storybook 10**`; acrescentar abaixo da tabela: `Versões fixadas no Plano 02: Storybook 10.6, Vitest 4.1, Playwright 1.63 (mesma tag da imagem Docker \`mcr.microsoft.com/playwright:v1.63.0-noble\`, onde o front roda local e no CI).`
- `specs/08-design-system/spec.md`: `Storybook 9` → `Storybook 10` (linhas 23 e 160).
- `specs/README.md` (D10): `Storybook 9` → `Storybook 10`.
- `specs/00-fundacao/arquitetura-frontend.md` §1 item 6, ao fim: `Transição (Plano 02): o antigo \`lib/api.ts\` virou \`src/lib/mock-api.ts\` e, junto com as telas ainda não migradas, fica num bloco "legado" do \`eslint.config.mjs\` que cada plano de feature encolhe; a pasta \`src/lib/api/\` é o cliente real.`

```bash
cd /home/alvez/atividade-extensionista/backend
git add specs && git commit -m "docs(specs): Storybook 10, versões fixadas e legado do front

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
cd /home/alvez/atividade-extensionista/frontend
git add -A && git commit -m "chore: Storybook 10, Vitest, MSW, ESLint e guarda contra mocks (D12)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Cliente da API

**Files:**
- Create: `src/lib/api/errors.ts`, `src/lib/api/case.ts`, `src/lib/api/client.ts`, `src/mocks/handlers/auth.ts`, `src/mocks/fixtures/usuario.ts`
- Modify: `src/mocks/handlers/index.ts`
- Test: `src/lib/api/case.test.ts`, `src/lib/api/client.integration.test.ts`

**Interfaces:**
- Consumes: `server` (Task 1).
- Produces:
  - `class ApiError extends Error { status: number; code: string; fieldErrors: Record<string, string[]>; details: Record<string, unknown> }` — `code` `'NETWORK_ERROR'` e `status` `0` quando não houve resposta.
  - `MENSAGEM_SEM_CONEXAO`, `MENSAGEM_ERRO_SERVIDOR`, `primeirasMensagens(erros): Record<string, string>`, `comoApiError(e: unknown): ApiError`.
  - `api<T>(caminho: string, opcoes?: { method?: 'GET'|'POST'|'PUT'|'PATCH'|'DELETE'; body?: unknown; signal?: AbortSignal }): Promise<T>` — devolve o corpo inteiro em camelCase (`{ data, meta }`), `undefined` em 204.
  - `camelizar<T>(v: unknown): T`, `snakear(v: unknown): unknown`.
  - MSW: `API`, `url(caminho)`, `semSessao()`, `erroDaApi(status, code, message, extra?)`, `handlersAuth` (csrf-cookie 204 + `/me` 401); fixture `usuarioApi` (onboarding completo, snake_case) e `comOnboardingEm(etapa)`.

- [ ] **Step 1: Escrever os testes que devem falhar**

`src/lib/api/case.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { camelizar, snakear } from './case';

describe('conversão de chaves', () => {
  it('converte respostas snake_case em camelCase, em profundidade', () => {
    expect(camelizar({ next_step: 'objetivo', settings: { unit_system: 'metric' }, lista: [{ food_id: 1 }] })).toEqual({
      nextStep: 'objetivo',
      settings: { unitSystem: 'metric' },
      lista: [{ foodId: 1 }],
    });
  });

  it('converte corpos camelCase em snake_case sem tocar nos valores', () => {
    expect(snakear({ passwordConfirmation: 'senhaComCamelCase1', termsAccepted: true })).toEqual({
      password_confirmation: 'senhaComCamelCase1',
      terms_accepted: true,
    });
  });

  it('deixa null, datas em texto e números como estão', () => {
    expect(camelizar({ goal_weight_kg: null, created_at: '2026-09-23T10:00:00-03:00', age: 27 })).toEqual({
      goalWeightKg: null,
      createdAt: '2026-09-23T10:00:00-03:00',
      age: 27,
    });
  });
});
```

`src/mocks/fixtures/usuario.ts`:
```ts
/** Usuário como a API devolve (snake_case), com onboarding concluído. */
export const usuarioApi = {
  id: 7,
  name: 'Camila Réus',
  email: 'camila.reus@gmail.com',
  preferred_name: 'Camila',
  onboarding_completed: true,
  next_step: null as string | null,
  created_at: '2026-09-23T10:00:00-03:00',
  settings: { unit_system: 'metric' },
};

export const comOnboardingEm = (etapa: string) => ({ ...usuarioApi, onboarding_completed: false, next_step: etapa });
```

`src/mocks/handlers/auth.ts`:
```ts
import { http, HttpResponse } from 'msw';

export const API = 'http://localhost:8000';
export const url = (caminho: string) => `${API}/api/v1${caminho}`;

export const erroDaApi = (status: number, code: string, message: string, extra: Record<string, unknown> = {}) =>
  HttpResponse.json({ message, code, ...extra }, { status });

export const semSessao = () => erroDaApi(401, 'UNAUTHENTICATED', 'Sua sessão expirou. Entre de novo.');

/** Padrão: visitante sem sessão. */
export const handlersAuth = [
  http.get(`${API}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),
  http.get(url('/me'), semSessao),
];
```

`src/mocks/handlers/index.ts` (substituir inteiro):
```ts
import type { RequestHandler } from 'msw';
import { handlersAuth } from './auth';

/** Handlers padrão de todas as integrações. Cada teste troca o que precisar com `server.use()`. */
export const handlers: RequestHandler[] = [...handlersAuth];
```

`src/lib/api/client.integration.test.ts`:
```ts
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { API, erroDaApi, url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';

// O cookie CSRF é memorizado no módulo: cada teste pega um módulo novo.
let api: typeof import('./client').api;

beforeEach(async () => {
  vi.resetModules();
  ({ api } = await import('./client'));
  document.cookie = 'XSRF-TOKEN=token%3Dteste; path=/';
});

describe('api()', () => {
  it('lê com cookie de sessão e Accept JSON, e devolve chaves em camelCase', async () => {
    let pedido: Request | undefined;
    server.use(
      http.get(url('/me'), ({ request }) => {
        pedido = request;
        return HttpResponse.json({ data: { preferred_name: 'Camila', next_step: 'objetivo' } });
      }),
    );

    const corpo = await api<{ data: { preferredName: string; nextStep: string } }>('/me');

    expect(corpo.data).toEqual({ preferredName: 'Camila', nextStep: 'objetivo' });
    expect(pedido?.headers.get('accept')).toBe('application/json');
    expect(pedido?.credentials).toBe('include');
  });

  it('pede o cookie CSRF uma vez e manda X-XSRF-TOKEN e corpo em snake_case nas escritas', async () => {
    let pedidosCsrf = 0;
    const corpos: unknown[] = [];
    const tokens: (string | null)[] = [];
    server.use(
      http.get(`${API}/sanctum/csrf-cookie`, () => {
        pedidosCsrf++;
        return new HttpResponse(null, { status: 204 });
      }),
      http.post(url('/login'), async ({ request }) => {
        corpos.push(await request.json());
        tokens.push(request.headers.get('x-xsrf-token'));
        return HttpResponse.json({ data: {} });
      }),
    );

    await api('/login', { method: 'POST', body: { email: 'a@b.com', passwordConfirmation: 'x' } });
    await api('/login', { method: 'POST', body: { email: 'a@b.com' } });

    expect(pedidosCsrf).toBe(1);
    expect(corpos[0]).toEqual({ email: 'a@b.com', password_confirmation: 'x' });
    expect(tokens).toEqual(['token=teste', 'token=teste']);
  });

  it('renova o CSRF e repete uma vez quando a escrita volta 403', async () => {
    let pedidosCsrf = 0;
    let tentativas = 0;
    server.use(
      http.get(`${API}/sanctum/csrf-cookie`, () => {
        pedidosCsrf++;
        return new HttpResponse(null, { status: 204 });
      }),
      http.post(url('/logout'), () => {
        tentativas++;
        return tentativas === 1
          ? erroDaApi(403, 'FORBIDDEN', 'Não deu para concluir. Recarregue a página e tente de novo.')
          : new HttpResponse(null, { status: 204 });
      }),
    );

    await expect(api('/logout', { method: 'POST' })).resolves.toBeUndefined();
    expect(tentativas).toBe(2);
    expect(pedidosCsrf).toBe(2);
  });

  it('transforma o erro da API em ApiError com campos em camelCase', async () => {
    server.use(
      http.put(url('/me/password'), () =>
        erroDaApi(422, 'VALIDATION_ERROR', 'Confira os campos destacados.', {
          errors: { current_password: ['A senha atual não confere.'] },
        }),
      ),
    );

    await expect(api('/me/password', { method: 'PUT', body: {} })).rejects.toMatchObject({
      name: 'ApiError',
      status: 422,
      code: 'VALIDATION_ERROR',
      message: 'Confira os campos destacados.',
      fieldErrors: { currentPassword: ['A senha atual não confere.'] },
    });
  });

  it('traz os detalhes do erro em camelCase (429)', async () => {
    server.use(
      http.post(url('/login'), () =>
        erroDaApi(429, 'TOO_MANY_REQUESTS', 'Muitas tentativas seguidas. Tente de novo em 40 segundos.', {
          details: { retry_after: 40 },
        }),
      ),
    );

    await expect(api('/login', { method: 'POST', body: {} })).rejects.toMatchObject({
      code: 'TOO_MANY_REQUESTS',
      details: { retryAfter: 40 },
    });
  });

  it('vira NETWORK_ERROR quando não há resposta', async () => {
    server.use(http.get(url('/me'), () => HttpResponse.error()));

    await expect(api('/me')).rejects.toMatchObject({
      status: 0,
      code: 'NETWORK_ERROR',
      message: 'Sem conexão. Confira a internet e tente de novo.',
    });
  });

  it('usa a mensagem genérica quando o erro não vem em JSON', async () => {
    server.use(http.get(url('/me'), () => new HttpResponse('<h1>502</h1>', { status: 502 })));

    await expect(api('/me')).rejects.toMatchObject({
      status: 502,
      code: 'SERVER_ERROR',
      message: 'Algo deu errado do nosso lado. Tente de novo.',
    });
  });
});
```
(As asserções usam `toMatchObject` e não `instanceof ApiError` porque `vi.resetModules()` cria outra instância do módulo `errors`.)

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose run --rm web npx vitest run --project unit --project integration src/lib/api`
Expected: FAIL — `Failed to resolve import "./case"` e `"./client"`.

- [ ] **Step 3: Implementar**

`src/lib/api/errors.ts`:
```ts
/** Erro da API no formato único do backend (`specs/00-fundacao/api-convencoes-e-erros.md` §3). */
export type FieldErrors = Record<string, string[]>;

export const MENSAGEM_SEM_CONEXAO = 'Sem conexão. Confira a internet e tente de novo.';
export const MENSAGEM_ERRO_SERVIDOR = 'Algo deu errado do nosso lado. Tente de novo.';

export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly fieldErrors: FieldErrors = {},
    readonly details: Record<string, unknown> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

/** Primeira mensagem de cada campo, como os formulários mostram. */
export function primeirasMensagens(erros: FieldErrors): Record<string, string> {
  return Object.fromEntries(Object.entries(erros).map(([campo, mensagens]) => [campo, mensagens[0] ?? '']));
}

/** Garante um ApiError: o que não veio da API é tratado como falta de conexão. */
export function comoApiError(erro: unknown): ApiError {
  return erro instanceof ApiError ? erro : new ApiError(0, 'NETWORK_ERROR', MENSAGEM_SEM_CONEXAO);
}
```

`src/lib/api/case.ts`:
```ts
const paraCamel = (chave: string) => chave.replace(/_([a-z0-9])/g, (_, letra: string) => letra.toUpperCase());
const paraSnake = (chave: string) => chave.replace(/[A-Z]/g, (letra) => `_${letra.toLowerCase()}`);

function converter(valor: unknown, chave: (texto: string) => string): unknown {
  if (Array.isArray(valor)) return valor.map((item) => converter(item, chave));
  if (valor !== null && typeof valor === 'object' && Object.getPrototypeOf(valor) === Object.prototype) {
    return Object.fromEntries(Object.entries(valor).map(([k, v]) => [chave(k), converter(v, chave)]));
  }
  return valor;
}

/** Resposta da API (snake_case) → front (camelCase). Só as chaves mudam. */
export const camelizar = <T>(valor: unknown) => converter(valor, paraCamel) as T;

/** Corpo do front (camelCase) → API (snake_case). */
export const snakear = (valor: unknown) => converter(valor, paraSnake);
```

`src/lib/api/client.ts`:
```ts
import { camelizar, snakear } from './case';
import { ApiError, MENSAGEM_ERRO_SERVIDOR, MENSAGEM_SEM_CONEXAO } from './errors';

type Metodo = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export interface Opcoes {
  method?: Metodo;
  body?: unknown;
  signal?: AbortSignal;
}

interface CorpoDeErro {
  message?: string;
  code?: string;
  errors?: Record<string, string[]>;
  details?: Record<string, unknown>;
}

const base = () => process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000';

let cookieCsrf: Promise<void> | null = null;

/** Pede o cookie XSRF-TOKEN ao Sanctum uma vez; `renovar` força um novo. */
function garantirCsrf(renovar = false): Promise<void> {
  if (!cookieCsrf || renovar) {
    cookieCsrf = fetch(`${base()}/sanctum/csrf-cookie`, { credentials: 'include' }).then(
      () => undefined,
      (erro: unknown) => {
        cookieCsrf = null;
        throw erro;
      },
    );
  }
  return cookieCsrf;
}

function lerCookie(nome: string): string | null {
  if (typeof document === 'undefined') return null;
  const par = document.cookie.split('; ').find((cookie) => cookie.startsWith(`${nome}=`));
  return par ? decodeURIComponent(par.slice(nome.length + 1)) : null;
}

async function enviar(caminho: string, metodo: Metodo, opcoes: Opcoes): Promise<Response> {
  const escrita = metodo !== 'GET';
  if (escrita) await garantirCsrf();

  const headers: Record<string, string> = { Accept: 'application/json' };
  if (opcoes.body !== undefined) headers['Content-Type'] = 'application/json';
  const xsrf = lerCookie('XSRF-TOKEN');
  if (escrita && xsrf) headers['X-XSRF-TOKEN'] = xsrf;

  return fetch(`${base()}/api/v1${caminho}`, {
    method: metodo,
    headers,
    credentials: 'include',
    signal: opcoes.signal,
    body: opcoes.body === undefined ? undefined : JSON.stringify(snakear(opcoes.body)),
  });
}

async function interpretar<T>(resposta: Response): Promise<T> {
  if (resposta.status === 204) return undefined as T;

  const corpo: unknown = await resposta.json().catch(() => null);
  if (resposta.ok) return camelizar<T>(corpo);

  const erro = camelizar<CorpoDeErro>(corpo ?? {});
  throw new ApiError(
    resposta.status,
    erro.code ?? 'SERVER_ERROR',
    erro.message ?? MENSAGEM_ERRO_SERVIDOR,
    erro.errors ?? {},
    erro.details ?? {},
  );
}

/**
 * Única porta para a API (`specs/00-fundacao/arquitetura-frontend.md` §3): cookie de sessão,
 * CSRF, JSON e snake_case ↔ camelCase. Devolve o corpo inteiro (`{ data, meta }`).
 */
export async function api<T>(caminho: string, opcoes: Opcoes = {}): Promise<T> {
  const metodo = opcoes.method ?? 'GET';
  try {
    let resposta = await enviar(caminho, metodo, opcoes);
    // Token CSRF vencido (419 no Laravel, 403 FORBIDDEN na nossa API): renova e tenta uma vez.
    if (metodo !== 'GET' && resposta.status === 403) {
      await garantirCsrf(true);
      resposta = await enviar(caminho, metodo, opcoes);
    }
    return await interpretar<T>(resposta);
  } catch (erro) {
    if (erro instanceof ApiError || (erro instanceof DOMException && erro.name === 'AbortError')) throw erro;
    throw new ApiError(0, 'NETWORK_ERROR', MENSAGEM_SEM_CONEXAO);
  }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose run --rm web npx vitest run --project unit --project integration src/lib/api`
Expected: PASS (3 + 7 testes).

- [ ] **Step 5: Suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(api): cliente com sessão Sanctum, CSRF, conversão de chaves e ApiError

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Biblioteca — primitivos das telas de conta

**Files:**
- Modify: `src/components/ui/Button.tsx`, `src/components/ui/Field.tsx` (só a função `Field`), `src/components/ui/Sheet.tsx`, `src/components/ui/Button.stories.tsx`
- Create: `src/components/ui/PasswordField.tsx`, `src/components/ui/FormError.tsx`, `src/components/ui/Toaster.tsx`, `src/components/app/TelaCarregando.tsx`
- Test (stories com `play`): `src/components/ui/{Button,Field,PasswordField,FormError,Sheet,Toaster}.stories.tsx`, `src/components/app/TelaCarregando.stories.tsx`

**Interfaces:**
- Consumes: `ApiError`, `MENSAGEM_SEM_CONEXAO` (Task 2).
- Produces:
  - `Button` com `variante` `'primaria'|'contorno'|'contorno-escuro'|'texto'|'destrutiva'`, `carregando?: boolean`, `rotuloCarregando?: string`, `type` padrão `"button"`.
  - `Field` com `erro?`, `aviso?`, `acessorio?: ReactNode`, `ref` (React 19, via props), `aria-invalid` e `aria-describedby` automáticos (`{id}-ajuda`, `{id}-mensagem`).
  - `PasswordField` (mesmas props de `Field`, sem `type`/`sufixo`/`acessorio`) com botão "Mostrar senha"/"Ocultar senha" (`aria-pressed`).
  - `FormError({ erro: ApiError | string | null; aoTentarDeNovo?: () => void; focar?: boolean })` — `role="alert"`, foca ao aparecer (salvo `focar={false}`).
  - `Sheet` com `tom?: 'padrao' | 'destrutivo'`, `aria-labelledby`, foco preso, `Esc` fecha, foco devolvido a quem abriu.
  - `Toaster({ children })` + `useToast(): (aviso: { texto: string; acao?: { rotulo: string; onClick: () => void }; segundos?: number }) => void` (função estável).
  - `TelaCarregando()` — `role="status"`, `aria-busy`.
- Fora deste plano: stories de Chip, OptionRow, Toggle, Segmento, Rail, Steps etc. entram no plano da feature que tocar cada um (spec 08, §2).

- [ ] **Step 1: Escrever as stories (testes de componente) que devem falhar**

`src/components/ui/Button.stories.tsx` (substituir inteiro):
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { Button } from './Button';

const meta = {
  title: 'UI/Button',
  component: Button,
  args: { children: 'Criar conta', onClick: fn() },
} satisfies Meta<typeof Button>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Primaria: Story = {
  play: async ({ canvasElement, args }) => {
    const botao = within(canvasElement).getByRole('button', { name: 'Criar conta' });
    await expect(botao).toHaveAttribute('type', 'button');
    await userEvent.click(botao);
    await expect(args.onClick).toHaveBeenCalledOnce();
  },
};

export const Contorno: Story = { args: { variante: 'contorno' } };

export const ContornoEscuro: Story = {
  args: { variante: 'contorno-escuro', children: 'Já tenho conta' },
  decorators: [(Historia) => <div className="rounded-3xl bg-tinta p-5"><Historia /></div>],
};

export const Texto: Story = { args: { variante: 'texto', tamanho: 'media', children: 'Esqueci minha senha' } };

export const Destrutiva: Story = { args: { variante: 'destrutiva', children: 'Apagar tudo' } };

export const Desabilitado: Story = {
  args: { disabled: true },
  play: async ({ canvasElement, args }) => {
    const botao = within(canvasElement).getByRole('button', { name: 'Criar conta' });
    await expect(botao).toBeDisabled();
    await userEvent.click(botao, { pointerEventsCheck: 0 });
    await expect(args.onClick).not.toHaveBeenCalled();
  },
};

export const Carregando: Story = {
  args: { carregando: true, rotuloCarregando: 'Criando…' },
  play: async ({ canvasElement, args }) => {
    const botao = within(canvasElement).getByRole('button', { name: 'Criando…' });
    await expect(botao).toHaveAttribute('aria-busy', 'true');
    await expect(botao).toBeDisabled();
    await userEvent.click(botao, { pointerEventsCheck: 0 });
    await expect(args.onClick).not.toHaveBeenCalled();
  },
};

export const Tamanhos: Story = {
  render: (args) => (
    <div className="flex flex-col items-start gap-3">
      <Button {...args}>Grande</Button>
      <Button {...args} tamanho="media">Média</Button>
      <Button {...args} tamanho="pequena">Pequena</Button>
    </div>
  ),
};
```

`src/components/ui/Field.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, userEvent, within } from 'storybook/test';
import { Field } from './Field';

const meta = {
  title: 'UI/Field',
  component: Field,
  args: { id: 'email', label: 'E-mail', type: 'email' },
} satisfies Meta<typeof Field>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Vazio: Story = {
  play: async ({ canvasElement }) => {
    const campo = within(canvasElement).getByLabelText('E-mail');
    await userEvent.type(campo, 'camila@exemplo.com');
    await expect(campo).toHaveValue('camila@exemplo.com');
    await expect(campo).not.toHaveAttribute('aria-invalid');
  },
};

export const ComSufixo: Story = { args: { id: 'peso', label: 'Peso', sufixo: 'kg', inputMode: 'decimal', defaultValue: '58,4' } };

export const ComAjuda: Story = {
  args: { id: 'senha', label: 'Senha', type: 'password', ajuda: '8 ou mais, com letra e número' },
  play: async ({ canvasElement }) => {
    const campo = within(canvasElement).getByLabelText('Senha');
    await expect(campo).toHaveAttribute('aria-describedby', 'senha-ajuda');
  },
};

export const ComErro: Story = {
  args: { defaultValue: 'camila@', erro: 'Confira o e-mail.', ajuda: 'O mesmo que você usa no celular' },
  play: async ({ canvasElement }) => {
    const campo = within(canvasElement).getByLabelText('E-mail');
    await expect(campo).toHaveAttribute('aria-invalid', 'true');
    await expect(campo).toHaveAttribute('aria-describedby', 'email-ajuda email-mensagem');
    await expect(within(canvasElement).getByText('Confira o e-mail.')).toHaveAttribute('id', 'email-mensagem');
  },
};

export const ComAviso: Story = {
  args: { id: 'meta', label: 'Meta de peso', sufixo: 'kg', defaultValue: '45', aviso: 'Essa meta fica abaixo da faixa saudável para a sua altura.' },
};

export const Desabilitado: Story = { args: { disabled: true, defaultValue: 'camila@exemplo.com' } };
```

`src/components/ui/PasswordField.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, userEvent, within } from 'storybook/test';
import { PasswordField } from './PasswordField';

const meta = {
  title: 'UI/PasswordField',
  component: PasswordField,
  args: { id: 'senha', label: 'Senha', autoComplete: 'new-password', ajuda: '8 ou mais, com letra e número' },
} satisfies Meta<typeof PasswordField>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Alternar: Story = {
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    const campo = tela.getByLabelText('Senha');
    await userEvent.type(campo, 'senha1234');
    await expect(campo).toHaveAttribute('type', 'password');

    await userEvent.click(tela.getByRole('button', { name: 'Mostrar senha' }));
    await expect(campo).toHaveAttribute('type', 'text');
    await expect(tela.getByRole('button', { name: 'Ocultar senha' })).toHaveAttribute('aria-pressed', 'true');

    await userEvent.click(tela.getByRole('button', { name: 'Ocultar senha' }));
    await expect(campo).toHaveAttribute('type', 'password');
  },
};

export const ComErro: Story = { args: { erro: 'Use 8 ou mais caracteres, com letra e número.' } };
```

`src/components/ui/FormError.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, waitFor, within } from 'storybook/test';
import { ApiError, MENSAGEM_ERRO_SERVIDOR, MENSAGEM_SEM_CONEXAO } from '@/lib/api/errors';
import { FormError } from './FormError';

const meta = { title: 'UI/FormError', component: FormError } satisfies Meta<typeof FormError>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Rede: Story = {
  args: { erro: new ApiError(0, 'NETWORK_ERROR', MENSAGEM_SEM_CONEXAO) },
  play: async ({ canvasElement }) => {
    const alerta = within(canvasElement).getByRole('alert');
    await expect(alerta).toHaveTextContent(MENSAGEM_SEM_CONEXAO);
    await waitFor(() => expect(alerta).toHaveFocus());
  },
};

export const Servidor: Story = { args: { erro: new ApiError(500, 'SERVER_ERROR', MENSAGEM_ERRO_SERVIDOR) } };

export const Credenciais: Story = {
  args: { erro: new ApiError(422, 'INVALID_CREDENTIALS', 'E-mail ou senha incorretos.'), focar: false },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('alert')).not.toHaveFocus();
  },
};

export const MuitasTentativas: Story = {
  args: { erro: new ApiError(429, 'TOO_MANY_REQUESTS', 'Muitas tentativas seguidas. Tente de novo em 40 segundos.') },
};

export const ComTentarDeNovo: Story = {
  args: { erro: MENSAGEM_SEM_CONEXAO, aoTentarDeNovo: fn() },
  play: async ({ canvasElement, args }) => {
    await userEvent.click(within(canvasElement).getByRole('button', { name: 'Tentar de novo' }));
    await expect(args.aoTentarDeNovo).toHaveBeenCalledOnce();
  },
};

export const SemErro: Story = {
  args: { erro: null },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).queryByRole('alert')).toBeNull();
  },
};
```

`src/components/ui/Sheet.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, waitFor, within } from 'storybook/test';
import { Button } from './Button';
import { Sheet } from './Sheet';

const meta = {
  title: 'UI/Sheet',
  component: Sheet,
  args: {
    aberta: true,
    aoFechar: fn(),
    titulo: 'Trocar o arroz',
    descricao: 'Opções com a mesma energia.',
    children: (
      <div className="mt-5 flex flex-col gap-3">
        <Button variante="contorno">Batata-doce</Button>
        <Button variante="contorno">Cuscuz</Button>
      </div>
    ),
  },
} satisfies Meta<typeof Sheet>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Aberta: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    const dialogo = tela.getByRole('dialog', { name: 'Trocar o arroz' });
    await waitFor(() => expect(dialogo).toHaveFocus());

    await userEvent.tab();
    await expect(tela.getByRole('button', { name: 'Batata-doce' })).toHaveFocus();
    await userEvent.tab();
    await expect(tela.getByRole('button', { name: 'Cuscuz' })).toHaveFocus();
    await userEvent.tab(); // o foco volta ao começo, sem sair da folha
    await expect(tela.getByRole('button', { name: 'Batata-doce' })).toHaveFocus();

    await userEvent.keyboard('{Escape}');
    await expect(args.aoFechar).toHaveBeenCalled();
  },
};

export const Destrutiva: Story = {
  args: {
    tom: 'destrutivo',
    titulo: 'Apagar sua conta?',
    descricao: 'Isto apaga seus dados de vez.',
    children: <Button variante="destrutiva" className="mt-5">Apagar tudo</Button>,
  },
  play: async ({ canvasElement }) => {
    const titulo = within(canvasElement).getByRole('heading', { name: 'Apagar sua conta?' });
    await expect(titulo).toHaveClass('text-alerta');
  },
};

export const Fechada: Story = {
  args: { aberta: false },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).queryByRole('dialog')).toBeNull();
  },
};
```

`src/components/ui/Toaster.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { Button } from './Button';
import { Toaster, useToast } from './Toaster';

function Disparador({ comAcao }: { comAcao?: () => void }) {
  const avisar = useToast();
  return (
    <Button
      onClick={() =>
        avisar({ texto: 'Senha trocada.', acao: comAcao ? { rotulo: 'Desfazer', onClick: comAcao } : undefined })
      }
    >
      Avisar
    </Button>
  );
}

const meta = {
  title: 'UI/Toaster',
  component: Toaster,
  args: { children: null },
} satisfies Meta<typeof Toaster>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Aviso: Story = {
  render: () => (
    <Toaster>
      <Disparador />
    </Toaster>
  ),
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await userEvent.click(tela.getByRole('button', { name: 'Avisar' }));
    await expect(await tela.findByRole('status')).toHaveTextContent('Senha trocada.');
  },
};

const desfazer = fn();

export const ComDesfazer: Story = {
  render: () => (
    <Toaster>
      <Disparador comAcao={desfazer} />
    </Toaster>
  ),
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await userEvent.click(tela.getByRole('button', { name: 'Avisar' }));
    await userEvent.click(await tela.findByRole('button', { name: 'Desfazer' }));
    await expect(desfazer).toHaveBeenCalledOnce();
  },
};
```

`src/components/app/TelaCarregando.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, within } from 'storybook/test';
import { TelaCarregando } from './TelaCarregando';

const meta = { title: 'App/TelaCarregando', component: TelaCarregando } satisfies Meta<typeof TelaCarregando>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Padrao: Story = {
  play: async ({ canvasElement }) => {
    const estado = within(canvasElement).getByRole('status');
    await expect(estado).toHaveAttribute('aria-busy', 'true');
    await expect(estado).toHaveTextContent('Carregando…');
  },
};
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose run --rm web npm run test:storybook`
Expected: FAIL — imports de `./PasswordField`, `./FormError`, `./Toaster`, `./TelaCarregando` não resolvem; `Button` sem `carregando`/`destrutiva`; `Field` sem `erro`.

- [ ] **Step 3: Implementar**

`src/components/ui/Button.tsx` (substituir inteiro):
```tsx
import Link from "next/link";

type Variante = "primaria" | "contorno" | "contorno-escuro" | "texto" | "destrutiva";

const base =
  "group relative inline-flex select-none items-center justify-center gap-2 overflow-hidden rounded-full font-semibold transition-[transform,filter,background-color,border-color,box-shadow] duration-200 ease-[cubic-bezier(.22,1,.36,1)] active:scale-[0.97] disabled:pointer-events-none disabled:opacity-50 aria-busy:opacity-100";

const variantes: Record<Variante, string> = {
  primaria:
    "bg-gema text-tinta shadow-[0_1px_2px_rgba(21,37,28,.08)] hover:-translate-y-px hover:brightness-[.97] hover:shadow-[0_8px_20px_-10px_rgba(21,37,28,.5)]",
  contorno: "border-[1.5px] border-tinta text-tinta hover:bg-tinta/5",
  "contorno-escuro":
    "border-[1.5px] border-grafite text-neve hover:border-musgo hover:bg-neve/10",
  texto: "text-mata hover:text-tinta",
  destrutiva:
    "bg-alerta text-white shadow-[0_1px_2px_rgba(126,39,31,.12)] hover:-translate-y-px hover:brightness-[.95] hover:shadow-[0_8px_20px_-10px_rgba(126,39,31,.55)]",
};

const tamanhos = {
  grande: "h-[54px] w-full px-6 text-base",
  media: "h-11 px-4 text-sm",
  pequena: "h-9 px-3.5 text-[13px]",
} as const;

/** Brilho que atravessa o botão principal quando o ponteiro passa por cima. */
const lustro = (
  <span
    aria-hidden="true"
    className="pointer-events-none absolute inset-0 -translate-x-full bg-linear-100 from-transparent via-white/45 to-transparent transition-transform duration-700 ease-out group-hover:translate-x-full motion-reduce:hidden"
  />
);

interface Comum {
  variante?: Variante;
  tamanho?: keyof typeof tamanhos;
  className?: string;
  children: React.ReactNode;
}

export function Button({
  variante = "primaria",
  tamanho = "grande",
  carregando = false,
  rotuloCarregando,
  className = "",
  children,
  disabled,
  type = "button",
  ...resto
}: Comum & {
  carregando?: boolean;
  rotuloCarregando?: string;
} & React.ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      type={type}
      disabled={disabled || carregando}
      aria-busy={carregando || undefined}
      className={`${base} ${variantes[variante]} ${tamanhos[tamanho]} ${className}`}
      {...resto}
    >
      {variante === "primaria" && !carregando ? lustro : null}
      <span className="relative flex items-center gap-2">
        {carregando ? (
          <>
            <span
              aria-hidden="true"
              className="size-4 animate-girar rounded-full border-2 border-current border-r-transparent"
            />
            {rotuloCarregando ?? children}
          </>
        ) : (
          children
        )}
      </span>
    </button>
  );
}

export function ButtonLink({
  href,
  variante = "primaria",
  tamanho = "grande",
  className = "",
  children,
  ...resto
}: Comum & { href: string } & Omit<
    React.ComponentPropsWithoutRef<typeof Link>,
    "href" | "className" | "children"
  >) {
  return (
    <Link
      href={href}
      className={`${base} ${variantes[variante]} ${tamanhos[tamanho]} ${className}`}
      {...resto}
    >
      {variante === "primaria" ? lustro : null}
      <span className="relative flex items-center gap-2">{children}</span>
    </Link>
  );
}
```

Em `src/components/ui/Field.tsx`, substituir **só a função `Field`** (a `Segmento` continua igual) por:
```tsx
type PropsDoField = {
  id: string;
  label: string;
  sufixo?: string;
  ajuda?: string;
  erro?: string;
  aviso?: string;
  /** Elemento dentro da caixa, à direita (ex.: botão "Mostrar senha"). */
  acessorio?: React.ReactNode;
  style?: React.CSSProperties;
} & Omit<React.ComponentProps<"input">, "style">;

export function Field({
  id,
  label,
  sufixo,
  ajuda,
  erro,
  aviso,
  acessorio,
  className = "",
  style,
  ...resto
}: PropsDoField) {
  const idAjuda = ajuda ? `${id}-ajuda` : undefined;
  const idMensagem = erro || aviso ? `${id}-mensagem` : undefined;
  const descritoPor = [idAjuda, idMensagem].filter(Boolean).join(" ") || undefined;
  const folgaDireita = acessorio ? 92 : sufixo ? sufixo.length * 9 + 22 : undefined;

  return (
    <div className={className} style={style}>
      <label htmlFor={id} className="mb-[7px] block text-[12.5px] font-semibold text-fumo">
        {label}
      </label>
      <div className="relative">
        <input
          id={id}
          aria-invalid={erro ? true : undefined}
          aria-describedby={descritoPor}
          className={`h-[52px] w-full rounded-[14px] border bg-white px-4 text-base font-medium text-tinta transition placeholder:font-normal placeholder:text-musgo focus:outline-none disabled:bg-fio disabled:text-fumo ${
            erro
              ? "border-alerta shadow-[inset_0_0_0_1px_var(--color-alerta)]"
              : "border-linha focus:border-tinta focus:shadow-[inset_0_0_0_1px_var(--color-tinta)]"
          }`}
          style={folgaDireita ? { paddingRight: `${folgaDireita}px` } : undefined}
          {...resto}
        />
        {sufixo ? (
          <span className="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-sm text-fumo">
            {sufixo}
          </span>
        ) : null}
        {acessorio}
      </div>
      {ajuda ? (
        <p id={idAjuda} className="mt-2 text-[12.5px] leading-snug text-fumo">
          {ajuda}
        </p>
      ) : null}
      {erro ? (
        <p id={idMensagem} className="mt-2 animate-entra text-[12.5px] leading-snug font-medium text-alerta">
          {erro}
        </p>
      ) : aviso ? (
        <p id={idMensagem} className="mt-2 animate-entra text-[12.5px] leading-snug font-medium text-gema-texto">
          {aviso}
        </p>
      ) : null}
    </div>
  );
}
```

`src/components/ui/PasswordField.tsx`:
```tsx
"use client";

import { useState } from "react";
import { Field } from "./Field";

type Props = Omit<React.ComponentProps<typeof Field>, "type" | "sufixo" | "acessorio">;

/** Campo de senha com "Mostrar/Ocultar" (spec 08 — PasswordField). */
export function PasswordField(props: Props) {
  const [visivel, setVisivel] = useState(false);

  return (
    <Field
      {...props}
      type={visivel ? "text" : "password"}
      acessorio={
        <button
          type="button"
          aria-label={visivel ? "Ocultar senha" : "Mostrar senha"}
          aria-pressed={visivel}
          aria-controls={props.id}
          onClick={() => setVisivel((v) => !v)}
          disabled={props.disabled}
          className="absolute right-2 top-1/2 -translate-y-1/2 rounded-[10px] px-2.5 py-1.5 text-[12.5px] font-semibold text-mata transition-colors hover:bg-mata-fraca disabled:opacity-50"
        >
          {visivel ? "Ocultar" : "Mostrar"}
        </button>
      }
    />
  );
}
```

`src/components/ui/FormError.tsx`:
```tsx
"use client";

import { useEffect, useRef } from "react";
import { IconeSemConexao } from "@/components/icons";
import type { ApiError } from "@/lib/api/errors";

/** Erro geral de formulário (rede, 500, credenciais, limite), acima do botão. */
export function FormError({
  erro,
  aoTentarDeNovo,
  focar = true,
}: {
  erro: ApiError | string | null;
  aoTentarDeNovo?: () => void;
  focar?: boolean;
}) {
  const caixa = useRef<HTMLDivElement>(null);
  const texto = erro === null ? null : typeof erro === "string" ? erro : erro.message;

  useEffect(() => {
    if (texto && focar) caixa.current?.focus();
  }, [texto, focar]);

  if (!texto) return null;

  return (
    <div
      ref={caixa}
      role="alert"
      tabIndex={-1}
      className="mb-3 flex animate-balanca items-start gap-2.5 rounded-2xl bg-alerta-fraca px-4 py-3 outline-none"
    >
      <IconeSemConexao size={18} className="mt-px shrink-0 text-alerta" />
      <div className="flex-1">
        <p className="text-[13.5px] leading-snug font-medium text-alerta-texto">{texto}</p>
        {aoTentarDeNovo ? (
          <button
            type="button"
            onClick={aoTentarDeNovo}
            className="mt-1.5 text-[13px] font-semibold text-alerta-texto underline underline-offset-2"
          >
            Tentar de novo
          </button>
        ) : null}
      </div>
    </div>
  );
}
```

`src/components/ui/Sheet.tsx` (substituir inteiro):
```tsx
"use client";

import { useEffect, useId, useRef, useState } from "react";

const FOCAVEIS =
  'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Folha que sobe de baixo. Fica montada durante a saída para a animação
 * de fechamento terminar antes de sumir. Prende o foco e devolve a quem abriu.
 */
export function Sheet({
  aberta,
  aoFechar,
  titulo,
  descricao,
  tom = "padrao",
  children,
}: {
  aberta: boolean;
  aoFechar: () => void;
  titulo: string;
  descricao?: string;
  tom?: "padrao" | "destrutivo";
  children: React.ReactNode;
}) {
  const caixa = useRef<HTMLDivElement>(null);
  const idTitulo = useId();
  const idDescricao = useId();
  const [montada, setMontada] = useState(aberta);
  const [saindo, setSaindo] = useState(false);

  useEffect(() => {
    if (aberta) {
      setMontada(true);
      setSaindo(false);
      return;
    }
    if (!montada) return;
    setSaindo(true);
    const t = setTimeout(() => {
      setMontada(false);
      setSaindo(false);
    }, 240);
    return () => clearTimeout(t);
  }, [aberta, montada]);

  useEffect(() => {
    if (!aberta) return;
    const anterior = document.activeElement instanceof HTMLElement ? document.activeElement : null;

    const aoTeclar = (e: KeyboardEvent) => {
      if (e.key === "Escape") {
        aoFechar();
        return;
      }
      if (e.key !== "Tab" || !caixa.current) return;
      const focaveis = caixa.current.querySelectorAll<HTMLElement>(FOCAVEIS);
      if (focaveis.length === 0) {
        e.preventDefault();
        return;
      }
      const primeiro = focaveis[0];
      const ultimo = focaveis[focaveis.length - 1];
      const atual = document.activeElement;
      if (e.shiftKey && (atual === primeiro || atual === caixa.current)) {
        e.preventDefault();
        ultimo.focus();
      } else if (!e.shiftKey && atual === ultimo) {
        e.preventDefault();
        primeiro.focus();
      }
    };

    document.addEventListener("keydown", aoTeclar);
    caixa.current?.focus();
    return () => {
      document.removeEventListener("keydown", aoTeclar);
      anterior?.focus();
    };
  }, [aberta, aoFechar]);

  if (!montada) return null;

  return (
    <div className="fixed inset-0 z-50 mx-auto flex max-w-[430px] flex-col justify-end">
      <button
        type="button"
        aria-label="Fechar"
        tabIndex={-1}
        onClick={aoFechar}
        className={`absolute inset-0 bg-tinta/55 backdrop-blur-[2px] transition-opacity duration-240 active:scale-100 ${
          saindo ? "opacity-0" : "animate-fade opacity-100"
        }`}
      />
      <div
        ref={caixa}
        role="dialog"
        aria-modal="true"
        aria-labelledby={idTitulo}
        aria-describedby={descricao ? idDescricao : undefined}
        tabIndex={-1}
        className={`relative max-h-[86dvh] overflow-y-auto rounded-t-[26px] bg-white px-5 pt-2.5 pb-7 shadow-[0_-1px_2px_rgba(21,37,28,.06),0_-18px_44px_-14px_rgba(21,37,28,.38)] outline-none area-segura-baixo ${
          saindo ? "translate-y-full transition-transform duration-240 ease-in" : "animate-folha"
        }`}
      >
        <span className="mx-auto mb-4 block h-1 w-9 rounded-full bg-linha" />
        <h2
          id={idTitulo}
          className={`animate-entra font-display text-[22px] font-bold tracking-[-0.025em] ${
            tom === "destrutivo" ? "text-alerta" : ""
          }`}
        >
          {titulo}
        </h2>
        {descricao ? (
          <p
            id={idDescricao}
            className="mt-1.5 animate-entra text-[13.5px] leading-normal text-fumo"
            style={{ animationDelay: "60ms" }}
          >
            {descricao}
          </p>
        ) : null}
        {children}
      </div>
    </div>
  );
}
```
(O fundo "Fechar" sai da ordem de `Tab` — `tabIndex={-1}` — porque fica fora da caixa presa; continua clicável e o `Esc` fecha.)

`src/components/ui/Toaster.tsx`:
```tsx
"use client";

import { createContext, useCallback, useContext, useState } from "react";
import { Toast } from "./Toast";

export interface AvisoToast {
  texto: string;
  acao?: { rotulo: string; onClick: () => void };
  segundos?: number;
}

const Contexto = createContext<(aviso: AvisoToast) => void>(() => {});

/** Um aviso por vez, no topo da coluna do app; sobrevive à troca de página. */
export function Toaster({ children }: { children: React.ReactNode }) {
  const [atual, setAtual] = useState<(AvisoToast & { id: number }) | null>(null);
  const mostrar = useCallback((aviso: AvisoToast) => setAtual({ ...aviso, id: Date.now() }), []);
  const fechar = useCallback(() => setAtual(null), []);

  return (
    <Contexto.Provider value={mostrar}>
      {children}
      {atual ? (
        <div className="pointer-events-none fixed inset-x-0 top-0 z-[60] mx-auto max-w-[430px] pt-4 area-segura-cima">
          <div className="pointer-events-auto">
            <Toast
              key={atual.id}
              texto={atual.texto}
              acao={atual.acao}
              segundos={atual.segundos}
              aoExpirar={fechar}
            />
          </div>
        </div>
      ) : null}
    </Contexto.Provider>
  );
}

export const useToast = () => useContext(Contexto);
```

`src/components/app/TelaCarregando.tsx`:
```tsx
import { MarcaNutri } from "@/components/icons";
import { Screen } from "./Screen";

/** Espera curta enquanto os guardas confirmam a sessão. */
export function TelaCarregando() {
  return (
    <Screen>
      <div role="status" aria-busy="true" className="flex flex-1 flex-col items-center justify-center">
        <span className="animate-respira text-tinta">
          <MarcaNutri size={30} />
        </span>
        <span className="sr-only">Carregando…</span>
      </div>
    </Screen>
  );
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose run --rm web npm run test:storybook`
Expected: PASS em todas as stories (inclui o axe de cada uma). Se o axe apontar contraste, troque a cor do texto pelo token da regra de contraste das Global Constraints — não desligue o `a11y`.

- [ ] **Step 5: Refinar o visual com `frontend-design` (D11)**

Carregue o skill `frontend-design:frontend-design` e peça, para `Button`, `Field`, `PasswordField`, `FormError`, `Sheet` e `TelaCarregando`: microinterações generosas e fiéis ao mock (pressão, brilho da primária, entrada do erro com balanço, spinner da gema, folha com mola), **sem** mudar props, textos, `role`s, `aria-*` e ids usados pelas stories, só com tokens de `globals.css` e animações em `transform`/`opacity`. Depois rode de novo:

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm run test:storybook"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(ui): Button carregando/destrutiva, Field com erro, PasswordField, FormError, Sheet acessível e Toaster

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Domínio de conta — tipos, API, validação, destinos e provedores

**Files:**
- Modify: `src/lib/types.ts` (acrescentar tipos no fim), `src/app/layout.tsx`
- Create: `src/lib/api/auth.ts`, `src/lib/query-client.tsx`, `src/lib/navegar.ts`, `src/features/auth/schemas.ts`, `src/features/auth/destino.ts`, `src/features/auth/termo.ts`, `src/features/auth/hooks.ts`, `src/test/renderizar.tsx`, `src/test/next-navigation.ts`
- Test: `src/features/auth/schemas.test.ts`, `src/features/auth/destino.test.ts`

**Interfaces:**
- Consumes: `api`, `ApiError` (Task 2); `Toaster` (Task 3).
- Produces:
  - `type EtapaOnboarding = 'objetivo'|'dados'|'atividade'|'preferencias'|'restricoes'|'rotina'|'resumo'`; `interface User { id: number; name: string; email: string; preferredName: string; onboardingCompleted: boolean; nextStep: EtapaOnboarding | null; createdAt: string; settings?: { unitSystem: 'metric' | 'imperial' } }`.
  - `src/lib/api/auth.ts`: `register(e: EntradaCadastro): Promise<User>`, `login(e: { email: string; password: string }): Promise<User>`, `logout(): Promise<void>`, `getMe(): Promise<User>`, `forgotPassword(email: string): Promise<{ message: string }>`, `resetPassword(e: { token; email; password; passwordConfirmation }): Promise<{ message: string }>`, `updatePassword(e: { currentPassword; password; passwordConfirmation }): Promise<{ message: string }>`, `deleteAccount(password: string): Promise<void>`; `interface EntradaCadastro { name; email; password; passwordConfirmation; termsAccepted: boolean; termsVersion: string }`.
  - `schemas.ts`: `MENSAGENS`, `type Erros<C extends string>`, `erroEmail`, `erroSenhaNova`, `validarCadastro(d: DadosCadastro)`, `validarLogin(d: DadosLogin)`, `validarNovaSenha(d: DadosNovaSenha)`, `validarTrocaSenha(d: DadosTrocaSenha)`; `DadosCadastro = { name; email; password; termsAccepted: boolean }` (sem confirmação — ver Ruling na Task 6), `DadosLogin = { email; password }`, `DadosNovaSenha = { password; passwordConfirmation }`, `DadosTrocaSenha = DadosNovaSenha & { currentPassword }`.
  - `destino.ts`: `safeRedirect(voltar?: string | null): string | null`, `destinoAposEntrar(user, voltar?)`, `type Area = 'app' | 'onboarding'`, `destinoDoGuarda({ area, user, erro, caminho, busca }): string | null`.
  - `termo.ts`: `TERMO_VERSAO = '2026-09'`, `TERMO_PARAGRAFOS: string[]`.
  - `hooks.ts`: `CHAVE_ME`, `useMe()`, `useLogin()`, `useRegister()`, `useLogout()`, `useForgotPassword()`, `useResetPassword()`, `useUpdatePassword()`, `useDeleteAccount()`, `useRedirecionarSeLogado()`.
  - `Providers` (em `src/lib/query-client.tsx`) aplicado no `app/layout.tsx`; `criarQueryClient()`.
  - `recarregarEm(url: string): void` (`src/lib/navegar.ts`) — navegação com recarga (limpa todo o estado do cliente).
  - Teste: `renderizar(ui)` (QueryClient novo + Toaster) e o falso de `next/navigation` (`roteador`, `definirUrl`, `redefinirNavegacao`), usado com `vi.mock('next/navigation', () => import('@/test/next-navigation'))`.

- [ ] **Step 1: Escrever os testes que devem falhar**

`src/features/auth/schemas.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { MENSAGENS, validarCadastro, validarLogin, validarNovaSenha, validarTrocaSenha } from './schemas';

const cadastroValido = { name: 'Camila Réus', email: 'camila@exemplo.com', password: 'senha1234', termsAccepted: true };

describe('validarCadastro', () => {
  it('aceita um cadastro válido', () => {
    expect(validarCadastro(cadastroValido)).toEqual({});
  });

  it.each([
    ['nome curto', { name: ' C ' }, 'name', MENSAGENS.nome],
    ['nome longo', { name: 'a'.repeat(121) }, 'name', MENSAGENS.nome],
    ['e-mail sem domínio', { email: 'camila@' }, 'email', MENSAGENS.email],
    ['senha curta', { password: 'abc1' }, 'password', MENSAGENS.senhaFraca],
    ['senha sem número', { password: 'senhasenha' }, 'password', MENSAGENS.senhaFraca],
    ['senha sem letra', { password: '12345678' }, 'password', MENSAGENS.senhaFraca],
    ['senha com mais de 72', { password: 'a1'.repeat(37) }, 'password', MENSAGENS.senhaLonga],
    ['termo não aceito', { termsAccepted: false }, 'termsAccepted', MENSAGENS.termo],
  ])('%s', (_, troca, campo, mensagem) => {
    expect(validarCadastro({ ...cadastroValido, ...troca })).toEqual({ [campo]: mensagem });
  });

  it('aceita senha com letras acentuadas (RN02 conta qualquer alfabeto)', () => {
    expect(validarCadastro({ ...cadastroValido, password: 'ação12345' })).toEqual({});
  });
});

describe('validarLogin', () => {
  it('pede e-mail válido e senha', () => {
    expect(validarLogin({ email: '', password: '' })).toEqual({ email: MENSAGENS.email, password: MENSAGENS.senhaVazia });
  });
});

describe('validarNovaSenha', () => {
  it('confere a confirmação', () => {
    expect(validarNovaSenha({ password: 'senha1234', passwordConfirmation: 'senha12345' })).toEqual({
      passwordConfirmation: MENSAGENS.senhasDiferentes,
    });
  });
});

describe('validarTrocaSenha', () => {
  it('pede a senha atual', () => {
    expect(validarTrocaSenha({ currentPassword: '', password: 'senha1234', passwordConfirmation: 'senha1234' })).toEqual({
      currentPassword: MENSAGENS.senhaAtualVazia,
    });
  });
});
```

`src/features/auth/destino.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { ApiError } from '@/lib/api/errors';
import type { User } from '@/lib/types';
import { destinoAposEntrar, destinoDoGuarda, safeRedirect } from './destino';

const concluido: User = {
  id: 7,
  name: 'Camila Réus',
  email: 'camila@exemplo.com',
  preferredName: 'Camila',
  onboardingCompleted: true,
  nextStep: null,
  createdAt: '2026-09-23T10:00:00-03:00',
};
const pelaMetade: User = { ...concluido, onboardingCompleted: false, nextStep: 'atividade' };

describe('safeRedirect', () => {
  it.each(['/hoje', '/dieta/almoco?dia=2', '/perfil/configuracoes'])('aceita caminho interno %s', (voltar) => {
    expect(safeRedirect(voltar)).toBe(voltar);
  });

  it.each([null, undefined, '', 'hoje', '//evil.com', '/\\evil.com', '/\t/evil.com', 'https://evil.com', ' /hoje'])(
    'recusa %j',
    (voltar) => {
      expect(safeRedirect(voltar)).toBeNull();
    },
  );
});

describe('destinoAposEntrar', () => {
  it('manda para a etapa pendente, ignorando o voltar (CA03)', () => {
    expect(destinoAposEntrar(pelaMetade, '/dieta')).toBe('/onboarding/atividade');
  });

  it('volta para onde estava quando o onboarding está completo (CA09)', () => {
    expect(destinoAposEntrar(concluido, '/dieta/almoco')).toBe('/dieta/almoco');
  });

  it('cai no Hoje quando o voltar não é seguro', () => {
    expect(destinoAposEntrar(concluido, '//evil.com')).toBe('/hoje');
  });
});

describe('destinoDoGuarda', () => {
  const semSessao = new ApiError(401, 'UNAUTHENTICATED', 'Sua sessão expirou. Entre de novo.');

  it('sem sessão vai para o login levando caminho e busca', () => {
    expect(destinoDoGuarda({ area: 'app', erro: semSessao, caminho: '/dieta/almoco', busca: 'dia=2' })).toBe(
      '/entrar?voltar=%2Fdieta%2Falmoco%3Fdia%3D2',
    );
  });

  it('no app com onboarding pela metade vai para a etapa', () => {
    expect(destinoDoGuarda({ area: 'app', user: pelaMetade, caminho: '/hoje', busca: '' })).toBe('/onboarding/atividade');
  });

  it('no onboarding com conta concluída vai para o Hoje', () => {
    expect(destinoDoGuarda({ area: 'onboarding', user: concluido, caminho: '/onboarding/dados', busca: '' })).toBe('/hoje');
  });

  it('deixa editar o perfil pelo onboarding com ?editar=1', () => {
    expect(destinoDoGuarda({ area: 'onboarding', user: concluido, caminho: '/onboarding/dados', busca: 'editar=1' })).toBeNull();
  });

  it('deixa ver "gerando" e "pronto" depois de concluir', () => {
    expect(destinoDoGuarda({ area: 'onboarding', user: concluido, caminho: '/onboarding/gerando', busca: '' })).toBeNull();
    expect(destinoDoGuarda({ area: 'onboarding', user: concluido, caminho: '/onboarding/pronto', busca: '' })).toBeNull();
  });

  it('não decide enquanto carrega ou em erro que não é de sessão', () => {
    expect(destinoDoGuarda({ area: 'app', caminho: '/hoje', busca: '' })).toBeNull();
    expect(destinoDoGuarda({ area: 'app', erro: new ApiError(0, 'NETWORK_ERROR', 'x'), caminho: '/hoje', busca: '' })).toBeNull();
  });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose run --rm web npm run test:unit`
Expected: FAIL — `Failed to resolve import "./schemas"` e `"./destino"`.

- [ ] **Step 3: Implementar**

Acrescentar ao fim de `src/lib/types.ts`:
```ts
/** Etapas do onboarding, na ordem do fluxo (espelha `App\Enums\OnboardingStep`). */
export type EtapaOnboarding =
  | "objetivo"
  | "dados"
  | "atividade"
  | "preferencias"
  | "restricoes"
  | "rotina"
  | "resumo";

/** Conta autenticada, como `GET /me` devolve (já em camelCase). */
export interface User {
  id: number;
  name: string;
  email: string;
  preferredName: string;
  onboardingCompleted: boolean;
  nextStep: EtapaOnboarding | null;
  createdAt: string;
  settings?: { unitSystem: "metric" | "imperial" };
}
```

`src/lib/api/auth.ts`:
```ts
import type { User } from '@/lib/types';
import { api } from './client';

type Dados<T> = { data: T };
type Mensagem = { message: string };

export interface EntradaCadastro {
  name: string;
  email: string;
  password: string;
  passwordConfirmation: string;
  termsAccepted: boolean;
  termsVersion: string;
}

/** POST /register */
export const register = (entrada: EntradaCadastro) =>
  api<Dados<User>>('/register', { method: 'POST', body: entrada }).then((r) => r.data);

/** POST /login */
export const login = (entrada: { email: string; password: string }) =>
  api<Dados<User>>('/login', { method: 'POST', body: entrada }).then((r) => r.data);

/** POST /logout */
export const logout = () => api<void>('/logout', { method: 'POST' });

/** GET /me */
export const getMe = () => api<Dados<User>>('/me').then((r) => r.data);

/** POST /password/forgot — a resposta é sempre a mesma (RN04). */
export const forgotPassword = (email: string) => api<Mensagem>('/password/forgot', { method: 'POST', body: { email } });

/** POST /password/reset */
export const resetPassword = (entrada: { token: string; email: string; password: string; passwordConfirmation: string }) =>
  api<Mensagem>('/password/reset', { method: 'POST', body: entrada });

/** PUT /me/password */
export const updatePassword = (entrada: { currentPassword: string; password: string; passwordConfirmation: string }) =>
  api<Mensagem>('/me/password', { method: 'PUT', body: entrada });

/** DELETE /me */
export const deleteAccount = (password: string) => api<void>('/me', { method: 'DELETE', body: { password } });
```

`src/features/auth/schemas.ts`:
```ts
/** Validação no front, espelho das regras do backend (RN01, RN02; spec 01 §6). */
export const MENSAGENS = {
  nome: 'Escreva seu nome.',
  email: 'Confira o e-mail.',
  senhaFraca: 'Use 8 ou mais caracteres, com letra e número.',
  senhaLonga: 'Use no máximo 72 caracteres.',
  senhasDiferentes: 'As senhas não conferem.',
  senhaVazia: 'Digite sua senha.',
  senhaAtualVazia: 'Digite a senha atual.',
  termo: 'Para continuar, aceite o termo.',
} as const;

export type Erros<C extends string> = Partial<Record<C, string>>;

export interface DadosCadastro {
  name: string;
  email: string;
  password: string;
  termsAccepted: boolean;
}

export interface DadosLogin {
  email: string;
  password: string;
}

export interface DadosNovaSenha {
  password: string;
  passwordConfirmation: string;
}

export interface DadosTrocaSenha extends DadosNovaSenha {
  currentPassword: string;
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function limpar<C extends string>(erros: Erros<C>): Erros<C> {
  return Object.fromEntries(Object.entries(erros).filter(([, mensagem]) => mensagem)) as Erros<C>;
}

export function erroEmail(email: string): string | undefined {
  return EMAIL.test(email.trim()) ? undefined : MENSAGENS.email;
}

/** RN02 — 8 a 72 caracteres (contados como o backend conta), letra de qualquer alfabeto e número. */
export function erroSenhaNova(senha: string): string | undefined {
  const tamanho = [...senha].length;
  if (tamanho > 72) return MENSAGENS.senhaLonga;
  if (tamanho < 8 || !/\p{L}/u.test(senha) || !/\d/.test(senha)) return MENSAGENS.senhaFraca;
  return undefined;
}

export function validarCadastro(d: DadosCadastro): Erros<keyof DadosCadastro> {
  const nome = d.name.trim();
  return limpar({
    name: nome.length < 2 || nome.length > 120 ? MENSAGENS.nome : undefined,
    email: erroEmail(d.email),
    password: erroSenhaNova(d.password),
    termsAccepted: d.termsAccepted ? undefined : MENSAGENS.termo,
  });
}

export function validarLogin(d: DadosLogin): Erros<keyof DadosLogin> {
  return limpar({
    email: erroEmail(d.email),
    password: d.password ? undefined : MENSAGENS.senhaVazia,
  });
}

export function validarNovaSenha(d: DadosNovaSenha): Erros<keyof DadosNovaSenha> {
  return limpar({
    password: erroSenhaNova(d.password),
    passwordConfirmation: d.password !== d.passwordConfirmation ? MENSAGENS.senhasDiferentes : undefined,
  });
}

export function validarTrocaSenha(d: DadosTrocaSenha): Erros<keyof DadosTrocaSenha> {
  return limpar({
    currentPassword: d.currentPassword ? undefined : MENSAGENS.senhaAtualVazia,
    ...validarNovaSenha(d),
  });
}
```

`src/features/auth/destino.ts`:
```ts
import { ApiError } from '@/lib/api/errors';
import type { User } from '@/lib/types';

export type Area = 'app' | 'onboarding';

/** Telas do onboarding que continuam válidas depois de concluir (geração do plano). */
const ONBOARDING_POS_CONCLUSAO = ['/onboarding/gerando', '/onboarding/pronto'];

/**
 * `?voltar=` só aceita caminho interno (spec 01, N02): começa com uma barra só, sem barra
 * invertida nem espaço/controle (o navegador ignora TAB e trata `\` como `/`).
 */
export function safeRedirect(voltar?: string | null): string | null {
  if (!voltar || !voltar.startsWith('/') || voltar.startsWith('//')) return null;
  if (/[\s\\]/.test(voltar)) return null;
  return voltar;
}

/** Para onde ir depois de entrar ou criar a conta (RF02, CA03, CA09). */
export function destinoAposEntrar(user: Pick<User, 'onboardingCompleted' | 'nextStep'>, voltar?: string | null): string {
  if (!user.onboardingCompleted) return `/onboarding/${user.nextStep ?? 'objetivo'}`;
  return safeRedirect(voltar) ?? '/hoje';
}

/** Decisão dos guardas de layout; `null` = pode mostrar a tela. */
export function destinoDoGuarda({
  area,
  user,
  erro,
  caminho,
  busca,
}: {
  area: Area;
  user?: User;
  erro?: unknown;
  caminho: string;
  busca: string;
}): string | null {
  if (erro instanceof ApiError && erro.status === 401) {
    const atual = busca ? `${caminho}?${busca}` : caminho;
    return `/entrar?voltar=${encodeURIComponent(atual)}`;
  }
  if (!user) return null;
  if (area === 'app' && !user.onboardingCompleted) return `/onboarding/${user.nextStep ?? 'objetivo'}`;
  const editando = new URLSearchParams(busca).get('editar') === '1';
  if (area === 'onboarding' && user.onboardingCompleted && !editando && !ONBOARDING_POS_CONCLUSAO.includes(caminho)) {
    return '/hoje';
  }
  return null;
}
```

`src/features/auth/termo.ts`:
```ts
/** Versão vigente do termo (RN03) — igual a `config('prato.terms_version')` no backend. */
export const TERMO_VERSAO = '2026-09';

/**
 * Texto PROVISÓRIO do termo de uso dos dados de saúde.
 * A versão final é escrita pelos autores (pendência P3 em specs/99-inconsistencias.md);
 * trocou o texto de forma relevante? suba TERMO_VERSAO aqui e no backend.
 */
export const TERMO_PARAGRAFOS = [
  'O Prato Forte guarda seu peso, altura, idade, objetivo, restrições e alergias para montar e ajustar seu plano alimentar. São dados de saúde, e a LGPD pede o seu consentimento para usá-los.',
  'Ninguém da academia vê seus dados. Na pesquisa do projeto de extensão da UNINTER, usamos só números agregados e anônimos, sem nome nem e-mail.',
  'Para montar o cardápio, uma inteligência artificial recebe seus números e a lista de alimentos, sem o seu nome completo nem o seu e-mail. No chat, o Nutri recebe o nome como você prefere ser chamado.',
  'Você pode apagar sua conta e todos os seus dados quando quiser, em Perfil › Configurações.',
];
```

`src/features/auth/hooks.ts`:
```ts
'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRouter, useSearchParams } from 'next/navigation';
import { useEffect } from 'react';
import * as conta from '@/lib/api/auth';
import type { User } from '@/lib/types';
import { destinoAposEntrar } from './destino';

export const CHAVE_ME = ['me'] as const;

export function useMe() {
  return useQuery({ queryKey: CHAVE_ME, queryFn: conta.getMe, staleTime: 60_000 });
}

function useGuardarUsuario() {
  const cliente = useQueryClient();
  return (user: User) => cliente.setQueryData(CHAVE_ME, user);
}

export function useLogin() {
  const guardar = useGuardarUsuario();
  return useMutation({ mutationFn: conta.login, onSuccess: guardar });
}

export function useRegister() {
  const guardar = useGuardarUsuario();
  return useMutation({ mutationFn: conta.register, onSuccess: guardar });
}

/** Sair e apagar a conta terminam com recarga (`recarregarEm`), que limpa todo o cache. */
export const useLogout = () => useMutation({ mutationFn: conta.logout });
export const useDeleteAccount = () => useMutation({ mutationFn: conta.deleteAccount });
export const useForgotPassword = () => useMutation({ mutationFn: conta.forgotPassword });
export const useResetPassword = () => useMutation({ mutationFn: conta.resetPassword });
export const useUpdatePassword = () => useMutation({ mutationFn: conta.updatePassword });

/**
 * Quem já tem sessão não fica nas telas de visitante: vai para o app (ou a etapa pendente).
 * Também é o que navega depois de entrar/criar conta, porque as mutações gravam `['me']`.
 */
export function useRedirecionarSeLogado() {
  const { data: user } = useMe();
  const router = useRouter();
  const voltar = useSearchParams().get('voltar');

  useEffect(() => {
    if (user) router.replace(destinoAposEntrar(user, voltar));
  }, [user, voltar, router]);
}
```

`src/lib/query-client.tsx`:
```tsx
'use client';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useState } from 'react';
import { Toaster } from '@/components/ui/Toaster';
import { ApiError } from '@/lib/api/errors';

/** 4xx não se resolve tentando de novo; rede e 5xx tentam mais duas vezes. */
export function criarQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        retry: (falhas, erro) => !(erro instanceof ApiError && erro.status >= 400 && erro.status < 500) && falhas < 2,
        refetchOnWindowFocus: false,
      },
    },
  });
}

export function Providers({ children }: { children: React.ReactNode }) {
  const [cliente] = useState(criarQueryClient);
  return (
    <QueryClientProvider client={cliente}>
      <Toaster>{children}</Toaster>
    </QueryClientProvider>
  );
}
```

`src/lib/navegar.ts`:
```ts
/** Navega recarregando a página: descarta todo o estado do cliente (cache, formulários). */
export function recarregarEm(url: string): void {
  window.location.assign(url);
}
```

Em `src/app/layout.tsx`, importar `import { Providers } from "@/lib/query-client";` e trocar o `<body>` por:
```tsx
      <body className="min-h-dvh bg-papel">
        <Providers>{children}</Providers>
      </body>
```

`src/test/renderizar.tsx`:
```tsx
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render } from '@testing-library/react';
import { Toaster } from '@/components/ui/Toaster';

/** Renderiza com um QueryClient novo (sem novas tentativas) e o Toaster, como no app. */
export function renderizar(ui: React.ReactElement) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return {
    cliente,
    ...render(
      <QueryClientProvider client={cliente}>
        <Toaster>{ui}</Toaster>
      </QueryClientProvider>,
    ),
  };
}
```

`src/test/next-navigation.ts`:
```ts
import { vi } from 'vitest';

/**
 * Falso de `next/navigation` para testes de integração:
 *   vi.mock('next/navigation', () => import('@/test/next-navigation'));
 */
export const roteador = {
  replace: vi.fn(),
  push: vi.fn(),
  back: vi.fn(),
  forward: vi.fn(),
  refresh: vi.fn(),
  prefetch: vi.fn(),
};

let atual = new URL('http://localhost:3000/');

export const useRouter = () => roteador;
export const usePathname = () => atual.pathname;
export const useSearchParams = () => atual.searchParams;

/** Define a URL que os componentes enxergam (caminho + busca). */
export function definirUrl(caminho: string) {
  atual = new URL(caminho, 'http://localhost:3000');
}

export function redefinirNavegacao() {
  Object.values(roteador).forEach((funcao) => funcao.mockReset());
  definirUrl('/');
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose run --rm web npm run test:unit`
Expected: PASS.

- [ ] **Step 5: Suíte, lint, build e commit**

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm run build"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(auth): API de conta, hooks, validação espelho, destinos seguros e provedores

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Guardas — `proxy.ts`, layouts autenticados e Boas-vindas

**Files:**
- Create: `src/proxy.ts`, `src/features/auth/components/AuthGate.tsx`, `src/features/auth/components/Visitante.tsx`
- Modify: `src/app/(app)/layout.tsx`, `src/app/onboarding/layout.tsx`, `src/app/page.tsx`
- Modify (repo backend): `specs/00-fundacao/autenticacao-autorizacao.md` §4
- Test: `src/proxy.test.ts`, `src/features/auth/components/AuthGate.integration.test.tsx`, `src/features/auth/components/Visitante.integration.test.tsx`

**Interfaces:**
- Consumes: `useMe`, `useRedirecionarSeLogado`, `destinoDoGuarda` (Task 4); `TelaCarregando`, `useToast` (Task 3); MSW `url`, `semSessao`, `usuarioApi`, `comOnboardingEm` (Task 2); `renderizar`, falso de `next/navigation` (Task 4).
- Produces:
  - `proxy(request: NextRequest): NextResponse` e `config.matcher` para `/hoje`, `/dieta`, `/nutri`, `/evolucao`, `/perfil`, `/onboarding` (e subcaminhos).
  - `AuthGate({ area: Area; children })` — carrega `/me`; 401 → `/entrar?voltar=`; regras de `destinoDoGuarda`; erro de rede → `ErrorState` com "Tentar de novo".
  - `RedirecionarSeLogado()` (retorna `null`) e `AvisoDeSaida()` (lê `?conta=apagada`, mostra "Sua conta foi apagada." e limpa a URL).

- [ ] **Step 1: Escrever os testes que devem falhar**

`src/proxy.test.ts`:
```ts
// @vitest-environment node
import { NextRequest } from 'next/server';
import { describe, expect, it } from 'vitest';
import { config, proxy } from './proxy';

const pedido = (caminho: string, cookie?: string) =>
  new NextRequest(new URL(caminho, 'http://localhost:3000'), { headers: cookie ? { cookie } : {} });

describe('proxy', () => {
  it('sem cookie de sessão manda para o login guardando caminho e busca', () => {
    const resposta = proxy(pedido('/dieta/almoco?dia=2'));

    expect(resposta.status).toBe(307);
    expect(resposta.headers.get('location')).toBe('http://localhost:3000/entrar?voltar=%2Fdieta%2Falmoco%3Fdia%3D2');
  });

  it('com cookie de sessão deixa passar (quem decide é a API)', () => {
    const resposta = proxy(pedido('/hoje', 'prato-forte-session=abc'));

    expect(resposta.headers.get('location')).toBeNull();
    expect(resposta.headers.get('x-middleware-next')).toBe('1');
  });

  it('só vigia as áreas do app', () => {
    expect(config.matcher).toEqual([
      '/hoje/:path*',
      '/dieta/:path*',
      '/nutri/:path*',
      '/evolucao/:path*',
      '/perfil/:path*',
      '/onboarding/:path*',
    ]);
  });
});
```

`src/features/auth/components/AuthGate.integration.test.tsx`:
```tsx
import { screen, waitFor } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { comOnboardingEm, usuarioApi } from '@/mocks/fixtures/usuario';
import { url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { definirUrl, redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { AuthGate } from './AuthGate';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

const comUsuario = (usuario: object) => server.use(http.get(url('/me'), () => HttpResponse.json({ data: usuario })));

beforeEach(() => redefinirNavegacao());

describe('AuthGate', () => {
  it('sessão expirada manda para o login com caminho e busca (CA09)', async () => {
    definirUrl('/dieta/almoco?dia=2');

    renderizar(<AuthGate area="app"><p>conteúdo</p></AuthGate>);

    await waitFor(() => expect(roteador.replace).toHaveBeenCalledWith('/entrar?voltar=%2Fdieta%2Falmoco%3Fdia%3D2'));
    expect(screen.queryByText('conteúdo')).not.toBeInTheDocument();
  });

  it('no app com onboarding pela metade vai para a etapa', async () => {
    comUsuario(comOnboardingEm('dados'));
    definirUrl('/hoje');

    renderizar(<AuthGate area="app"><p>conteúdo</p></AuthGate>);

    await waitFor(() => expect(roteador.replace).toHaveBeenCalledWith('/onboarding/dados'));
  });

  it('mostra o app para quem concluiu o onboarding', async () => {
    comUsuario(usuarioApi);
    definirUrl('/hoje');

    renderizar(<AuthGate area="app"><p>conteúdo</p></AuthGate>);

    expect(await screen.findByText('conteúdo')).toBeInTheDocument();
    expect(roteador.replace).not.toHaveBeenCalled();
  });

  it('no onboarding, conta concluída vai para o Hoje', async () => {
    comUsuario(usuarioApi);
    definirUrl('/onboarding/objetivo');

    renderizar(<AuthGate area="onboarding"><p>etapa</p></AuthGate>);

    await waitFor(() => expect(roteador.replace).toHaveBeenCalledWith('/hoje'));
  });

  it('sem rede mostra o erro com "Tentar de novo" em vez de mandar para o login', async () => {
    server.use(http.get(url('/me'), () => HttpResponse.error()));
    definirUrl('/hoje');

    renderizar(<AuthGate area="app"><p>conteúdo</p></AuthGate>);

    expect(await screen.findByRole('button', { name: 'Tentar de novo' })).toBeInTheDocument();
    expect(roteador.replace).not.toHaveBeenCalled();
  });
});
```

`src/features/auth/components/Visitante.integration.test.tsx`:
```tsx
import { screen, waitFor } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { usuarioApi } from '@/mocks/fixtures/usuario';
import { url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { definirUrl, redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { AvisoDeSaida, RedirecionarSeLogado } from './Visitante';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => redefinirNavegacao());

describe('telas de visitante', () => {
  it('quem já está logado vai direto para o app', async () => {
    server.use(http.get(url('/me'), () => HttpResponse.json({ data: usuarioApi })));

    renderizar(<RedirecionarSeLogado />);

    await waitFor(() => expect(roteador.replace).toHaveBeenCalledWith('/hoje'));
  });

  it('visitante fica onde está', async () => {
    renderizar(<RedirecionarSeLogado />);

    await new Promise((fim) => setTimeout(fim, 50));
    expect(roteador.replace).not.toHaveBeenCalled();
  });

  it('avisa que a conta foi apagada e limpa a URL', async () => {
    definirUrl('/?conta=apagada');

    renderizar(<AvisoDeSaida />);

    expect(await screen.findByText('Sua conta foi apagada.')).toBeInTheDocument();
    expect(roteador.replace).toHaveBeenCalledWith('/');
  });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose run --rm web npx vitest run --project unit --project integration src/proxy.test.ts src/features/auth/components`
Expected: FAIL — `Failed to resolve import "./proxy"`, `"./AuthGate"`, `"./Visitante"`.

- [ ] **Step 3: Implementar**

`src/proxy.ts`:
```ts
import { NextResponse, type NextRequest } from 'next/server';

/** Cookie de sessão do Laravel (`Str::slug(APP_NAME).'-session'`). */
const COOKIE_SESSAO = process.env.SESSION_COOKIE_NAME ?? 'prato-forte-session';

/**
 * Primeira barreira de UX (spec 00, autenticação §4): sem cookie de sessão, nem abre a tela.
 * Com cookie, deixa passar — a autoridade é `GET /me` no `AuthGate`.
 */
export function proxy(request: NextRequest) {
  if (request.cookies.has(COOKIE_SESSAO)) return NextResponse.next();

  const login = new URL('/entrar', request.url);
  login.searchParams.set('voltar', request.nextUrl.pathname + request.nextUrl.search);
  return NextResponse.redirect(login);
}

export const config = {
  matcher: [
    '/hoje/:path*',
    '/dieta/:path*',
    '/nutri/:path*',
    '/evolucao/:path*',
    '/perfil/:path*',
    '/onboarding/:path*',
  ],
};
```

`src/features/auth/components/AuthGate.tsx`:
```tsx
'use client';

import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useEffect } from 'react';
import { ErrorState } from '@/components/app/ErrorState';
import { Screen } from '@/components/app/Screen';
import { TelaCarregando } from '@/components/app/TelaCarregando';
import { type Area, destinoDoGuarda } from '../destino';
import { useMe } from '../hooks';

/** Guarda dos layouts autenticados: sessão, onboarding e destino (RN07). */
export function AuthGate({ area, children }: { area: Area; children: React.ReactNode }) {
  const { data: user, error, isPending, refetch } = useMe();
  const router = useRouter();
  const caminho = usePathname();
  const busca = useSearchParams().toString();
  const destino = destinoDoGuarda({ area, user, erro: error, caminho, busca });

  useEffect(() => {
    if (destino) router.replace(destino);
  }, [destino, router]);

  if (isPending || destino) return <TelaCarregando />;

  if (error) {
    return (
      <Screen>
        <ErrorState
          titulo="Não deu para abrir o app"
          descricao={error.message}
          aoTentarDeNovo={() => void refetch()}
        />
      </Screen>
    );
  }

  return <>{children}</>;
}
```

`src/features/auth/components/Visitante.tsx`:
```tsx
'use client';

import { useRouter, useSearchParams } from 'next/navigation';
import { useEffect } from 'react';
import { useToast } from '@/components/ui/Toaster';
import { useRedirecionarSeLogado } from '../hooks';

/** Nas telas de visitante: quem já tem sessão vai para o app. Não desenha nada. */
export function RedirecionarSeLogado() {
  useRedirecionarSeLogado();
  return null;
}

/** Depois de apagar a conta (recarga em `/?conta=apagada`): avisa e limpa a URL. */
export function AvisoDeSaida() {
  const apagada = useSearchParams().get('conta') === 'apagada';
  const avisar = useToast();
  const router = useRouter();

  useEffect(() => {
    if (!apagada) return;
    avisar({ texto: 'Sua conta foi apagada.' });
    router.replace('/');
  }, [apagada, avisar, router]);

  return null;
}
```

`src/app/(app)/layout.tsx` (substituir inteiro):
```tsx
import { Suspense } from "react";
import { TelaCarregando } from "@/components/app/TelaCarregando";
import { AuthGate } from "@/features/auth/components/AuthGate";
import { PlanProvider } from "@/lib/plan-store";

export default function AppLayout({ children }: { children: React.ReactNode }) {
  return (
    <Suspense fallback={<TelaCarregando />}>
      <AuthGate area="app">
        <PlanProvider>{children}</PlanProvider>
      </AuthGate>
    </Suspense>
  );
}
```

`src/app/onboarding/layout.tsx` (substituir inteiro):
```tsx
import { Suspense } from "react";
import { TelaCarregando } from "@/components/app/TelaCarregando";
import { AuthGate } from "@/features/auth/components/AuthGate";
import { OnboardingProvider } from "@/lib/onboarding-store";

export default function OnboardingLayout({ children }: { children: React.ReactNode }) {
  return (
    <Suspense fallback={<TelaCarregando />}>
      <AuthGate area="onboarding">
        <OnboardingProvider>{children}</OnboardingProvider>
      </AuthGate>
    </Suspense>
  );
}
```

Em `src/app/page.tsx` (Boas-vindas, S01):
1. Imports: trocar `import Link from "next/link";` por
   ```tsx
   import { Suspense } from "react";
   import { AvisoDeSaida, RedirecionarSeLogado } from "@/features/auth/components/Visitante";
   ```
2. `<ButtonLink href="/onboarding/objetivo">` → `<ButtonLink href="/cadastro">`.
3. `<ButtonLink href="/hoje" variante="contorno-escuro" …>` → `href="/entrar"`.
4. Substituir o bloco `<Link href="/hoje" className="sr-only">Pular para o app</Link>` (contornava a autenticação) por:
   ```tsx
      <Suspense fallback={null}>
        <RedirecionarSeLogado />
        <AvisoDeSaida />
      </Suspense>
   ```

No repo backend, em `specs/00-fundacao/autenticacao-autorizacao.md` §4, trocar a linha "Rotas públicas: … vai para `/hoje` (ou para a etapa pendente)." por:
```markdown
- Rotas públicas: `/`, `/cadastro`, `/entrar`, `/senha/*`. Usuário logado que abre `/`, `/cadastro` ou `/entrar` vai para `/hoje` (ou para a etapa pendente) — decidido **no cliente** por `GET /me` (`RedirecionarSeLogado`), não no `proxy.ts`: depois do logout o Laravel mantém um cookie de sessão anônima, então a simples presença do cookie não prova login e o `proxy` criaria um laço `/entrar` ↔ `/hoje`.
```

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose run --rm web npx vitest run --project unit --project integration src/proxy.test.ts src/features/auth/components`
Expected: PASS.

- [ ] **Step 5: Suíte, lint, build e commits**

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm run build"`
Expected: tudo verde; o build lista `ƒ Proxy (Middleware)` (ou equivalente) e as rotas.

```bash
git add -A && git commit -m "feat(auth): proxy.ts, guardas de sessão nos layouts e Boas-vindas sem atalho para o app

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
cd /home/alvez/atividade-extensionista/backend && git add specs && git commit -m "docs(specs): redirect de visitante logado decidido no cliente

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" && cd /home/alvez/atividade-extensionista/frontend
```

---

### Task 6: Telas Criar conta (N01) e Entrar (N02)

**Files:**
- Create: `src/features/auth/components/AuthScreen.tsx`, `TermoSheet.tsx`, `RegisterForm.tsx`, `LoginForm.tsx`, `CadastroTela.tsx`, `EntrarTela.tsx`
- Create: `src/app/(publico)/template.tsx`, `src/app/(publico)/cadastro/page.tsx`, `src/app/(publico)/entrar/page.tsx`
- Test: `src/features/auth/components/RegisterForm.stories.tsx`, `LoginForm.stories.tsx`, `CadastroTela.integration.test.tsx`, `EntrarTela.integration.test.tsx`

**Interfaces:**
- Consumes: Tasks 2–4 (`ApiError`, `comoApiError`, `primeirasMensagens`, `Button`, `Field`, `PasswordField`, `FormError`, `OptionRow`, `Sheet`, `validarCadastro`, `validarLogin`, `TERMO_*`, `useRegister`, `useLogin`, `useRedirecionarSeLogado`).
- Produces:
  - `AuthScreen({ voltarPara, rotuloVoltar?, titulo, texto?, children })` — casca das telas de conta (reusada nas Tasks 7 e 8).
  - `RegisterForm({ aoEnviar: (d: DadosCadastro) => Promise<unknown> })`, `LoginForm({ aoEnviar: (d: DadosLogin) => Promise<unknown>; emailInicial?: string })` — formulários apresentacionais: validam, mostram `aria-busy`, mapeiam 422 para os campos e o resto para `FormError`.
  - `CadastroTela()`, `EntrarTela()` — contêineres.
  - Rotas `/cadastro` e `/entrar` (esta aceita `?voltar=` e `?email=`).
- Ruling já tomado neste plano: o N01 da spec não tem campo "confirmar senha" (wireframe), mas a API exige `password_confirmation` — o contêiner envia `passwordConfirmation = password` (o `PasswordField` com "Mostrar" cobre o erro de digitação). Registrar no ledger ao executar.

- [ ] **Step 1: Escrever stories e testes de integração que devem falhar**

`src/features/auth/components/RegisterForm.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { ApiError, MENSAGEM_SEM_CONEXAO } from '@/lib/api/errors';
import { RegisterForm } from './RegisterForm';

type Tela = ReturnType<typeof within>;

async function preencher(tela: Tela) {
  await userEvent.type(tela.getByLabelText('Nome completo'), 'Camila Réus');
  await userEvent.type(tela.getByLabelText('E-mail'), 'camila.reus@gmail.com');
  await userEvent.type(tela.getByLabelText('Senha'), 'senha1234');
  await userEvent.click(tela.getByRole('checkbox', { name: /Li e aceito/ }));
}

const meta = {
  title: 'Conta/RegisterForm',
  component: RegisterForm,
  args: { aoEnviar: fn(async () => undefined) },
} satisfies Meta<typeof RegisterForm>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Vazio: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await userEvent.click(tela.getByRole('button', { name: 'Criar conta' }));
    await expect(tela.getByText('Escreva seu nome.')).toBeVisible();
    await expect(tela.getByText('Confira o e-mail.')).toBeVisible();
    await expect(tela.getByText('Use 8 ou mais caracteres, com letra e número.')).toBeVisible();
    await expect(tela.getByText('Para continuar, aceite o termo.')).toBeVisible();
    await expect(args.aoEnviar).not.toHaveBeenCalled();
  },
};

export const Preenchido: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await preencher(tela);
    await userEvent.click(tela.getByRole('button', { name: 'Criar conta' }));
    await expect(args.aoEnviar).toHaveBeenCalledWith({
      name: 'Camila Réus',
      email: 'camila.reus@gmail.com',
      password: 'senha1234',
      termsAccepted: true,
    });
  },
};

export const Enviando: Story = {
  args: { aoEnviar: fn(() => new Promise<never>(() => {})) },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await preencher(tela);
    await userEvent.click(tela.getByRole('button', { name: 'Criar conta' }));
    const botao = await tela.findByRole('button', { name: 'Criando…' });
    await expect(botao).toHaveAttribute('aria-busy', 'true');
    await expect(tela.getByLabelText('E-mail')).toBeDisabled();
  },
};

export const ErroDeCampo: Story = {
  args: {
    aoEnviar: fn(async () => {
      throw new ApiError(422, 'VALIDATION_ERROR', 'Confira os campos destacados.', { email: ['Esse e-mail já tem conta.'] });
    }),
  },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await preencher(tela);
    await userEvent.click(tela.getByRole('button', { name: 'Criar conta' }));
    await expect(await tela.findByText('Esse e-mail já tem conta.')).toBeVisible();
    await expect(tela.getByRole('link', { name: 'Entrar com este e-mail' })).toHaveAttribute(
      'href',
      '/entrar?email=camila.reus%40gmail.com',
    );
  },
};

export const ErroGeral: Story = {
  args: {
    aoEnviar: fn(async () => {
      throw new ApiError(0, 'NETWORK_ERROR', MENSAGEM_SEM_CONEXAO);
    }),
  },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await preencher(tela);
    await userEvent.click(tela.getByRole('button', { name: 'Criar conta' }));
    await expect(await tela.findByRole('alert')).toHaveTextContent(MENSAGEM_SEM_CONEXAO);
    await expect(tela.getByRole('button', { name: 'Criar conta' })).toBeEnabled();
  },
};

export const LerOTermo: Story = {
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await userEvent.click(tela.getByRole('button', { name: 'Ler o termo' }));
    await expect(await tela.findByRole('dialog', { name: 'Termo de uso dos seus dados' })).toBeVisible();
  },
};
```

`src/features/auth/components/LoginForm.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, waitFor, within } from 'storybook/test';
import { ApiError } from '@/lib/api/errors';
import { LoginForm } from './LoginForm';

type Tela = ReturnType<typeof within>;

async function preencher(tela: Tela) {
  await userEvent.type(tela.getByLabelText('E-mail'), 'camila@exemplo.com');
  await userEvent.type(tela.getByLabelText('Senha'), 'senha1234');
}

const meta = {
  title: 'Conta/LoginForm',
  component: LoginForm,
  args: { aoEnviar: fn(async () => undefined) },
} satisfies Meta<typeof LoginForm>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Vazio: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await userEvent.click(tela.getByRole('button', { name: 'Entrar' }));
    await expect(tela.getByText('Confira o e-mail.')).toBeVisible();
    await expect(tela.getByText('Digite sua senha.')).toBeVisible();
    await expect(args.aoEnviar).not.toHaveBeenCalled();
  },
};

export const CredenciaisInvalidas: Story = {
  args: {
    aoEnviar: fn(async () => {
      throw new ApiError(422, 'INVALID_CREDENTIALS', 'E-mail ou senha incorretos.');
    }),
  },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await preencher(tela);
    await userEvent.click(tela.getByRole('button', { name: 'Entrar' }));
    await expect(await tela.findByRole('alert')).toHaveTextContent('E-mail ou senha incorretos.');
    await waitFor(() => expect(tela.getByLabelText('E-mail')).toHaveFocus());
  },
};

export const MuitasTentativas: Story = {
  args: {
    aoEnviar: fn(async () => {
      throw new ApiError(429, 'TOO_MANY_REQUESTS', 'Muitas tentativas seguidas. Tente de novo em 40 segundos.', {}, { retryAfter: 40 });
    }),
  },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await preencher(tela);
    await userEvent.click(tela.getByRole('button', { name: 'Entrar' }));
    await expect(await tela.findByRole('alert')).toHaveTextContent('Tente de novo em 40 segundos.');
  },
};

export const Enviando: Story = {
  args: { aoEnviar: fn(() => new Promise<never>(() => {})) },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await preencher(tela);
    await userEvent.click(tela.getByRole('button', { name: 'Entrar' }));
    await expect(await tela.findByRole('button', { name: 'Entrando…' })).toHaveAttribute('aria-busy', 'true');
  },
};

export const ComEmailDoCadastro: Story = {
  args: { emailInicial: 'camila@exemplo.com' },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByLabelText('E-mail')).toHaveValue('camila@exemplo.com');
  },
};
```

`src/features/auth/components/CadastroTela.integration.test.tsx`:
```tsx
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { comOnboardingEm } from '@/mocks/fixtures/usuario';
import { erroDaApi, url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { CadastroTela } from './CadastroTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => redefinirNavegacao());

async function preencherEEnviar() {
  const usuario = userEvent.setup();
  await usuario.type(screen.getByLabelText('Nome completo'), 'Camila Réus');
  await usuario.type(screen.getByLabelText('E-mail'), 'camila.reus@gmail.com');
  await usuario.type(screen.getByLabelText('Senha'), 'senha1234');
  await usuario.click(screen.getByRole('checkbox', { name: /Li e aceito/ }));
  await usuario.click(screen.getByRole('button', { name: 'Criar conta' }));
}

describe('Criar conta', () => {
  it('cria a conta e vai para a primeira etapa do onboarding (CA01)', async () => {
    let corpo: unknown;
    server.use(
      http.post(url('/register'), async ({ request }) => {
        corpo = await request.json();
        return HttpResponse.json({ data: comOnboardingEm('objetivo') }, { status: 201 });
      }),
    );

    renderizar(<CadastroTela />);
    await preencherEEnviar();

    await waitFor(() => expect(roteador.replace).toHaveBeenCalledWith('/onboarding/objetivo'));
    expect(corpo).toEqual({
      name: 'Camila Réus',
      email: 'camila.reus@gmail.com',
      password: 'senha1234',
      password_confirmation: 'senha1234',
      terms_accepted: true,
      terms_version: '2026-09',
    });
  });

  it('mostra o e-mail já cadastrado no campo, com atalho para entrar (CA02)', async () => {
    server.use(
      http.post(url('/register'), () =>
        erroDaApi(422, 'VALIDATION_ERROR', 'Confira os campos destacados.', { errors: { email: ['Esse e-mail já tem conta.'] } }),
      ),
    );

    renderizar(<CadastroTela />);
    await preencherEEnviar();

    expect(await screen.findByText('Esse e-mail já tem conta.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Entrar com este e-mail' })).toHaveAttribute('href', '/entrar?email=camila.reus%40gmail.com');
    expect(roteador.replace).not.toHaveBeenCalled();
  });

  it('sem rede avisa e deixa tentar de novo', async () => {
    server.use(http.post(url('/register'), () => HttpResponse.error()));

    renderizar(<CadastroTela />);
    await preencherEEnviar();

    expect(await screen.findByRole('alert')).toHaveTextContent('Sem conexão. Confira a internet e tente de novo.');
    expect(screen.getByRole('button', { name: 'Criar conta' })).toBeEnabled();
  });
});
```

`src/features/auth/components/EntrarTela.integration.test.tsx`:
```tsx
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { comOnboardingEm, usuarioApi } from '@/mocks/fixtures/usuario';
import { erroDaApi, url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { definirUrl, redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { EntrarTela } from './EntrarTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => redefinirNavegacao());

const loginDevolve = (usuario: object) =>
  server.use(http.post(url('/login'), () => HttpResponse.json({ data: usuario })));

async function entrar(email = 'camila@exemplo.com') {
  const usuario = userEvent.setup();
  if (email) await usuario.type(screen.getByLabelText('E-mail'), email);
  await usuario.type(screen.getByLabelText('Senha'), 'senha1234');
  await usuario.click(screen.getByRole('button', { name: 'Entrar' }));
  return usuario;
}

describe('Entrar', () => {
  it.each([
    ['sem voltar', '/entrar', '/hoje'],
    ['voltando para onde estava (CA09)', '/entrar?voltar=%2Fdieta%2Falmoco%3Fdia%3D2', '/dieta/almoco?dia=2'],
    ['ignorando voltar externo', '/entrar?voltar=%2F%2Fevil.com', '/hoje'],
  ])('onboarding completo: %s', async (_, endereco, destino) => {
    definirUrl(endereco);
    loginDevolve(usuarioApi);

    renderizar(<EntrarTela />);
    await entrar();

    await waitFor(() => expect(roteador.replace).toHaveBeenCalledWith(destino));
  });

  it('onboarding pela metade vai para a etapa (CA03)', async () => {
    loginDevolve(comOnboardingEm('atividade'));

    renderizar(<EntrarTela />);
    await entrar();

    await waitFor(() => expect(roteador.replace).toHaveBeenCalledWith('/onboarding/atividade'));
  });

  it('senha errada mostra a mensagem genérica e não navega (CA04)', async () => {
    server.use(http.post(url('/login'), () => erroDaApi(422, 'INVALID_CREDENTIALS', 'E-mail ou senha incorretos.')));

    renderizar(<EntrarTela />);
    await entrar();

    expect(await screen.findByRole('alert')).toHaveTextContent('E-mail ou senha incorretos.');
    expect(roteador.replace).not.toHaveBeenCalled();
  });

  it('toque duplo em Entrar manda uma requisição só', async () => {
    let pedidos = 0;
    server.use(
      http.post(url('/login'), async () => {
        pedidos++;
        await new Promise((fim) => setTimeout(fim, 50));
        return HttpResponse.json({ data: usuarioApi });
      }),
    );

    renderizar(<EntrarTela />);
    const usuario = await entrar();
    await usuario.click(screen.getByRole('button', { name: /Entrando/ }));

    await waitFor(() => expect(roteador.replace).toHaveBeenCalledWith('/hoje'));
    expect(pedidos).toBe(1);
  });

  it('sem rede avisa e libera o botão de novo', async () => {
    server.use(http.post(url('/login'), () => HttpResponse.error()));

    renderizar(<EntrarTela />);
    await entrar();

    expect(await screen.findByRole('alert')).toHaveTextContent('Sem conexão. Confira a internet e tente de novo.');
    expect(screen.getByRole('button', { name: 'Entrar' })).toBeEnabled();
  });

  it('já vem com o e-mail do atalho do cadastro', async () => {
    definirUrl('/entrar?email=camila%40exemplo.com');

    renderizar(<EntrarTela />);

    expect(screen.getByLabelText('E-mail')).toHaveValue('camila@exemplo.com');
  });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose run --rm web bash -c "npx vitest run --project integration src/features/auth/components && npx vitest run --project storybook src/features/auth"`
Expected: FAIL — imports de `./CadastroTela`, `./EntrarTela`, `./RegisterForm`, `./LoginForm` não resolvem.

- [ ] **Step 3: Implementar**

`src/features/auth/components/AuthScreen.tsx`:
```tsx
import { Screen } from "@/components/app/Screen";
import { TopBar } from "@/components/app/TopBar";

/** Casca das telas de conta: voltar, título que entra, texto e o formulário ocupando o resto. */
export function AuthScreen({
  voltarPara,
  rotuloVoltar = "Voltar",
  titulo,
  texto,
  children,
}: {
  voltarPara: string;
  rotuloVoltar?: string;
  titulo: string;
  texto?: string;
  children: React.ReactNode;
}) {
  return (
    <Screen>
      <TopBar voltarPara={voltarPara} rotuloVoltar={rotuloVoltar} className="area-segura-cima" />
      <main className="flex flex-1 flex-col px-6 pt-4 pb-8 area-segura-baixo">
        <h1
          className="animate-entra font-display text-[30px] leading-[1.08] font-bold tracking-[-0.028em]"
          style={{ animationDelay: "40ms" }}
        >
          {titulo}
        </h1>
        {texto ? (
          <p
            className="mt-2.5 max-w-[330px] animate-entra text-[14.5px] leading-normal text-fumo"
            style={{ animationDelay: "110ms" }}
          >
            {texto}
          </p>
        ) : null}
        <div className="mt-7 flex flex-1 flex-col">{children}</div>
      </main>
    </Screen>
  );
}
```

`src/features/auth/components/TermoSheet.tsx`:
```tsx
"use client";

import { Button } from "@/components/ui/Button";
import { Sheet } from "@/components/ui/Sheet";
import { cascata } from "@/lib/motion";
import { TERMO_PARAGRAFOS, TERMO_VERSAO } from "../termo";

export function TermoSheet({ aberta, aoFechar }: { aberta: boolean; aoFechar: () => void }) {
  return (
    <Sheet aberta={aberta} aoFechar={aoFechar} titulo="Termo de uso dos seus dados" descricao={`Versão ${TERMO_VERSAO}`}>
      <div className="mt-4 flex flex-col gap-3 text-[14px] leading-relaxed text-tinta">
        {TERMO_PARAGRAFOS.map((paragrafo, i) => (
          <p key={i} className="animate-entra" style={cascata(i, 50, 120)}>
            {paragrafo}
          </p>
        ))}
      </div>
      <Button variante="contorno" className="mt-6" onClick={aoFechar}>
        Fechar
      </Button>
    </Sheet>
  );
}
```

`src/features/auth/components/RegisterForm.tsx`:
```tsx
"use client";

import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { FormError } from "@/components/ui/FormError";
import { OptionRow } from "@/components/ui/OptionRow";
import { PasswordField } from "@/components/ui/PasswordField";
import { type ApiError, comoApiError, primeirasMensagens } from "@/lib/api/errors";
import { cascata } from "@/lib/motion";
import { type DadosCadastro, type Erros, validarCadastro } from "../schemas";
import { TermoSheet } from "./TermoSheet";

const VAZIO: DadosCadastro = { name: "", email: "", password: "", termsAccepted: false };

/** N01 — formulário de cadastro (apresentacional: quem envia é `aoEnviar`). */
export function RegisterForm({ aoEnviar }: { aoEnviar: (dados: DadosCadastro) => Promise<unknown> }) {
  const [dados, setDados] = useState(VAZIO);
  const [erros, setErros] = useState<Erros<keyof DadosCadastro>>({});
  const [erroGeral, setErroGeral] = useState<ApiError | null>(null);
  const [emailRecusado, setEmailRecusado] = useState(false);
  const [enviando, setEnviando] = useState(false);
  const [termoAberto, setTermoAberto] = useState(false);

  function mudar<C extends keyof DadosCadastro>(campo: C, valor: DadosCadastro[C]) {
    setDados((atual) => ({ ...atual, [campo]: valor }));
    setErros((atual) => ({ ...atual, [campo]: undefined }));
    if (campo === "email") setEmailRecusado(false);
  }

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();
    if (enviando) return;
    const encontrados = validarCadastro(dados);
    setErros(encontrados);
    setErroGeral(null);
    if (Object.keys(encontrados).length > 0) return;

    setEnviando(true);
    try {
      await aoEnviar(dados); // sucesso: a tela navega; o botão continua ocupado até sair
    } catch (e) {
      const erro = comoApiError(e);
      if (erro.code === "VALIDATION_ERROR") {
        const porCampo = primeirasMensagens(erro.fieldErrors) as Erros<keyof DadosCadastro>;
        setErros(porCampo);
        // Formato já foi conferido aqui; e-mail recusado pelo servidor = já tem conta (RF01 alternativo).
        setEmailRecusado(Boolean(porCampo.email));
      } else {
        setErroGeral(erro);
      }
      setEnviando(false);
    }
  }

  return (
    <form noValidate onSubmit={enviar} className="flex flex-1 flex-col">
      <div className="flex flex-col gap-4">
        <Field
          id="nome"
          label="Nome completo"
          autoComplete="name"
          value={dados.name}
          onChange={(e) => mudar("name", e.target.value)}
          erro={erros.name}
          disabled={enviando}
          className="animate-entra"
          style={cascata(0, 60, 160)}
        />
        <div className="animate-entra" style={cascata(1, 60, 160)}>
          <Field
            id="email"
            type="email"
            label="E-mail"
            autoComplete="email"
            inputMode="email"
            value={dados.email}
            onChange={(e) => mudar("email", e.target.value)}
            erro={erros.email}
            disabled={enviando}
          />
          {emailRecusado && erros.email ? (
            <Link
              href={`/entrar?email=${encodeURIComponent(dados.email.trim())}`}
              className="mt-1.5 inline-block animate-entra text-[13px] font-semibold text-mata underline-offset-2 hover:underline"
            >
              Entrar com este e-mail
            </Link>
          ) : null}
        </div>
        <PasswordField
          id="senha"
          label="Senha"
          autoComplete="new-password"
          ajuda="8 ou mais, com letra e número"
          value={dados.password}
          onChange={(e) => mudar("password", e.target.value)}
          erro={erros.password}
          disabled={enviando}
          className="animate-entra"
          style={cascata(2, 60, 160)}
        />
        <div className="animate-entra" style={cascata(3, 60, 160)}>
          <OptionRow
            quadrado
            compacto
            marcado={dados.termsAccepted}
            onClick={() => mudar("termsAccepted", !dados.termsAccepted)}
            titulo="Li e aceito o termo de uso dos meus dados de saúde"
          />
          <button
            type="button"
            onClick={() => setTermoAberto(true)}
            className="mt-2 text-[13px] font-semibold text-mata underline-offset-2 hover:underline"
          >
            Ler o termo
          </button>
          {erros.termsAccepted ? (
            <p className="mt-1.5 animate-entra text-[12.5px] font-medium text-alerta">{erros.termsAccepted}</p>
          ) : null}
        </div>
      </div>

      <div className="mt-auto animate-entra pt-8" style={cascata(4, 60, 160)}>
        <FormError erro={erroGeral} />
        <Button type="submit" carregando={enviando} rotuloCarregando="Criando…">
          Criar conta
        </Button>
        <Link
          href="/entrar"
          className="mt-2 flex h-11 items-center justify-center text-[14px] font-semibold text-mata"
        >
          Já tenho conta
        </Link>
      </div>

      <TermoSheet aberta={termoAberto} aoFechar={() => setTermoAberto(false)} />
    </form>
  );
}
```

`src/features/auth/components/LoginForm.tsx`:
```tsx
"use client";

import Link from "next/link";
import { useRef, useState } from "react";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { FormError } from "@/components/ui/FormError";
import { PasswordField } from "@/components/ui/PasswordField";
import { type ApiError, comoApiError, primeirasMensagens } from "@/lib/api/errors";
import { cascata } from "@/lib/motion";
import { type DadosLogin, type Erros, validarLogin } from "../schemas";

/** N02 — formulário de login (apresentacional). */
export function LoginForm({
  aoEnviar,
  emailInicial = "",
}: {
  aoEnviar: (dados: DadosLogin) => Promise<unknown>;
  emailInicial?: string;
}) {
  const [dados, setDados] = useState<DadosLogin>({ email: emailInicial, password: "" });
  const [erros, setErros] = useState<Erros<keyof DadosLogin>>({});
  const [erroGeral, setErroGeral] = useState<ApiError | null>(null);
  const [enviando, setEnviando] = useState(false);
  const campoEmail = useRef<HTMLInputElement>(null);

  function mudar(campo: keyof DadosLogin, valor: string) {
    setDados((atual) => ({ ...atual, [campo]: valor }));
    setErros((atual) => ({ ...atual, [campo]: undefined }));
  }

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();
    if (enviando) return;
    const encontrados = validarLogin(dados);
    setErros(encontrados);
    setErroGeral(null);
    if (Object.keys(encontrados).length > 0) return;

    setEnviando(true);
    try {
      await aoEnviar(dados);
    } catch (e) {
      const erro = comoApiError(e);
      if (erro.code === "VALIDATION_ERROR") {
        setErros(primeirasMensagens(erro.fieldErrors) as Erros<keyof DadosLogin>);
      } else {
        setErroGeral(erro);
        // Credenciais erradas: a mensagem não diz qual campo (CA04); o foco volta ao e-mail.
        if (erro.code === "INVALID_CREDENTIALS") setTimeout(() => campoEmail.current?.focus());
      }
      setEnviando(false);
    }
  }

  return (
    <form noValidate onSubmit={enviar} className="flex flex-1 flex-col">
      <div className="flex flex-col gap-4">
        <Field
          ref={campoEmail}
          id="email"
          type="email"
          label="E-mail"
          autoComplete="email"
          inputMode="email"
          value={dados.email}
          onChange={(e) => mudar("email", e.target.value)}
          erro={erros.email}
          disabled={enviando}
          className="animate-entra"
          style={cascata(0, 60, 160)}
        />
        <div className="animate-entra" style={cascata(1, 60, 160)}>
          <PasswordField
            id="senha"
            label="Senha"
            autoComplete="current-password"
            value={dados.password}
            onChange={(e) => mudar("password", e.target.value)}
            erro={erros.password}
            disabled={enviando}
          />
          <Link
            href="/senha/esqueci"
            className="mt-2.5 inline-block text-[13px] font-semibold text-mata underline-offset-2 hover:underline"
          >
            Esqueci minha senha
          </Link>
        </div>
      </div>

      <div className="mt-auto animate-entra pt-8" style={cascata(2, 60, 160)}>
        <FormError erro={erroGeral} focar={erroGeral?.code !== "INVALID_CREDENTIALS"} />
        <Button type="submit" carregando={enviando} rotuloCarregando="Entrando…">
          Entrar
        </Button>
        <Link
          href="/cadastro"
          className="mt-2 flex h-11 items-center justify-center text-[14px] font-semibold text-mata"
        >
          Criar conta
        </Link>
      </div>
    </form>
  );
}
```

`src/features/auth/components/CadastroTela.tsx`:
```tsx
"use client";

import { useRedirecionarSeLogado, useRegister } from "../hooks";
import { TERMO_VERSAO } from "../termo";
import { AuthScreen } from "./AuthScreen";
import { RegisterForm } from "./RegisterForm";

/** N01 — ao criar, `useRegister` grava `['me']` e `useRedirecionarSeLogado` leva ao onboarding. */
export function CadastroTela() {
  useRedirecionarSeLogado();
  const registrar = useRegister();

  return (
    <AuthScreen
      voltarPara="/"
      rotuloVoltar="Voltar para o início"
      titulo="Vamos começar pela sua conta"
      texto="Assim seu plano fica salvo e você entra de qualquer celular."
    >
      <RegisterForm
        aoEnviar={(dados) =>
          registrar.mutateAsync({ ...dados, passwordConfirmation: dados.password, termsVersion: TERMO_VERSAO })
        }
      />
    </AuthScreen>
  );
}
```

`src/features/auth/components/EntrarTela.tsx`:
```tsx
"use client";

import { useSearchParams } from "next/navigation";
import { useLogin, useRedirecionarSeLogado } from "../hooks";
import { AuthScreen } from "./AuthScreen";
import { LoginForm } from "./LoginForm";

/** N02 — o destino (etapa pendente, `?voltar=` seguro ou Hoje) sai de `useRedirecionarSeLogado`. */
export function EntrarTela() {
  useRedirecionarSeLogado();
  const entrar = useLogin();
  const email = useSearchParams().get("email") ?? "";

  return (
    <AuthScreen
      voltarPara="/"
      rotuloVoltar="Voltar para o início"
      titulo="Que bom te ver de novo"
      texto="Entre com o e-mail e a senha da sua conta."
    >
      <LoginForm emailInicial={email} aoEnviar={(dados) => entrar.mutateAsync(dados)} />
    </AuthScreen>
  );
}
```

`src/app/(publico)/template.tsx`:
```tsx
/** Telas de conta entram pela direita, como o onboarding. */
export default function TransicaoConta({ children }: { children: React.ReactNode }) {
  return <div className="animate-entra-lado">{children}</div>;
}
```

`src/app/(publico)/cadastro/page.tsx`:
```tsx
import type { Metadata } from "next";
import { Suspense } from "react";
import { TelaCarregando } from "@/components/app/TelaCarregando";
import { CadastroTela } from "@/features/auth/components/CadastroTela";

export const metadata: Metadata = { title: "Criar conta · Prato Forte" };

export default function Page() {
  return (
    <Suspense fallback={<TelaCarregando />}>
      <CadastroTela />
    </Suspense>
  );
}
```

`src/app/(publico)/entrar/page.tsx`:
```tsx
import type { Metadata } from "next";
import { Suspense } from "react";
import { TelaCarregando } from "@/components/app/TelaCarregando";
import { EntrarTela } from "@/features/auth/components/EntrarTela";

export const metadata: Metadata = { title: "Entrar · Prato Forte" };

export default function Page() {
  return (
    <Suspense fallback={<TelaCarregando />}>
      <EntrarTela />
    </Suspense>
  );
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose run --rm web bash -c "npx vitest run --project integration src/features/auth/components && npx vitest run --project storybook src/features/auth"`
Expected: PASS.

- [ ] **Step 5: Refinar o visual com `frontend-design` (D11)**

Carregue o skill `frontend-design:frontend-design` e peça as telas **Criar conta** e **Entrar** (arquivos `AuthScreen.tsx`, `RegisterForm.tsx`, `LoginForm.tsx`, `TermoSheet.tsx`): bastante animação e fidelidade ao mock — a Boas-vindas escura (`bg-tinta`, régua de horários com o tique gema) é a referência de personalidade; as telas de conta podem ecoar o motivo da régua/linha do dia no topo, cascatas de entrada nos campos, balanço no erro, transição lateral. **Restrições:** não mudar textos, rótulos, `role`s, `aria-*`, ids nem props (as stories e integrações dependem deles); só tokens de `globals.css`; `text-musgo` só sobre `tinta`; movimento em `transform`/`opacity` e com `prefers-reduced-motion`.

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test"`
Expected: PASS.

- [ ] **Step 6: Conferência manual contra a API real (1 vez)**

```bash
cd /home/alvez/atividade-extensionista/backend && docker compose up -d && docker compose exec api php artisan migrate --force && cd -
docker compose run --rm web npm run dev
```
Abra http://localhost:3000 → "Montar meu plano" → crie uma conta → deve cair em `/onboarding/objetivo`. Em outra aba anônima: http://localhost:3000/hoje → `/entrar?voltar=%2Fhoje`. Encerre com Ctrl+C e `docker compose -f ../backend/compose.yaml down`.
Expected: fluxo acima; no DevTools, `POST /api/v1/register` 201 com cookies `prato-forte-session` e `XSRF-TOKEN`.

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "feat(auth): telas Criar conta e Entrar ligadas à API

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Esqueci minha senha (N03) e Redefinir senha (N04)

**Files:**
- Create: `src/features/auth/components/ForgotPasswordForm.tsx`, `ResetPasswordForm.tsx`, `EsqueciTela.tsx`, `RedefinirTela.tsx`
- Create: `src/app/(publico)/senha/esqueci/page.tsx`, `src/app/(publico)/senha/redefinir/page.tsx`
- Test: `ForgotPasswordForm.stories.tsx`, `ResetPasswordForm.stories.tsx`, `EsqueciTela.integration.test.tsx`, `RedefinirTela.integration.test.tsx` (todos em `src/features/auth/components/`)

**Interfaces:**
- Consumes: `AuthScreen` (Task 6); `useForgotPassword`, `useResetPassword`, `erroEmail`, `validarNovaSenha` (Task 4); `useToast`, primitivos (Task 3).
- Produces:
  - `ForgotPasswordForm({ aoEnviar: (email: string) => Promise<unknown> })` — depois de enviar mostra "Confira seu e-mail" (sempre, RN04).
  - `ResetPasswordForm({ aoEnviar: (d: DadosNovaSenha) => Promise<unknown> })` — rótulos "Nova senha" e "Confirme a nova senha", botão "Salvar senha".
  - `EsqueciTela()`, `RedefinirTela()`; rotas `/senha/esqueci`, `/senha/redefinir?token=&email=`.

- [ ] **Step 1: Escrever stories e testes que devem falhar**

`src/features/auth/components/ForgotPasswordForm.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { ApiError } from '@/lib/api/errors';
import { ForgotPasswordForm } from './ForgotPasswordForm';

const meta = {
  title: 'Conta/ForgotPasswordForm',
  component: ForgotPasswordForm,
  args: { aoEnviar: fn(async () => undefined) },
} satisfies Meta<typeof ForgotPasswordForm>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Vazio: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await userEvent.click(tela.getByRole('button', { name: 'Enviar link' }));
    await expect(tela.getByText('Confira o e-mail.')).toBeVisible();
    await expect(args.aoEnviar).not.toHaveBeenCalled();
  },
};

export const Enviado: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await userEvent.type(tela.getByLabelText('E-mail'), 'camila@exemplo.com');
    await userEvent.click(tela.getByRole('button', { name: 'Enviar link' }));
    await expect(args.aoEnviar).toHaveBeenCalledWith('camila@exemplo.com');
    await expect(await tela.findByRole('heading', { name: 'Confira seu e-mail' })).toBeVisible();
    await expect(tela.getByRole('link', { name: 'Voltar para entrar' })).toHaveAttribute('href', '/entrar');
  },
};

export const MuitasTentativas: Story = {
  args: {
    aoEnviar: fn(async () => {
      throw new ApiError(429, 'TOO_MANY_REQUESTS', 'Muitas tentativas seguidas. Tente de novo em 1200 segundos.');
    }),
  },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await userEvent.type(tela.getByLabelText('E-mail'), 'camila@exemplo.com');
    await userEvent.click(tela.getByRole('button', { name: 'Enviar link' }));
    await expect(await tela.findByRole('alert')).toHaveTextContent('Muitas tentativas seguidas.');
  },
};
```

`src/features/auth/components/ResetPasswordForm.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { ResetPasswordForm } from './ResetPasswordForm';

const meta = {
  title: 'Conta/ResetPasswordForm',
  component: ResetPasswordForm,
  args: { aoEnviar: fn(async () => undefined) },
} satisfies Meta<typeof ResetPasswordForm>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Valido: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await userEvent.type(tela.getByLabelText('Nova senha'), 'novaSenha9');
    await userEvent.type(tela.getByLabelText('Confirme a nova senha'), 'novaSenha9');
    await userEvent.click(tela.getByRole('button', { name: 'Salvar senha' }));
    await expect(args.aoEnviar).toHaveBeenCalledWith({ password: 'novaSenha9', passwordConfirmation: 'novaSenha9' });
  },
};

export const SenhasDiferentes: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await userEvent.type(tela.getByLabelText('Nova senha'), 'novaSenha9');
    await userEvent.type(tela.getByLabelText('Confirme a nova senha'), 'novaSenha8');
    await userEvent.click(tela.getByRole('button', { name: 'Salvar senha' }));
    await expect(tela.getByText('As senhas não conferem.')).toBeVisible();
    await expect(args.aoEnviar).not.toHaveBeenCalled();
  },
};

export const Enviando: Story = {
  args: { aoEnviar: fn(() => new Promise<never>(() => {})) },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await userEvent.type(tela.getByLabelText('Nova senha'), 'novaSenha9');
    await userEvent.type(tela.getByLabelText('Confirme a nova senha'), 'novaSenha9');
    await userEvent.click(tela.getByRole('button', { name: 'Salvar senha' }));
    await expect(await tela.findByRole('button', { name: 'Salvando…' })).toHaveAttribute('aria-busy', 'true');
  },
};
```

`src/features/auth/components/EsqueciTela.integration.test.tsx`:
```tsx
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { redefinirNavegacao } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { EsqueciTela } from './EsqueciTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => redefinirNavegacao());

describe('Esqueci minha senha', () => {
  it('confirma o envio para qualquer e-mail (CA05) e manda o e-mail digitado', async () => {
    let corpo: unknown;
    server.use(
      http.post(url('/password/forgot'), async ({ request }) => {
        corpo = await request.json();
        return HttpResponse.json({ message: 'Se houver uma conta com esse e-mail, enviamos um link.' });
      }),
    );
    const usuario = userEvent.setup();

    renderizar(<EsqueciTela />);
    await usuario.type(screen.getByLabelText('E-mail'), 'ninguem@exemplo.com');
    await usuario.click(screen.getByRole('button', { name: 'Enviar link' }));

    expect(await screen.findByRole('heading', { name: 'Confira seu e-mail' })).toBeInTheDocument();
    expect(corpo).toEqual({ email: 'ninguem@exemplo.com' });
  });
});
```

`src/features/auth/components/RedefinirTela.integration.test.tsx`:
```tsx
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { erroDaApi, url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { definirUrl, redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { RedefinirTela } from './RedefinirTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => redefinirNavegacao());

async function salvar(senha = 'novaSenha9') {
  const usuario = userEvent.setup();
  await usuario.type(screen.getByLabelText('Nova senha'), senha);
  await usuario.type(screen.getByLabelText('Confirme a nova senha'), senha);
  await usuario.click(screen.getByRole('button', { name: 'Salvar senha' }));
}

describe('Redefinir senha', () => {
  it('sem token na URL mostra o link inválido com atalho para pedir outro', () => {
    definirUrl('/senha/redefinir');

    renderizar(<RedefinirTela />);

    expect(screen.getByText('Esse link expirou. Peça outro.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Pedir outro link' })).toHaveAttribute('href', '/senha/esqueci');
  });

  it('salva, avisa e leva ao login com o e-mail preenchido', async () => {
    let corpo: unknown;
    server.use(
      http.post(url('/password/reset'), async ({ request }) => {
        corpo = await request.json();
        return HttpResponse.json({ message: 'Senha redefinida.' });
      }),
    );
    definirUrl('/senha/redefinir?token=abc123&email=camila%2Btreino%40gmail.com');

    renderizar(<RedefinirTela />);
    await salvar();

    await waitFor(() => expect(roteador.replace).toHaveBeenCalledWith('/entrar?email=camila%2Btreino%40gmail.com'));
    expect(await screen.findByText('Senha nova salva. Entre com ela.')).toBeInTheDocument();
    expect(corpo).toEqual({
      token: 'abc123',
      email: 'camila+treino@gmail.com',
      password: 'novaSenha9',
      password_confirmation: 'novaSenha9',
    });
  });

  it('link vencido ou usado vira o estado de link inválido (CA06)', async () => {
    server.use(http.post(url('/password/reset'), () => erroDaApi(422, 'INVALID_RESET_TOKEN', 'Esse link expirou. Peça outro.')));
    definirUrl('/senha/redefinir?token=velho&email=camila%40exemplo.com');

    renderizar(<RedefinirTela />);
    await salvar();

    expect(await screen.findByRole('link', { name: 'Pedir outro link' })).toBeInTheDocument();
    expect(roteador.replace).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose run --rm web bash -c "npx vitest run --project integration src/features/auth/components && npx vitest run --project storybook src/features/auth"`
Expected: FAIL — `./EsqueciTela`, `./RedefinirTela`, `./ForgotPasswordForm`, `./ResetPasswordForm` não resolvem.

- [ ] **Step 3: Implementar**

`src/features/auth/components/ForgotPasswordForm.tsx`:
```tsx
"use client";

import { useState } from "react";
import { Button, ButtonLink } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { FormError } from "@/components/ui/FormError";
import { IconeCheck } from "@/components/icons";
import { type ApiError, comoApiError } from "@/lib/api/errors";
import { erroEmail } from "../schemas";

/** N03 — pede o link; a confirmação é a mesma exista ou não a conta (RN04). */
export function ForgotPasswordForm({ aoEnviar }: { aoEnviar: (email: string) => Promise<unknown> }) {
  const [email, setEmail] = useState("");
  const [erro, setErro] = useState<string | undefined>();
  const [erroGeral, setErroGeral] = useState<ApiError | null>(null);
  const [enviando, setEnviando] = useState(false);
  const [enviado, setEnviado] = useState(false);

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();
    if (enviando) return;
    const problema = erroEmail(email);
    setErro(problema);
    setErroGeral(null);
    if (problema) return;

    setEnviando(true);
    try {
      await aoEnviar(email.trim());
      setEnviado(true);
    } catch (e) {
      setErroGeral(comoApiError(e));
    } finally {
      setEnviando(false);
    }
  }

  if (enviado) {
    return (
      <div className="flex flex-1 flex-col">
        <div className="animate-escala rounded-3xl bg-white p-6 text-center">
          <span className="mx-auto flex size-12 animate-pop items-center justify-center rounded-full bg-mata text-white">
            <IconeCheck size={22} strokeWidth={2.4} />
          </span>
          <h2 className="mt-4 font-display text-[22px] font-bold tracking-[-0.02em]">Confira seu e-mail</h2>
          <p className="mt-2 text-[14px] leading-normal text-fumo">
            Se houver uma conta com {email.trim()}, o link chega em alguns minutos. Olhe também a caixa de spam.
          </p>
        </div>
        <div className="mt-auto pt-8">
          <ButtonLink href="/entrar" variante="contorno">
            Voltar para entrar
          </ButtonLink>
        </div>
      </div>
    );
  }

  return (
    <form noValidate onSubmit={enviar} className="flex flex-1 flex-col">
      <Field
        id="email"
        type="email"
        label="E-mail"
        autoComplete="email"
        inputMode="email"
        value={email}
        onChange={(e) => {
          setEmail(e.target.value);
          setErro(undefined);
        }}
        erro={erro}
        disabled={enviando}
        className="animate-entra"
        style={{ animationDelay: "160ms" }}
      />
      <div className="mt-auto animate-entra pt-8" style={{ animationDelay: "220ms" }}>
        <FormError erro={erroGeral} />
        <Button type="submit" carregando={enviando} rotuloCarregando="Enviando…">
          Enviar link
        </Button>
      </div>
    </form>
  );
}
```

`src/features/auth/components/ResetPasswordForm.tsx`:
```tsx
"use client";

import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { FormError } from "@/components/ui/FormError";
import { PasswordField } from "@/components/ui/PasswordField";
import { type ApiError, comoApiError, primeirasMensagens } from "@/lib/api/errors";
import { cascata } from "@/lib/motion";
import { type DadosNovaSenha, type Erros, validarNovaSenha } from "../schemas";

/** N04 — nova senha + confirmação (apresentacional). */
export function ResetPasswordForm({ aoEnviar }: { aoEnviar: (dados: DadosNovaSenha) => Promise<unknown> }) {
  const [dados, setDados] = useState<DadosNovaSenha>({ password: "", passwordConfirmation: "" });
  const [erros, setErros] = useState<Erros<keyof DadosNovaSenha>>({});
  const [erroGeral, setErroGeral] = useState<ApiError | null>(null);
  const [enviando, setEnviando] = useState(false);

  function mudar(campo: keyof DadosNovaSenha, valor: string) {
    setDados((atual) => ({ ...atual, [campo]: valor }));
    setErros((atual) => ({ ...atual, [campo]: undefined }));
  }

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();
    if (enviando) return;
    const encontrados = validarNovaSenha(dados);
    setErros(encontrados);
    setErroGeral(null);
    if (Object.keys(encontrados).length > 0) return;

    setEnviando(true);
    try {
      await aoEnviar(dados);
    } catch (e) {
      const erro = comoApiError(e);
      if (erro.code === "VALIDATION_ERROR") setErros(primeirasMensagens(erro.fieldErrors) as Erros<keyof DadosNovaSenha>);
      else setErroGeral(erro);
      setEnviando(false);
    }
  }

  return (
    <form noValidate onSubmit={enviar} className="flex flex-1 flex-col">
      <div className="flex flex-col gap-4">
        <PasswordField
          id="nova-senha"
          label="Nova senha"
          autoComplete="new-password"
          ajuda="8 ou mais, com letra e número"
          value={dados.password}
          onChange={(e) => mudar("password", e.target.value)}
          erro={erros.password}
          disabled={enviando}
          className="animate-entra"
          style={cascata(0, 60, 160)}
        />
        <PasswordField
          id="confirmacao"
          label="Confirme a nova senha"
          autoComplete="new-password"
          value={dados.passwordConfirmation}
          onChange={(e) => mudar("passwordConfirmation", e.target.value)}
          erro={erros.passwordConfirmation}
          disabled={enviando}
          className="animate-entra"
          style={cascata(1, 60, 160)}
        />
      </div>
      <div className="mt-auto animate-entra pt-8" style={cascata(2, 60, 160)}>
        <FormError erro={erroGeral} />
        <Button type="submit" carregando={enviando} rotuloCarregando="Salvando…">
          Salvar senha
        </Button>
      </div>
    </form>
  );
}
```

`src/features/auth/components/EsqueciTela.tsx`:
```tsx
"use client";

import { useForgotPassword } from "../hooks";
import { AuthScreen } from "./AuthScreen";
import { ForgotPasswordForm } from "./ForgotPasswordForm";

export function EsqueciTela() {
  const pedir = useForgotPassword();

  return (
    <AuthScreen
      voltarPara="/entrar"
      rotuloVoltar="Voltar para entrar"
      titulo="Vamos recuperar seu acesso"
      texto="Digite o e-mail da sua conta. Mandamos um link para você criar uma senha nova."
    >
      <ForgotPasswordForm aoEnviar={(email) => pedir.mutateAsync(email)} />
    </AuthScreen>
  );
}
```

`src/features/auth/components/RedefinirTela.tsx`:
```tsx
"use client";

import { useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";
import { ButtonLink } from "@/components/ui/Button";
import { useToast } from "@/components/ui/Toaster";
import { ApiError } from "@/lib/api/errors";
import { useResetPassword } from "../hooks";
import type { DadosNovaSenha } from "../schemas";
import { AuthScreen } from "./AuthScreen";
import { ResetPasswordForm } from "./ResetPasswordForm";

/** N04 — o link do e-mail traz `token` e `email`; sem eles (ou vencido), pede outro. */
export function RedefinirTela() {
  const busca = useSearchParams();
  const token = busca.get("token");
  const email = busca.get("email");
  const redefinir = useResetPassword();
  const router = useRouter();
  const avisar = useToast();
  const [linkInvalido, setLinkInvalido] = useState(!token || !email);

  if (linkInvalido || !token || !email) {
    return (
      <AuthScreen voltarPara="/entrar" rotuloVoltar="Voltar para entrar" titulo="Esse link não vale mais">
        <div className="animate-entra rounded-2xl bg-alerta-fraca px-4 py-3.5" style={{ animationDelay: "120ms" }}>
          <p className="text-[14px] leading-snug font-medium text-alerta-texto">Esse link expirou. Peça outro.</p>
        </div>
        <div className="mt-auto pt-8">
          <ButtonLink href="/senha/esqueci">Pedir outro link</ButtonLink>
        </div>
      </AuthScreen>
    );
  }

  async function salvar(dados: DadosNovaSenha) {
    try {
      await redefinir.mutateAsync({ token: token!, email: email!, ...dados });
    } catch (erro) {
      if (erro instanceof ApiError && erro.code === "INVALID_RESET_TOKEN") {
        setLinkInvalido(true);
        return;
      }
      throw erro;
    }
    avisar({ texto: "Senha nova salva. Entre com ela." });
    router.replace(`/entrar?email=${encodeURIComponent(email!)}`);
  }

  return (
    <AuthScreen
      voltarPara="/entrar"
      rotuloVoltar="Voltar para entrar"
      titulo="Crie uma senha nova"
      texto="Depois de salvar, os aparelhos em que você estava conectado saem da conta."
    >
      <ResetPasswordForm aoEnviar={salvar} />
    </AuthScreen>
  );
}
```

`src/app/(publico)/senha/esqueci/page.tsx`:
```tsx
import type { Metadata } from "next";
import { EsqueciTela } from "@/features/auth/components/EsqueciTela";

export const metadata: Metadata = { title: "Recuperar senha · Prato Forte" };

export default function Page() {
  return <EsqueciTela />;
}
```

`src/app/(publico)/senha/redefinir/page.tsx`:
```tsx
import type { Metadata } from "next";
import { Suspense } from "react";
import { TelaCarregando } from "@/components/app/TelaCarregando";
import { RedefinirTela } from "@/features/auth/components/RedefinirTela";

export const metadata: Metadata = { title: "Senha nova · Prato Forte" };

export default function Page() {
  return (
    <Suspense fallback={<TelaCarregando />}>
      <RedefinirTela />
    </Suspense>
  );
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose run --rm web bash -c "npx vitest run --project integration src/features/auth/components && npx vitest run --project storybook src/features/auth"`
Expected: PASS.

- [ ] **Step 5: Refinar com `frontend-design` (D11) e verificar**

Carregue `frontend-design:frontend-design` para `ForgotPasswordForm` (sobretudo o estado "Confira seu e-mail": entrada com escala e check que "pula", como no mock) e `RedefinirTela`/`ResetPasswordForm`, com as mesmas restrições da Task 6 (textos, `role`s, rótulos e props intocados; só tokens; `musgo` só no escuro; movimento reduzido respeitado).

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm run build"`
Expected: tudo verde.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(auth): recuperar e redefinir senha

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Conta nas Configurações — trocar senha (N06), sair (RF03) e apagar conta (N07)

**Files:**
- Create: `src/features/auth/components/ChangePasswordForm.tsx`, `TrocarSenhaTela.tsx`, `DeleteAccountSheet.tsx`, `ContaSection.tsx`
- Create: `src/app/(app)/perfil/configuracoes/senha/page.tsx`
- Modify: `src/app/(app)/perfil/configuracoes/page.tsx` (bloco "Sua conta" → `<ContaSection />`)
- Test: `ChangePasswordForm.stories.tsx`, `DeleteAccountSheet.stories.tsx`, `TrocarSenhaTela.integration.test.tsx`, `ContaSection.integration.test.tsx` (em `src/features/auth/components/`)

**Interfaces:**
- Consumes: `AuthScreen` (Task 6); `useMe`, `useUpdatePassword`, `useLogout`, `useDeleteAccount`, `validarTrocaSenha`, `MENSAGENS`, `recarregarEm` (Task 4); `Sheet` `tom="destrutivo"`, `Button` `destrutiva`, `useToast` (Task 3).
- Produces:
  - `ChangePasswordForm({ aoEnviar: (d: DadosTrocaSenha) => Promise<unknown> })` — rótulos "Senha atual", "Nova senha", "Confirme a nova senha"; botão "Salvar".
  - `DeleteAccountSheet({ aberta, aoFechar, aoApagar: (senha: string) => Promise<unknown> })`.
  - `ContaSection()` — e-mail (de `/me`), "Trocar senha" (link), "Sair desta conta" (logout + recarga em `/`), "Apagar minha conta e meus dados" (folha; sucesso → recarga em `/?conta=apagada`).
  - Rota `/perfil/configuracoes/senha`.

- [ ] **Step 1: Escrever stories e testes que devem falhar**

`src/features/auth/components/ChangePasswordForm.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { ApiError } from '@/lib/api/errors';
import { ChangePasswordForm } from './ChangePasswordForm';

type Tela = ReturnType<typeof within>;

async function preencher(tela: Tela) {
  await userEvent.type(tela.getByLabelText('Senha atual'), 'senha1234');
  await userEvent.type(tela.getByLabelText('Nova senha'), 'novaSenha9');
  await userEvent.type(tela.getByLabelText('Confirme a nova senha'), 'novaSenha9');
  await userEvent.click(tela.getByRole('button', { name: 'Salvar' }));
}

const meta = {
  title: 'Conta/ChangePasswordForm',
  component: ChangePasswordForm,
  args: { aoEnviar: fn(async () => undefined) },
} satisfies Meta<typeof ChangePasswordForm>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Valido: Story = {
  play: async ({ canvasElement, args }) => {
    await preencher(within(canvasElement));
    await expect(args.aoEnviar).toHaveBeenCalledWith({
      currentPassword: 'senha1234',
      password: 'novaSenha9',
      passwordConfirmation: 'novaSenha9',
    });
  },
};

export const SenhaAtualErrada: Story = {
  args: {
    aoEnviar: fn(async () => {
      throw new ApiError(422, 'VALIDATION_ERROR', 'Confira os campos destacados.', {
        currentPassword: ['A senha atual não confere.'],
      });
    }),
  },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await preencher(tela);
    await expect(await tela.findByText('A senha atual não confere.')).toBeVisible();
    await expect(tela.getByLabelText('Senha atual')).toHaveAttribute('aria-invalid', 'true');
  },
};
```

`src/features/auth/components/DeleteAccountSheet.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, waitFor, within } from 'storybook/test';
import { ApiError } from '@/lib/api/errors';
import { DeleteAccountSheet } from './DeleteAccountSheet';

const meta = {
  title: 'Conta/DeleteAccountSheet',
  component: DeleteAccountSheet,
  args: { aberta: true, aoFechar: fn(), aoApagar: fn(async () => undefined) },
} satisfies Meta<typeof DeleteAccountSheet>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Aberta: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    const dialogo = tela.getByRole('dialog', { name: 'Apagar sua conta?' });
    await waitFor(() => expect(dialogo).toHaveFocus());
    await expect(tela.getByText('Não dá para desfazer.')).toBeVisible();

    await userEvent.click(tela.getByRole('button', { name: 'Apagar tudo' }));
    await expect(tela.getByText('Digite sua senha.')).toBeVisible();
    await expect(args.aoApagar).not.toHaveBeenCalled();

    await userEvent.type(tela.getByLabelText('Sua senha'), 'senha1234');
    await userEvent.click(tela.getByRole('button', { name: 'Apagar tudo' }));
    await expect(args.aoApagar).toHaveBeenCalledWith('senha1234');
  },
};

export const SenhaIncorreta: Story = {
  args: {
    aoApagar: fn(async () => {
      throw new ApiError(422, 'VALIDATION_ERROR', 'Confira os campos destacados.', { password: ['A senha não confere.'] });
    }),
  },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await userEvent.type(tela.getByLabelText('Sua senha'), 'errada123');
    await userEvent.click(tela.getByRole('button', { name: 'Apagar tudo' }));
    await expect(await tela.findByText('A senha não confere.')).toBeVisible();
  },
};

export const Apagando: Story = {
  args: { aoApagar: fn(() => new Promise<never>(() => {})) },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await userEvent.type(tela.getByLabelText('Sua senha'), 'senha1234');
    await userEvent.click(tela.getByRole('button', { name: 'Apagar tudo' }));
    await expect(await tela.findByRole('button', { name: 'Apagando…' })).toHaveAttribute('aria-busy', 'true');
  },
};

export const Cancelar: Story = {
  play: async ({ canvasElement, args }) => {
    await userEvent.click(within(canvasElement).getByRole('button', { name: 'Cancelar' }));
    await expect(args.aoFechar).toHaveBeenCalledOnce();
  },
};
```

`src/features/auth/components/TrocarSenhaTela.integration.test.tsx`:
```tsx
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { erroDaApi, url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { TrocarSenhaTela } from './TrocarSenhaTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => redefinirNavegacao());

async function trocar() {
  const usuario = userEvent.setup();
  await usuario.type(screen.getByLabelText('Senha atual'), 'senha1234');
  await usuario.type(screen.getByLabelText('Nova senha'), 'novaSenha9');
  await usuario.type(screen.getByLabelText('Confirme a nova senha'), 'novaSenha9');
  await usuario.click(screen.getByRole('button', { name: 'Salvar' }));
}

describe('Trocar senha', () => {
  it('troca, avisa e volta para Configurações (RF05)', async () => {
    let corpo: unknown;
    server.use(
      http.put(url('/me/password'), async ({ request }) => {
        corpo = await request.json();
        return HttpResponse.json({ message: 'Senha trocada.' });
      }),
    );

    renderizar(<TrocarSenhaTela />);
    await trocar();

    await waitFor(() => expect(roteador.push).toHaveBeenCalledWith('/perfil/configuracoes'));
    expect(await screen.findByText('Senha trocada.')).toBeInTheDocument();
    expect(corpo).toEqual({ current_password: 'senha1234', password: 'novaSenha9', password_confirmation: 'novaSenha9' });
  });

  it('mostra "A senha atual não confere." no campo certo', async () => {
    server.use(
      http.put(url('/me/password'), () =>
        erroDaApi(422, 'VALIDATION_ERROR', 'Confira os campos destacados.', {
          errors: { current_password: ['A senha atual não confere.'] },
        }),
      ),
    );

    renderizar(<TrocarSenhaTela />);
    await trocar();

    expect(await screen.findByText('A senha atual não confere.')).toBeInTheDocument();
    expect(screen.getByLabelText('Senha atual')).toHaveAttribute('aria-invalid', 'true');
    expect(roteador.push).not.toHaveBeenCalled();
  });
});
```

`src/features/auth/components/ContaSection.integration.test.tsx`:
```tsx
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { recarregarEm } from '@/lib/navegar';
import { usuarioApi } from '@/mocks/fixtures/usuario';
import { erroDaApi, url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { redefinirNavegacao } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { ContaSection } from './ContaSection';

vi.mock('next/navigation', () => import('@/test/next-navigation'));
vi.mock('@/lib/navegar', () => ({ recarregarEm: vi.fn() }));

beforeEach(() => {
  redefinirNavegacao();
  vi.mocked(recarregarEm).mockReset();
  server.use(http.get(url('/me'), () => HttpResponse.json({ data: usuarioApi })));
});

describe('Sua conta', () => {
  it('mostra o e-mail e leva para trocar a senha', async () => {
    renderizar(<ContaSection />);

    expect(await screen.findByText('camila.reus@gmail.com')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Trocar senha/ })).toHaveAttribute('href', '/perfil/configuracoes/senha');
  });

  it('sair encerra a sessão e recarrega nas Boas-vindas (RF03)', async () => {
    let saiu = false;
    server.use(
      http.post(url('/logout'), () => {
        saiu = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    renderizar(<ContaSection />);
    await userEvent.setup().click(await screen.findByRole('button', { name: /Sair desta conta/ }));

    await waitFor(() => expect(recarregarEm).toHaveBeenCalledWith('/'));
    expect(saiu).toBe(true);
  });

  it('apagar com a senha certa manda a senha e recarrega avisando (RF06)', async () => {
    let corpo: unknown;
    server.use(
      http.delete(url('/me'), async ({ request }) => {
        corpo = await request.json();
        return new HttpResponse(null, { status: 204 });
      }),
    );
    const usuario = userEvent.setup();

    renderizar(<ContaSection />);
    await usuario.click(await screen.findByRole('button', { name: 'Apagar minha conta e meus dados' }));
    const folha = await screen.findByRole('dialog', { name: 'Apagar sua conta?' });
    await usuario.type(within(folha).getByLabelText('Sua senha'), 'senha1234');
    await usuario.click(within(folha).getByRole('button', { name: 'Apagar tudo' }));

    await waitFor(() => expect(recarregarEm).toHaveBeenCalledWith('/?conta=apagada'));
    expect(corpo).toEqual({ password: 'senha1234' });
  });

  it('senha errada fica na folha, sem apagar', async () => {
    server.use(
      http.delete(url('/me'), () =>
        erroDaApi(422, 'VALIDATION_ERROR', 'Confira os campos destacados.', { errors: { password: ['A senha não confere.'] } }),
      ),
    );
    const usuario = userEvent.setup();

    renderizar(<ContaSection />);
    await usuario.click(await screen.findByRole('button', { name: 'Apagar minha conta e meus dados' }));
    const folha = await screen.findByRole('dialog', { name: 'Apagar sua conta?' });
    await usuario.type(within(folha).getByLabelText('Sua senha'), 'errada123');
    await usuario.click(within(folha).getByRole('button', { name: 'Apagar tudo' }));

    expect(await within(folha).findByText('A senha não confere.')).toBeInTheDocument();
    expect(recarregarEm).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose run --rm web bash -c "npx vitest run --project integration src/features/auth/components && npx vitest run --project storybook src/features/auth"`
Expected: FAIL — `./ChangePasswordForm`, `./TrocarSenhaTela`, `./DeleteAccountSheet`, `./ContaSection` não resolvem.

- [ ] **Step 3: Implementar**

`src/features/auth/components/ChangePasswordForm.tsx`:
```tsx
"use client";

import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { FormError } from "@/components/ui/FormError";
import { PasswordField } from "@/components/ui/PasswordField";
import { type ApiError, comoApiError, primeirasMensagens } from "@/lib/api/errors";
import { cascata } from "@/lib/motion";
import { type DadosTrocaSenha, type Erros, validarTrocaSenha } from "../schemas";

/** N06 — senha atual + nova + confirmação (apresentacional). */
export function ChangePasswordForm({ aoEnviar }: { aoEnviar: (dados: DadosTrocaSenha) => Promise<unknown> }) {
  const [dados, setDados] = useState<DadosTrocaSenha>({ currentPassword: "", password: "", passwordConfirmation: "" });
  const [erros, setErros] = useState<Erros<keyof DadosTrocaSenha>>({});
  const [erroGeral, setErroGeral] = useState<ApiError | null>(null);
  const [enviando, setEnviando] = useState(false);

  function mudar(campo: keyof DadosTrocaSenha, valor: string) {
    setDados((atual) => ({ ...atual, [campo]: valor }));
    setErros((atual) => ({ ...atual, [campo]: undefined }));
  }

  async function enviar(evento: React.FormEvent) {
    evento.preventDefault();
    if (enviando) return;
    const encontrados = validarTrocaSenha(dados);
    setErros(encontrados);
    setErroGeral(null);
    if (Object.keys(encontrados).length > 0) return;

    setEnviando(true);
    try {
      await aoEnviar(dados);
    } catch (e) {
      const erro = comoApiError(e);
      if (erro.code === "VALIDATION_ERROR") setErros(primeirasMensagens(erro.fieldErrors) as Erros<keyof DadosTrocaSenha>);
      else setErroGeral(erro);
      setEnviando(false);
    }
  }

  return (
    <form noValidate onSubmit={enviar} className="flex flex-1 flex-col">
      <div className="flex flex-col gap-4">
        <PasswordField
          id="senha-atual"
          label="Senha atual"
          autoComplete="current-password"
          value={dados.currentPassword}
          onChange={(e) => mudar("currentPassword", e.target.value)}
          erro={erros.currentPassword}
          disabled={enviando}
          className="animate-entra"
          style={cascata(0, 60, 160)}
        />
        <PasswordField
          id="nova-senha"
          label="Nova senha"
          autoComplete="new-password"
          ajuda="8 ou mais, com letra e número"
          value={dados.password}
          onChange={(e) => mudar("password", e.target.value)}
          erro={erros.password}
          disabled={enviando}
          className="animate-entra"
          style={cascata(1, 60, 160)}
        />
        <PasswordField
          id="confirmacao"
          label="Confirme a nova senha"
          autoComplete="new-password"
          value={dados.passwordConfirmation}
          onChange={(e) => mudar("passwordConfirmation", e.target.value)}
          erro={erros.passwordConfirmation}
          disabled={enviando}
          className="animate-entra"
          style={cascata(2, 60, 160)}
        />
      </div>
      <div className="mt-auto animate-entra pt-8" style={cascata(3, 60, 160)}>
        <FormError erro={erroGeral} />
        <Button type="submit" carregando={enviando} rotuloCarregando="Salvando…">
          Salvar
        </Button>
      </div>
    </form>
  );
}
```

`src/features/auth/components/TrocarSenhaTela.tsx`:
```tsx
"use client";

import { useRouter } from "next/navigation";
import { useToast } from "@/components/ui/Toaster";
import { useUpdatePassword } from "../hooks";
import type { DadosTrocaSenha } from "../schemas";
import { AuthScreen } from "./AuthScreen";
import { ChangePasswordForm } from "./ChangePasswordForm";

export function TrocarSenhaTela() {
  const trocar = useUpdatePassword();
  const router = useRouter();
  const avisar = useToast();

  async function salvar(dados: DadosTrocaSenha) {
    await trocar.mutateAsync(dados);
    avisar({ texto: "Senha trocada." });
    router.push("/perfil/configuracoes");
  }

  return (
    <AuthScreen
      voltarPara="/perfil/configuracoes"
      rotuloVoltar="Voltar para configurações"
      titulo="Trocar senha"
      texto="Depois da troca, os outros aparelhos saem da conta. Este continua conectado."
    >
      <ChangePasswordForm aoEnviar={salvar} />
    </AuthScreen>
  );
}
```

`src/features/auth/components/DeleteAccountSheet.tsx`:
```tsx
"use client";

import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { FormError } from "@/components/ui/FormError";
import { PasswordField } from "@/components/ui/PasswordField";
import { Sheet } from "@/components/ui/Sheet";
import { type ApiError, comoApiError } from "@/lib/api/errors";
import { cascata } from "@/lib/motion";
import { MENSAGENS } from "../schemas";

const O_QUE_SOME = [
  "Seu perfil e suas preferências",
  "Seu plano alimentar",
  "Suas pesagens",
  "Suas conversas com o Nutri",
  "Suas avaliações",
];

/** N07 — confirmação com senha; `aoApagar` rejeita com ApiError quando a senha não confere. */
export function DeleteAccountSheet({
  aberta,
  aoFechar,
  aoApagar,
}: {
  aberta: boolean;
  aoFechar: () => void;
  aoApagar: (senha: string) => Promise<unknown>;
}) {
  const [senha, setSenha] = useState("");
  const [erro, setErro] = useState<string | undefined>();
  const [erroGeral, setErroGeral] = useState<ApiError | null>(null);
  const [apagando, setApagando] = useState(false);

  async function apagar(evento: React.FormEvent) {
    evento.preventDefault();
    if (apagando) return;
    setErroGeral(null);
    if (!senha) {
      setErro(MENSAGENS.senhaVazia);
      return;
    }

    setApagando(true);
    try {
      await aoApagar(senha);
    } catch (e) {
      const falha = comoApiError(e);
      if (falha.code === "VALIDATION_ERROR") setErro(falha.fieldErrors.password?.[0] ?? falha.message);
      else setErroGeral(falha);
      setApagando(false);
    }
  }

  return (
    <Sheet aberta={aberta} aoFechar={aoFechar} tom="destrutivo" titulo="Apagar sua conta?" descricao="Isto apaga, de vez:">
      <form noValidate onSubmit={apagar}>
        <ul className="mt-3 flex flex-col gap-1.5 text-[14px] text-tinta">
          {O_QUE_SOME.map((item, i) => (
            <li key={item} className="flex animate-entra items-center gap-2.5" style={cascata(i, 45, 100)}>
              <span aria-hidden="true" className="size-1.5 rounded-full bg-alerta" />
              {item}
            </li>
          ))}
        </ul>
        <p className="mt-4 animate-pop rounded-xl bg-alerta-fraca px-3.5 py-2.5 text-[13.5px] font-semibold text-alerta-texto">
          Não dá para desfazer.
        </p>
        <PasswordField
          id="senha-apagar"
          label="Sua senha"
          autoComplete="current-password"
          value={senha}
          onChange={(e) => {
            setSenha(e.target.value);
            setErro(undefined);
          }}
          erro={erro}
          disabled={apagando}
          className="mt-5"
        />
        <div className="mt-6">
          <FormError erro={erroGeral} />
          <Button type="submit" variante="destrutiva" carregando={apagando} rotuloCarregando="Apagando…">
            Apagar tudo
          </Button>
          <Button variante="texto" className="mt-1" onClick={aoFechar} disabled={apagando}>
            Cancelar
          </Button>
        </div>
      </form>
    </Sheet>
  );
}
```

`src/features/auth/components/ContaSection.tsx`:
```tsx
"use client";

import Link from "next/link";
import { useState } from "react";
import { IconeAvancar } from "@/components/icons";
import { useToast } from "@/components/ui/Toaster";
import { recarregarEm } from "@/lib/navegar";
import { useDeleteAccount, useLogout, useMe } from "../hooks";
import { DeleteAccountSheet } from "./DeleteAccountSheet";

const linha = "flex min-h-[62px] w-full items-center gap-3.5 border-b border-fio py-3 text-left";

/** Bloco "Sua conta" de Configurações (S19): e-mail, trocar senha, sair, apagar. */
export function ContaSection() {
  const { data: user } = useMe();
  const sair = useLogout();
  const apagar = useDeleteAccount();
  const avisar = useToast();
  const [folhaAberta, setFolhaAberta] = useState(false);

  async function aoSair() {
    try {
      await sair.mutateAsync();
      recarregarEm("/"); // recarga = cache, formulários e sessão do cliente zerados (RF03)
    } catch {
      avisar({ texto: "Não deu para sair agora. Tente de novo." });
    }
  }

  async function aoApagar(senha: string) {
    await apagar.mutateAsync(senha);
    recarregarEm("/?conta=apagada");
  }

  return (
    <>
      <h2 className="mt-[22px] text-[12.5px] font-semibold text-fumo">Sua conta</h2>
      <div className="mt-2.5 rounded-[20px] bg-white px-[18px]">
        <div className="flex min-h-[62px] items-center border-b border-fio py-3">
          <div className="flex-1">
            <p className="text-[15px] font-semibold">E-mail</p>
            <p className="mt-0.5 text-[13px] text-fumo">{user?.email ?? "…"}</p>
          </div>
        </div>
        <Link href="/perfil/configuracoes/senha" className={linha}>
          <span className="flex-1 text-[15px] font-semibold">Trocar senha</span>
          <IconeAvancar size={18} className="shrink-0 text-fumo" />
        </Link>
        <button type="button" onClick={aoSair} disabled={sair.isPending} className={linha}>
          <span className="flex-1 text-[15px] font-semibold">{sair.isPending ? "Saindo…" : "Sair desta conta"}</span>
          <IconeAvancar size={18} className="shrink-0 text-fumo" />
        </button>
        <button
          type="button"
          onClick={() => setFolhaAberta(true)}
          className="flex min-h-[62px] w-full items-center py-3 text-left"
        >
          <span className="flex-1 text-[15px] font-semibold text-alerta">Apagar minha conta e meus dados</span>
        </button>
      </div>
      <DeleteAccountSheet aberta={folhaAberta} aoFechar={() => setFolhaAberta(false)} aoApagar={aoApagar} />
    </>
  );
}
```

`src/app/(app)/perfil/configuracoes/senha/page.tsx`:
```tsx
import type { Metadata } from "next";
import { TrocarSenhaTela } from "@/features/auth/components/TrocarSenhaTela";

export const metadata: Metadata = { title: "Trocar senha · Prato Forte" };

export default function Page() {
  return <TrocarSenhaTela />;
}
```

Em `src/app/(app)/perfil/configuracoes/page.tsx`, trocar o bloco de "Sua conta" (do `<h2 …>Sua conta</h2>` até o `</div>` que fecha a lista com "Apagar minha conta e meus dados") por `<ContaSection />`:
```bash
python3 - <<'EOF'
p='src/app/(app)/perfil/configuracoes/page.tsx'
s=open(p).read()
inicio=s.index('        <h2 className="mt-[22px] text-[12.5px] font-semibold text-fumo">Sua conta</h2>')
fim=s.index('        <div className="mt-[22px] border-t border-linha pt-[18px]">')
s=s[:inicio]+'        <ContaSection />\n\n'+s[fim:]
s=s.replace('import { usePlan } from "@/lib/plan-store";','import { usePlan } from "@/lib/plan-store";\nimport { ContaSection } from "@/features/auth/components/ContaSection";')
open(p,'w').write(s)
EOF
grep -n "ContaSection\|Sua conta\|Trocar senha" "src/app/(app)/perfil/configuracoes/page.tsx"
```
Expected: só as duas linhas de `ContaSection` (import e uso). Se o `lint` acusar imports que ficaram sem uso (`Link`, `IconeAvancar`), remova-os desse arquivo.

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose run --rm web bash -c "npx vitest run --project integration src/features/auth/components && npx vitest run --project storybook src/features/auth"`
Expected: PASS.

- [ ] **Step 5: Refinar com `frontend-design` (D11) e verificar**

Carregue `frontend-design:frontend-design` para `TrocarSenhaTela`/`ChangePasswordForm` e `DeleteAccountSheet` (tom destrutivo: alerta só no título, nos marcadores e no botão; a entrada da lista em cascata e o aviso "Não dá para desfazer." com `animate-pop`), com as restrições da Task 6.

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm run build"`
Expected: tudo verde.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "feat(auth): trocar senha, sair e apagar conta nas Configurações

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: E2E (E2E-01 parte de conta, E2E-02, E2E-10), seeder no backend e CI do front

**Files:**
- Repo backend: Create `database/seeders/E2ESeeder.php`, `tests/Feature/Seeders/E2ESeederTest.php`
- Repo front: Create `playwright.config.ts`, `e2e/contas.ts`, `e2e/auth.spec.ts`, `e2e/cadastro.spec.ts`, `e2e/senha.spec.ts`, `.github/workflows/ci.yml`; Modify `README.md`

**Interfaces:**
- Consumes: todas as telas das Tasks 5–8; backend do Plano 01 (API, Mailpit).
- Produces:
  - `E2ESeeder` (rodar com `php artisan migrate:fresh --seeder=E2ESeeder --force`): `concluido@e2e.pratoforte.test` (onboarding completo), `novo@e2e.pratoforte.test` (parado em `atividade`), `senha-chromium@e2e.pratoforte.test` e `senha-webkit@e2e.pratoforte.test` (completos) — todos com senha `senha1234`. Os planos seguintes acrescentam contas aqui (ex.: alergia a castanhas no Plano 03).
  - `npm run e2e` (Playwright, Chromium 390×844 e WebKit iPhone 14).
  - Workflow `ci` com os jobs `frontend` (lint, tipos, unit+integration+storybook, audit) e `e2e`.

- [ ] **Step 1 (backend): teste do seeder que deve falhar**

```bash
cd /home/alvez/atividade-extensionista/backend
git switch plano-02-front
```

`tests/Feature/Seeders/E2ESeederTest.php`:
```php
<?php

use App\Models\User;
use Database\Seeders\E2ESeeder;

it('cria as contas fixas dos testes E2E do front', function () {
    $this->seed(E2ESeeder::class);

    $email = fn (string $email) => User::where('email', $email)->sole();

    expect($email('concluido@e2e.pratoforte.test')->profile->isOnboarded())->toBeTrue()
        ->and($email('novo@e2e.pratoforte.test')->profile->nextStep()?->value)->toBe('atividade')
        ->and($email('senha-chromium@e2e.pratoforte.test')->profile->isOnboarded())->toBeTrue()
        ->and($email('senha-webkit@e2e.pratoforte.test')->profile->isOnboarded())->toBeTrue();
});

it('usa a senha combinada com o front', function () {
    $this->seed(E2ESeeder::class);

    $this->postJson('/api/v1/login', ['email' => 'concluido@e2e.pratoforte.test', 'password' => 'senha1234'])
        ->assertOk()
        ->assertJsonPath('data.onboarding_completed', true);
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Seeders/E2ESeederTest.php`
Expected: FAIL — `Class "Database\Seeders\E2ESeeder" not found`.

- [ ] **Step 2 (backend): implementar, testar e commitar**

`database/seeders/E2ESeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Contas fixas dos testes E2E do front (senha de todas: senha1234).
 * Rode sobre banco limpo: php artisan migrate:fresh --seeder=E2ESeeder --force
 */
class E2ESeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->onboarded()->create(['name' => 'Camila Réus', 'email' => 'concluido@e2e.pratoforte.test']);

        User::factory()->withCompletedSteps(['objetivo', 'dados'])
            ->create(['name' => 'Nina Souza', 'email' => 'novo@e2e.pratoforte.test']);

        // Uma conta por navegador: o E2E-10 troca a senha, e os projetos rodam em paralelo no CI.
        foreach (['chromium', 'webkit'] as $navegador) {
            User::factory()->onboarded()->create(['name' => 'Rafa Lima', 'email' => "senha-{$navegador}@e2e.pratoforte.test"]);
        }
    }
}
```

Run: `docker compose run --rm api php artisan test && docker compose run --rm --no-deps api composer lint`
Expected: PASS e lint OK.

```bash
git add -A && git commit -m "test: E2ESeeder com as contas fixas dos testes de ponta a ponta do front

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 3 (front): escrever os E2E**

```bash
cd /home/alvez/atividade-extensionista/frontend
```

`playwright.config.ts`:
```ts
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: 'http://localhost:3000',
    locale: 'pt-BR',
    timezoneId: 'America/Sao_Paulo',
    trace: 'retain-on-failure',
    video: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 }, hasTouch: true } },
    { name: 'webkit', use: { ...devices['iPhone 14'] } },
  ],
  webServer: {
    command: 'npm run build && npm run start',
    url: 'http://localhost:3000',
    reuseExistingServer: !process.env.CI,
    timeout: 240_000,
  },
});
```

`e2e/contas.ts`:
```ts
import { type APIRequestContext, expect, type Page } from '@playwright/test';

/** Contas do E2ESeeder (repo backend). */
export const SENHA = 'senha1234';
export const CONCLUIDO = 'concluido@e2e.pratoforte.test';
export const NOVO = 'novo@e2e.pratoforte.test';
export const MAILPIT = process.env.MAILPIT_URL ?? 'http://localhost:8025';

export async function entrar(page: Page, email: string, senha = SENHA) {
  await page.goto('/entrar');
  await page.getByLabel('E-mail').fill(email);
  await page.getByLabel('Senha', { exact: true }).fill(senha);
  await page.getByRole('button', { name: 'Entrar' }).click();
}

export async function limparCaixa(request: APIRequestContext, email: string) {
  await request.delete(`${MAILPIT}/api/v1/search`, { params: { query: `to:"${email}"` } });
}

/** Espera o e-mail de redefinição chegar ao Mailpit e devolve o link para o front. */
export async function linkDeRedefinicao(request: APIRequestContext, email: string): Promise<string> {
  let link = '';
  await expect
    .poll(
      async () => {
        const busca = await request.get(`${MAILPIT}/api/v1/search`, { params: { query: `to:"${email}"` } });
        const { messages } = (await busca.json()) as { messages?: { ID: string }[] };
        if (!messages?.length) return '';
        const mensagem = (await (await request.get(`${MAILPIT}/api/v1/message/${messages[0].ID}`)).json()) as { Text: string };
        link = mensagem.Text.match(/http:\/\/localhost:3000\/senha\/redefinir\?\S+/)?.[0] ?? '';
        return link;
      },
      { timeout: 15_000 },
    )
    .not.toBe('');
  return link;
}
```

`e2e/auth.spec.ts` (E2E-02):
```ts
import { expect, test } from '@playwright/test';
import { CONCLUIDO, entrar, NOVO, SENHA } from './contas';

test('rota protegida sem sessão vai para o login e volta depois (CA09)', async ({ page }) => {
  await page.goto('/hoje');
  await expect(page).toHaveURL(/\/entrar\?voltar=%2Fhoje$/);

  await page.getByLabel('E-mail').fill(CONCLUIDO);
  await page.getByLabel('Senha', { exact: true }).fill(SENHA);
  await page.getByRole('button', { name: 'Entrar' }).click();

  await expect(page).toHaveURL(/\/hoje$/);
});

test('senha errada mostra o erro sem dizer qual campo (CA04)', async ({ page }) => {
  await entrar(page, CONCLUIDO, 'errada123');

  // O Next também tem um role="alert" (anunciador de rota); filtra pelo texto.
  await expect(page.getByRole('alert').filter({ hasText: 'E-mail ou senha incorretos.' })).toBeVisible();
  await expect(page).toHaveURL(/\/entrar/);
});

test('onboarding pela metade leva para a etapa pendente (CA03)', async ({ page }) => {
  await entrar(page, NOVO);

  await expect(page).toHaveURL(/\/onboarding\/atividade$/);
});

test('sair volta às Boas-vindas e fecha o app (RF03)', async ({ page }) => {
  await entrar(page, CONCLUIDO);
  await expect(page).toHaveURL(/\/hoje$/);

  await page.goto('/perfil/configuracoes');
  await page.getByRole('button', { name: 'Sair desta conta' }).click();
  await expect(page).toHaveURL('http://localhost:3000/');

  await page.goto('/hoje');
  await expect(page).toHaveURL(/\/entrar\?voltar=%2Fhoje$/);
});
```

`e2e/cadastro.spec.ts` (E2E-01, parte de conta):
```ts
import { expect, test } from '@playwright/test';

test('das Boas-vindas ao cadastro e à primeira etapa do onboarding (CA01)', async ({ page }, info) => {
  const email = `cadastro-${info.project.name}-${Date.now()}@e2e.pratoforte.test`;

  await page.goto('/');
  await page.getByRole('link', { name: 'Montar meu plano' }).click();
  await expect(page).toHaveURL(/\/cadastro$/);

  await page.getByLabel('Nome completo').fill('Teste de Ponta');
  await page.getByLabel('E-mail').fill(email);
  await page.getByLabel('Senha', { exact: true }).fill('senha1234');
  await page.getByRole('checkbox', { name: /Li e aceito/ }).click();
  await page.getByRole('button', { name: 'Criar conta' }).click();

  await expect(page).toHaveURL(/\/onboarding\/objetivo$/);
});
```

`e2e/senha.spec.ts` (E2E-10):
```ts
import { expect, test } from '@playwright/test';
import { limparCaixa, linkDeRedefinicao } from './contas';

test('recuperar a senha pelo e-mail e entrar com a nova', async ({ page, request }, info) => {
  const email = `senha-${info.project.name}@e2e.pratoforte.test`;
  const nova = `nova${Date.now()}a`;
  await limparCaixa(request, email);

  await page.goto('/entrar');
  await page.getByRole('link', { name: 'Esqueci minha senha' }).click();
  await page.getByLabel('E-mail').fill(email);
  await page.getByRole('button', { name: 'Enviar link' }).click();
  await expect(page.getByRole('heading', { name: 'Confira seu e-mail' })).toBeVisible();

  await page.goto(await linkDeRedefinicao(request, email));
  await page.getByLabel('Nova senha', { exact: true }).fill(nova);
  await page.getByLabel('Confirme a nova senha').fill(nova);
  await page.getByRole('button', { name: 'Salvar senha' }).click();

  await expect(page.getByText('Senha nova salva. Entre com ela.')).toBeVisible();
  await expect(page.getByLabel('E-mail')).toHaveValue(email);
  await page.getByLabel('Senha', { exact: true }).fill(nova);
  await page.getByRole('button', { name: 'Entrar' }).click();
  await expect(page).toHaveURL(/\/hoje$/);
});
```

- [ ] **Step 4: Rodar os E2E contra o backend real**

```bash
cd /home/alvez/atividade-extensionista/backend
docker compose up -d
docker compose exec api php artisan migrate:fresh --seeder=E2ESeeder --force
cd /home/alvez/atividade-extensionista/frontend
docker compose run --rm web npm run e2e
```
Expected: 6 testes × 2 navegadores = **12 passed**. Limites do backend a lembrar se rodar muitas vezes seguidas: 3 cadastros/min por IP e 3 pedidos de link/hora por e-mail — se aparecer 429, espere e rode de novo (ou refaça o `migrate:fresh`, que não zera o cache de limites: `docker compose exec api php artisan cache:clear`).

- [ ] **Step 5: CI do front**

`.github/workflows/ci.yml`:
```yaml
name: ci

on:
  push:
  pull_request:

jobs:
  frontend:
    runs-on: ubuntu-latest
    container:
      image: mcr.microsoft.com/playwright:v1.63.0-noble
    steps:
      - uses: actions/checkout@v4
      - run: npm ci
      - run: npm run lint # inclui a regra "sem mocks em produção" (D12)
      - run: npm run typecheck
      - run: npm test # unit + integration (MSW) + storybook (Chromium + axe)
      - run: npm audit --audit-level=high

  e2e:
    if: github.event_name == 'pull_request' || github.ref == 'refs/heads/main'
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: prato_forte
          MYSQL_USER: prato
          MYSQL_PASSWORD: prato
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping -h localhost -uroot -proot"
          --health-interval=5s
          --health-timeout=5s
          --health-retries=30
      mailpit:
        image: axllent/mailpit
        ports:
          - 1025:1025
          - 8025:8025
    env:
      DB_HOST: 127.0.0.1
      DB_PORT: 3306
      DB_DATABASE: prato_forte
      DB_USERNAME: prato
      DB_PASSWORD: prato
      MAIL_HOST: 127.0.0.1
    steps:
      - uses: actions/checkout@v4
        with:
          path: frontend
      - uses: actions/checkout@v4
        with:
          repository: windstonp/backend-atividade-extensionista-prato-forte
          # Repo privado? crie o secret BACKEND_REPO_TOKEN (PAT só-leitura) no repo do front.
          token: ${{ secrets.BACKEND_REPO_TOKEN || github.token }}
          path: backend
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo_mysql, intl, zip, bcmath
          coverage: none
      - name: API (Laravel) com as contas do E2E
        working-directory: backend
        run: |
          composer install --no-interaction --prefer-dist --no-progress
          cp .env.example .env
          php artisan key:generate
          php artisan migrate:fresh --seeder=E2ESeeder --force
          php artisan serve --host=0.0.0.0 --port=8000 > /tmp/api.log 2>&1 &
      - uses: actions/setup-node@v4
        with:
          node-version: 22
          cache: npm
          cache-dependency-path: frontend/package-lock.json
      - working-directory: frontend
        run: |
          npm ci
          npx playwright install --with-deps chromium webkit
      - working-directory: frontend
        run: npm run e2e
      - if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: playwright
          path: |
            frontend/test-results
            frontend/playwright-report
            /tmp/api.log
```

Acrescentar ao `README.md` do front (seção nova no topo, depois do título):
````markdown
## Rodar (Docker — o WSL não precisa de Node)

```bash
docker compose run --rm web npm ci          # 1ª vez
docker compose up web                        # Next em http://localhost:3000 (API: repo backend, :8000)
docker compose run --rm web npm test         # unit + integração (MSW) + Storybook (Chromium + axe)
docker compose run --rm web npm run lint     # inclui a guarda "sem mocks em produção" (D12)
docker compose run --rm web npm run storybook  # http://localhost:6006
```

E2E (precisa do backend no ar e semeado):
```bash
(cd ../backend && docker compose up -d && docker compose exec api php artisan migrate:fresh --seeder=E2ESeeder --force)
docker compose run --rm web npm run e2e
```
````

- [ ] **Step 6: Verificação final e commits**

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm audit --audit-level=high"`
Expected: tudo verde. Se o `npm audit` apontar vulnerabilidade *high* numa dependência de desenvolvimento sem correção disponível, registre no ledger (Ruling) com o pacote e o motivo, e troque o passo do CI por `npm audit --audit-level=high --omit=dev` — nunca silencie uma dependência de produção.

```bash
git add -A && git commit -m "test(e2e): cadastro, login/logout/proteção de rota e recuperação de senha; CI do front

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 7: Conferir a DoD da spec 01 (parte front)**

Marcar em `specs/01-contas-autenticacao/spec.md` §9 (repo backend), no mesmo commit do seeder ou num `docs(specs)`:
- CA01–CA09 cobertos (CA01, CA03, CA09 agora também pelo front; E2E-01 só a parte de cadastro — o resto do E2E-01 é dos Planos 03/04).
- Telas N01–N04, N06, N07 com stories + `play`; integração com MSW; `proxy.ts` + layouts; "Pular para o app" removido; "Já tenho conta" → `/entrar`; E2E-02 e E2E-10 verdes; axe limpo.
- Pendente fora deste plano: remover a inscrição Web Push no logout (Plano 07); texto final do termo (P3).
