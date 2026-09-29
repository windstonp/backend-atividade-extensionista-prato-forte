# Arquitetura do frontend (Next.js 16)

Base existente 🔵: Next.js 16.3 (App Router) + React 19.2 + TypeScript 5 + Tailwind CSS 4, sem biblioteca de estado nem de testes. Design tokens em `src/app/globals.css` (`@theme`), camada de movimento em `globals.css` + `src/lib/motion.ts`.

> Antes de escrever código Next, ler `frontend/node_modules/next/dist/docs/` (regra do `frontend/AGENTS.md`): esta versão tem mudanças — ex.: `middleware.ts` virou **`proxy.ts`**.

## 1. Princípios

1. **Telas não sabem de onde vem o dado** (já é assim no mock): toda I/O passa por `src/lib/api/`.
2. **Uma fonte de verdade para o servidor**: o estado vindo da API é cache, não cópia manual em Context. 🟡 Adotar **TanStack Query v5** — cache, polling (plano gerando), atualização otimista (marcar refeição) e reversão em erro, sem reinventar isso no `plan-store.tsx`.
3. **Estado só de cliente** (rascunho de onboarding antes de salvar, sheet aberta, toast) fica local no componente ou em Context pequeno.
4. **Sem `localStorage` para dado de domínio**: o `plan-store` 🔵 guardava o plano no `localStorage`; com a API, o servidor é a verdade. `localStorage` só para conveniências (último período escolhido na Evolução).
5. **Componentes da biblioteca** (`08-design-system`) sempre que existir equivalente.
6. **Nenhum dado de domínio fixo no código de produção** ✅ D12: `lib/mock-data.ts` é movido para `src/mocks/fixtures/`; só stories (`*.stories.tsx`), testes (`*.test.*`, `e2e/`) e o bootstrap do MSW (`src/mocks/browser.ts`, ativado por `NEXT_PUBLIC_API_MOCKING`) podem importar de `src/mocks/`. Garantido por ESLint:
   ```js
   // eslint.config.mjs (trecho)
   { files: ['src/**/*.{ts,tsx}'],
     ignores: ['src/mocks/**', '**/*.stories.tsx', '**/*.test.{ts,tsx}'],
     rules: { 'no-restricted-imports': ['error', { patterns: [
       { group: ['@/mocks/*', '**/mocks/**', '**/mock-data'], message: 'Dado mockado só em stories, testes e MSW. Use src/lib/api.' } ] }] } }
   ```
   Exceção única: o ponto de ativação do MSW no `app/layout.tsx`, via `import()` dinâmico condicionado à variável — liberado com comentário `eslint-disable-next-line` justificado. Transição (Plano 02): o antigo `lib/api.ts` virou `src/lib/mock-api.ts` e, junto com as telas ainda não migradas, fica num bloco "legado" do `eslint.config.mjs` que cada plano de feature encolhe; a pasta `src/lib/api/` é o cliente real.

## 2. Estrutura de diretórios

```
frontend/
├── .storybook/                     # main.ts, preview.tsx (tokens, fontes, MSW, reduced-motion toggle)
├── e2e/                            # Playwright (ver estrategia-de-testes.md)
├── public/
│   ├── manifest.webmanifest        # PWA (necessário para Web Push no iOS)
│   └── sw.js                       # service worker: push + notificationclick
├── src/
│   ├── proxy.ts                    # guarda de rota por cookie de sessão
│   ├── app/                        # rotas (App Router) — páginas finas
│   │   ├── page.tsx                # Boas-vindas
│   │   ├── (publico)/cadastro/  entrar/  senha/esqueci/  senha/redefinir/
│   │   ├── onboarding/{objetivo,dados,atividade,preferencias,restricoes,rotina,resumo,gerando,pronto}/
│   │   └── (app)/hoje/  dieta/  dieta/[slot]/  nutri/  nutri/[conversa]/  evolucao/  evolucao/peso/
│   │            perfil/  perfil/preferencias/  perfil/configuracoes/  perfil/configuracoes/senha/  perfil/avaliar/
│   ├── components/
│   │   ├── ui/                     # primitivos (Button, Field, Chip, Sheet, Toast, Rail…)
│   │   └── app/                    # estrutura (Screen, TopBar, BottomNav, OnboardingStep, ErrorState, NutriBar)
│   ├── features/
│   │   ├── auth/         components/ hooks/ schemas.ts
│   │   ├── onboarding/   components/ hooks/ schemas.ts
│   │   ├── profile/      components/ hooks/
│   │   ├── plan/         components/ (DayRail, MealRow, FoodItemRow, SubstitutionSheet, WeekDayPicker…) hooks/
│   │   ├── nutri/        components/ (ConversationList, ChatBubble, SwapCard, MealSuggestionCard…) hooks/
│   │   ├── progress/     components/ (WeightChart, AdherenceGrid, WeightStepper) hooks/
│   │   ├── settings/     components/ hooks/ push.ts
│   │   └── feedback/     components/ (RatingButtons, UsabilitySurvey) hooks/
│   ├── lib/
│   │   ├── api/
│   │   │   ├── client.ts           # fetch com credentials, CSRF, Accept JSON, conversão snake↔camel, ApiError
│   │   │   ├── errors.ts           # ApiError { status, code, message, fieldErrors, details }
│   │   │   ├── auth.ts  onboarding.ts  profile.ts  plans.ts  days.ts  nutri.ts  progress.ts  settings.ts  feedback.ts
│   │   │   └── query-keys.ts
│   │   ├── types.ts                # modelo de domínio (evolui do atual)
│   │   ├── nutrition.ts  format.ts  units.ts  labels.ts  motion.ts
│   │   └── query-client.tsx        # QueryClientProvider
│   └── mocks/
│       ├── handlers/               # MSW por feature (substituem mock-data.ts)
│       ├── fixtures/               # dados (a Camila do mock-data.ts vira fixture)
│       ├── browser.ts  server.ts
└── vitest.config.ts  playwright.config.ts
```

As páginas atuais (`src/app/**/page.tsx`) concentram lógica e subcomponentes (`LinhaRefeicao`, `GraficoPeso`, `Resposta`…). Na implementação, esses subcomponentes **migram para `features/*/components`**, com story e teste; a página fica com composição + hook.

## 3. Camada de API

```ts
// src/lib/api/client.ts (contrato)
export async function api<T>(path: string, init?: { method?: string; body?: unknown; signal?: AbortSignal }): Promise<T>
// - base: process.env.NEXT_PUBLIC_API_URL + '/api/v1'
// - credentials: 'include'; headers: Accept/Content-Type JSON; X-XSRF-TOKEN do cookie
// - antes da 1ª escrita: GET /sanctum/csrf-cookie (memoizado); em 419/403 CSRF, renova e repete 1×
// - converte chaves snake_case → camelCase na resposta e camelCase → snake_case no corpo
// - desembrulha { data } quando presente; devolve meta junto quando pedido
// - erro: lança ApiError { status, code, message, fieldErrors, details }
```
Cada função de domínio documenta o endpoint (como já faz `lib/api.ts` 🔵). Exemplo: `getDay(date)`, `setMealDone(date, slot, done)`, `swapItem(date, itemId, foodId)`, `undo(date)`.

### Tratamento global de erros (hook `useApiErrorHandler`)
| `code` | Ação |
|---|---|
| `UNAUTHENTICATED` | limpa cache, `router.replace('/entrar?voltar=' + atual)` |
| `ONBOARDING_INCOMPLETE` | `router.replace('/onboarding/' + details.nextStep)` |
| `TOO_MANY_REQUESTS` | toast com `details.retryAfter` |
| demais | a tela decide (estado de erro, mensagem inline, toast) |

## 4. Modelo de tipos

`src/lib/types.ts` 🔵 é a base. Mudanças necessárias:
- IDs passam a `number`; refeição identificada por `slot` na rota (`/dieta/almoco`).
- `DayPlan` ganha `isToday`, `isTrainingDay`, `editable`, `totals { planned, consumed, remaining }`, `lastChange?: { id, text, undoUntil }`.
- `FoodItem` ganha `foodId`, `grams`, `source`; `replacedFrom` continua string (nome) para exibição.
- `Profile`: remove `initials` (derivado no front), `gym`/`city` passam a constantes de configuração, `memberSince` vira `createdAt`, `goalWeightKg` pode ser `null`, ganha `goalWeightSource`, `preferredName`, `otherRestrictions: string[]`.
- `NutriMessage`: `card?: SwapCard | MealCard` (union discriminada por `type`) no lugar de `swap?`/`meal?`; `actionsAvailable: boolean`; `followUpSuggestions: string[]` (RN45 — os chips vêm da última resposta, não de constante); `rating?: 'up' | 'down' | null`.
- Novos: `Conversation`, `Settings`, `ProgressSummary`, `Catalog`, `OnboardingState`, `PlanStatus`.

## 5. Estado e dados por tela (hooks)

| Hook | Query key | Endpoint | Observação |
|---|---|---|---|
| `useMe()` | `['me']` | `GET /me` | carregado no layout autenticado |
| `useOnboarding()` | `['onboarding']` | `GET /onboarding` | + `useSaveStep(step)` |
| `useCatalog()` | `['catalog']` | `GET /catalog/onboarding` | `staleTime: Infinity` |
| `useProfile()` | `['profile']` | `GET /profile` | |
| `usePlanStatus(id)` | `['plan', id]` | `GET /plans/{id}` | `refetchInterval: 1500` enquanto `pending/generating` |
| `useDay(date)` | `['day', date]` | `GET /days/{date}` | |
| `useToggleMeal()` | — | `PATCH /days/{date}/meals/{slot}` | **otimista** em `['day', today]` |
| `useSubstitutions(itemId)` | `['subs', date, itemId]` | `GET /days/{date}/items/{id}/substitutions` | só com a folha aberta |
| `useSwap()` / `useUndo()` | — | `POST …/swap`, `POST …/undo` | invalida `['day', today]` |
| `useConversations()` | `['conversations']` | `GET /conversations` | infinita (cursor) |
| `useMessages(id)` | `['messages', id]` | `GET /conversations/{id}/messages` | infinita, mais antigas ao rolar para cima |
| `useAsk(id)` | — | `POST /conversations/{id}/messages` | mensagem do usuário otimista com `status: 'enviando'`; erro → `'falhou'` 🔵 |
| `useApplyAction()` | — | `POST /messages/{id}/actions/{i}` | invalida `['day', today]`, `['messages', id]` |
| `useNutriContext()` | `['nutri-context']` | `GET /nutri/context` | |
| `useWeighIns()` / `useProgress(period)` | `['weigh-ins']`, `['progress', period]` | | |
| `useSettings()` | `['settings']` | `GET/PUT /settings` | |

## 6. Rotas e layouts

- `app/layout.tsx`: fontes, `QueryClientProvider`, `Toaster`, registro do service worker.
- `app/(app)/layout.tsx`: exige sessão (`useMe`), redireciona conforme §3; substitui o `PlanProvider` 🔵.
- `app/onboarding/layout.tsx`: exige sessão; se onboarding concluído e sem `?editar=1`, vai para `/hoje`.
- Cada segmento mantém seu `template.tsx` (transição de entrada 🔵).
- Modo edição do onboarding (links do Perfil 🔵): `/onboarding/dados?editar=1` — mesmo componente, botão "Salvar" em vez de "Continuar", volta ao Perfil, mostra efeito no plano (`plan_effect`).

## 7. Web Push no cliente

`features/settings/push.ts`:
1. `Notification.requestPermission()` só em resposta a um toque no `Toggle` (nunca no carregamento).
2. `registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: vapidPublicKey })`.
3. `POST /push-subscriptions` com `endpoint`, `keys.p256dh`, `keys.auth`, `contentEncoding`.
4. Permissão negada → toggle volta a desligado + mensagem explicando como liberar; iOS sem PWA instalada → mensagem "Adicione o Prato Forte à tela de início para receber avisos".
5. `sw.js`: evento `push` mostra a notificação; `notificationclick` abre a URL do payload (`/dieta/almoco`, `/evolucao`…).

## 8. Variáveis de ambiente

```
NEXT_PUBLIC_API_URL=https://api.pratoforte.exemplo
NEXT_PUBLIC_API_MOCKING=disabled   # enabled → MSW no navegador (demo offline e desenvolvimento sem back)
NEXT_PUBLIC_APP_VERSION=1.0.0      # exibida em Configurações
```
