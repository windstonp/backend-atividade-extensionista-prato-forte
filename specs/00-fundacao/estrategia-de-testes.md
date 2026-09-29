# Estratégia de testes e qualidade

Pergunta que toda spec responde: **"Como saberemos automaticamente que esta feature está funcionando?"**
Testes são definidos na spec **antes** da implementação (seção *Test Strategy* de cada feature) e fazem parte da Definition of Done.

## 1. Níveis e responsabilidades

```
Component Tests   → um componente, isolado, em todos os estados que importam
      ↓
Unit Tests        → uma regra ou função pura, sem I/O
      ↓
Integration Tests → várias partes reais juntas (Controller→Service→DB | Form→API client→estado→UI)
      ↓
E2E Tests         → fluxo real do usuário, navegador + API + banco
```

| Nível | Responsabilidade | NÃO é responsabilidade |
|---|---|---|
| **Component** | render, variantes, props, eventos, estados (carregando, vazio, erro, desabilitado), a11y (axe), movimento reduzido | chamar API, regra de negócio |
| **Unit** | cálculos, validadores, formatadores, parsers, regras puras (RN13, RN14, RN24, RN25, RN35, RN36, RN41…) | banco, HTTP, DOM |
| **Integration (back)** | endpoint completo com banco real: status, JSON, efeitos no banco, autorização (401/404-posse), validação (422), regras de negócio (409), fila e notificações (fakes) | IA real, navegador |
| **Integration (front)** | tela/feature com MSW: formulário → validação → cliente API → cache → UI, incluindo erros da API | backend real |
| **E2E** | fluxos críticos do usuário ponta a ponta | combinações de validação (ficam no integration) |

## 2. Ferramentas (escolhidas pela stack real — ✅ D10)

| Onde | Ferramenta | Uso |
|---|---|---|
| Back | **Pest 3** (+ plugin Laravel) | Unit e Feature |
| Back | MySQL 8 real no CI (serviço do GitHub Actions) | integração fiel (coluna gerada, JSON, collation) — não SQLite |
| Back | `Queue::fake`, `Notification::fake`, `Http::fake`, `FakeAiClient`, `Carbon::setTestNow` | isolamento de efeitos e tempo |
| Front | **Storybook 10** (`@storybook/nextjs-vite`) + **`@storybook/addon-vitest`** | stories viram testes de componente (modo browser, Playwright/Chromium) |
| Front | `@storybook/addon-a11y` | axe em toda story; `parameters.a11y.test = 'error'` |
| Front | **Vitest** + **Testing Library** (`@testing-library/react`, `user-event`) | unit e integração |
| Front | **MSW 2** | mocks de API compartilhados por stories, integração e demo |
| E2E | **Playwright** | Chromium + WebKit mobile (viewport 390×844); traces em falha |
| E2E | Mailpit | ler o e-mail de recuperação de senha |

Versões fixadas no Plano 02: Storybook 10.6, Vitest 4.1, Playwright 1.63 (mesma tag da imagem Docker `mcr.microsoft.com/playwright:v1.63.0-noble`, onde o front roda local e no CI).

## 3. Estrutura

### Backend
```
backend/tests/
├── Pest.php                      # uses(RefreshDatabase) em Feature; helpers: actingAsOnboarded(), fakeAi()
├── Unit/
│   ├── Nutrition/  NutritionCalculatorTest  MealSchedulerTest  DayTotalsTest  PortionFormatterTest  GoalWeightResolverTest
│   ├── Foods/      SubstitutionFinderTest  FoodFilterTest(*)
│   ├── Plans/      PortionAdjusterTest  PlanValidatorTest
│   ├── Nutri/      NutriActionValidatorTest  NutriResponseParserTest
│   ├── Progress/   WeightForecastTest  AdherenceCalculatorTest
│   └── Validation/ SusScoreTest
├── Feature/
│   ├── Auth/  RegisterTest  LoginTest  LogoutTest  PasswordResetTest  UpdatePasswordTest  DeleteAccountTest
│   ├── Onboarding/  OnboardingStepsTest  CompleteOnboardingTest  PreviewTargetsTest
│   ├── Profile/  ProfileTest  PreferencesTest  PlanEffectTest
│   ├── Plans/  GeneratePlanTest  GeneratePlanJobTest  PlanStatusTest
│   ├── Days/  DayTest  ToggleMealTest  SubstitutionsTest  SwapTest  UndoTest
│   ├── Nutri/  ConversationsTest  MessagesTest  ActionsTest  ContextTest  SummarizeJobTest
│   ├── Progress/  WeighInTest  ProgressTest
│   ├── Settings/  SettingsTest  PushSubscriptionTest  MealReminderCommandTest  WeeklySummaryCommandTest  TipsCommandTest
│   ├── Feedback/  RatingTest  UsabilityTest  ExportCommandTest
│   └── Security/  OwnershipTest  RateLimitTest  AllergyInvariantTest
└── Fixtures/ai/  plan_valid.json  plan_forbidden_food.json  plan_missing_slot.json  nutri_swap.json  nutri_meal.json  nutri_invalid_action.json  not_json.txt
```
(*) `FoodFilterTest` usa banco (é Feature na prática, mas testa uma classe); tudo bem manter em `Unit/` com `RefreshDatabase` ou mover — decisão do time, desde que consistente.

### Frontend
```
frontend/
├── src/components/ui/Button/Button.tsx · Button.stories.tsx          # story = teste de componente (play)
├── src/features/plan/components/MealRow.tsx · MealRow.stories.tsx
├── src/lib/nutrition.test.ts · format.test.ts · units.test.ts         # unit, ao lado do código
├── src/features/plan/hooks/useToggleMeal.test.tsx                      # integração de hook com MSW
├── src/features/auth/LoginForm.integration.test.tsx                    # Form → validação → API → UI
└── e2e/
    ├── fixtures/ (login helpers, seed reset)
    ├── auth.spec.ts  onboarding.spec.ts  day.spec.ts  swap.spec.ts  allergy.spec.ts
    ├── nutri.spec.ts  progress.spec.ts  plan-retry.spec.ts  password-reset.spec.ts
```
Convenção de nomes: `*.stories.tsx` (componente), `*.test.ts(x)` (unit), `*.integration.test.tsx` (integração), `e2e/*.spec.ts` (E2E).

Projetos do Vitest (`vitest.config.ts` → `test.projects`): `unit` (jsdom), `integration` (jsdom + MSW server), `storybook` (browser, via addon).

## 4. Regras transversais (valem para toda feature)

1. **Posse** — cada recurso com ID tem teste "usuário B recebe 404 ao acessar recurso do usuário A" (`Security/OwnershipTest` com *dataset* de rotas).
2. **Autenticação** — cada rota protegida responde 401 sem sessão (dataset).
3. **Onboarding** — rotas `onboarded` respondem 409 `ONBOARDING_INCOMPLETE` para usuário sem onboarding (dataset).
4. **Alergia (RN17)** — `AllergyInvariantTest`: com a IA falsa devolvendo alimento proibido em plano e em ação do Nutri, nada proibido é persistido; trocas nunca listam proibidos. E2E `allergy.spec.ts` repete no navegador.
5. **Tempo** — todo teste que depende de "hoje" fixa o relógio (`Carbon::setTestNow('2026-09-21 11:00', 'America/Sao_Paulo')`; no front, `vi.setSystemTime`; no E2E, o seed usa a data do servidor).
6. **IA** — nenhum teste automatizado chama a IA real. Um *smoke test* manual/opcional (`php artisan ai:smoke`) valida a integração real antes da demonstração.
7. **Stories representam estados reais** — nada de stories "de enfeite"; cada story corresponde a um estado que a tela realmente apresenta (catálogo em `08-design-system`).

## 5. E2E — fluxos mapeados

| Id | Fluxo | Feature |
|---|---|---|
| E2E-01 | Cadastro → 7 etapas → gerando → pronto → Hoje mostra 5 refeições com as metas | 01, 02, 03 |
| E2E-02 | Login inválido mostra erro; login válido → Hoje; logout → Boas-vindas; rota protegida sem sessão → Login | 01 |
| E2E-03 | Retomar onboarding: login de usuário parado na etapa "atividade" abre essa etapa com dados anteriores | 02 |
| E2E-04 | Marcar refeição como feita → metas atualizam → recarregar → persiste; desmarcar | 03 |
| E2E-05 | Trocar alimento na folha → toast → "Desfazer" → item original volta | 03 |
| E2E-06 | **Alergia**: usuário com alergia a castanhas — plano, folha de troca e ação do Nutri sem castanhas | 02, 03, 04 |
| E2E-07 | Falha da IA na geração → "Não deu para montar agora" → "Tentar de novo" → pronto | 03 |
| E2E-08 | Nutri: nova conversa → pergunta sugerida → cartão de troca → "Substituir no almoço de hoje" → Dieta mostra "Trocado"; voltar à lista → continuar conversa anterior com histórico | 04 |
| E2E-09 | Registrar peso → Evolução mostra o novo ponto e o número | 05 |
| E2E-10 | Recuperar senha: pedir link → abrir e-mail no Mailpit → redefinir → login com a nova senha | 01 |
| E2E-11 | Mudar restrição no Perfil → plano refeito automaticamente → novo plano sem o alimento | 02, 03 |
| E2E-12 | Avaliar resposta do Nutri 👍 e responder o questionário de usabilidade | 07 |

Não são E2E (cobertos em integração): cada mensagem de validação, limites numéricos, rate limiting, notificações (comandos testados no back; push real não é automatizável de forma estável).

## 6. CI (GitHub Actions — gratuito)

| Job | Gatilho | Passos |
|---|---|---|
| `backend` | todo push/PR que toca `backend/` | composer install → Pint --test → Larastan → Pest (MySQL service) → `composer audit` |
| `frontend` | todo push/PR que toca `frontend/` | npm ci → ESLint (inclui a regra **sem mocks em produção**, D12) → `tsc --noEmit` → Vitest (unit + integration) → Storybook tests (Playwright Chromium) → `npm audit --audit-level=high` |
| `e2e` | PR para `main` | sobe MySQL + Mailpit + Laravel (`AI_DRIVER=fake`, `E2ESeeder`) + `next build && next start` → Playwright; artefatos: trace, vídeo, screenshots |

Branch `main` protegida: os três jobs verdes para merge.

## 7. Ciclo de qualidade por feature

```
Requisito (RF/RN desta spec)
  ↓ Specification (esta pasta)
  ↓ Critérios de aceitação (Dado/Quando/Então)
  ↓ Design de componentes (08-design-system) — telas novas com frontend-design (D11)
  ↓ Stories (estados reais) — falham até o componente existir
  ↓ Implementação (back: Unit → Feature; front: componente → hook → página)
  ↓ Unit tests verdes
  ↓ Integration tests verdes
  ↓ E2E do fluxo crítico verde
  ↓ Validação: critérios de aceitação conferidos, axe limpo, CI verde, revisão
```
Mudou regra? Atualiza `regras-de-negocio.md` e a spec no mesmo PR.

## 8. Definition of Done (modelo geral)

Cada spec de feature traz a sua DoD, adaptada. Modelo:

```
[ ] Critérios de aceitação da spec atendidos
[ ] Backend: rotas + Form Requests + Policies + Services + Resources
[ ] Backend: unit tests das regras puras envolvidas
[ ] Backend: feature tests — sucesso, 422, 401, 404 de posse, 409 das regras, efeitos no banco
[ ] Frontend: componentes da biblioteca reutilizados; novos componentes com stories dos estados reais e play()
[ ] Frontend: tela ligada à API real com loading, vazio, erro e sucesso — nenhum import de src/mocks fora de stories/testes (regra de lint verde)
[ ] Frontend: testes de integração do fluxo da tela com MSW
[ ] E2E do fluxo crítico, quando a feature tem um (§5)
[ ] Acessibilidade: axe sem violações serious/critical; teclado e leitor de tela nos elementos novos
[ ] Movimento: animações presentes, reduced-motion verificado
[ ] Telas novas geradas com o skill frontend-design, na identidade do mock
[ ] CI verde; specs e regras atualizadas se algo mudou
```
