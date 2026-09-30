# Plano 04B — Plano alimentar: telas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ligar à API do Plano 04A as telas Gerando, Pronto, Hoje, Dieta e Detalhe da refeição (com a folha de troca e o "Desfazer"), o "Refazer meu plano" do Perfil e os efeitos da RN21 no front — tirando o plano do `localStorage`.

**Architecture:** Estado do servidor no TanStack Query: `['dia', data]` para o dia (com atualização otimista ao marcar refeição, recalculada no cliente com a mesma regra da RN24), `['plano', id]` com *polling* enquanto gera. Componentes apresentacionais novos em `src/features/dia/components` (`DayRail`, `MealRow`, `FoodItemRow`, `WeekDayPicker`, `SubstitutionSheet`, `NoPlanState`), cada um com story e `play`; as páginas são contêineres finos. O `plan-store` continua só para as telas ainda de protótipo (Nutri e Evolução, Planos 05 e 06), sem `localStorage`.

**Tech Stack:** Next 16.3.5, React 19.2, TanStack Query 5, Storybook 10.6, Vitest 4.1, MSW 2, Playwright 1.63 (repo front); Laravel 12 (repo backend, só seeder e compose).

**Spec:** `specs/03-plano-alimentar/spec.md` (§2–§4, §7, §8, §9), com `specs/02-onboarding-perfil/spec.md` (fluxos do §3: `plan_effect`), `specs/08-design-system/spec.md` e `specs/99-inconsistencias.md` (textos a ajustar da Dieta). Contrato da API: Plano 04A (`GET /days/{date}`, `PATCH /days/{date}/meals/{slot}`, `GET …/items/{item}/substitutions`, `POST …/swap`, `POST /days/{date}/undo`, `POST /plans`, `GET /plans/{plan}`, `GET /plans/active`). Caminhos de spec/plano relativos ao repo **backend**.

**Onde rodar:** front em `/home/alvez/atividade-extensionista/frontend` (`docker compose run --rm web …`), backend em `/home/alvez/atividade-extensionista/backend` (`docker compose …`, agora com os serviços `queue` e `scheduler`). Branches empilhadas: front `plano-04b-telas` saindo de `plano-03-onboarding`; backend `plano-04b-telas` saindo de `plano-04a-plano-alimentar`.

## Decisões deste plano (rulings sobre a spec)

1. **Detalhe da refeição por slot:** `/dieta/{slot}` (ex.: `/dieta/almoco`) — a spec diz `/dieta/[slot]`; a pasta continua `[refeicao]`, o valor é o slot. Só hoje é aberto (RN23); outros dias não têm link.
2. **"Pergunta ao Nutri" leva a `/nutri?pergunta=…`** já nesta etapa; o Nutri passa a ler o parâmetro no Plano 05.
3. **Toast de "Desfazer" do Hoje e do Detalhe vem do servidor** (`last_change`): some quando expira (7 s, o padrão do `Toast`; a API aceita até 15 min) ou quando a pessoa toca "Desfazer"; um toast já dispensado não volta para a mesma alteração.
4. **Rail acima da meta:** a barra fica em 100% (já é assim); o texto do excesso fica para o Plano 06 (Evolução), onde as médias passam da meta com frequência.
5. **`plan-store`** perde o `localStorage` e o dia; continua com os dados de protótipo para Nutri e Evolução até os Planos 05 e 06.

## Global Constraints

- Nenhum dado do plano, do dia ou das trocas sai de mock (D12): Hoje, Dieta, Detalhe, Gerando e Pronto só leem a API.
- Textos exatos: "Montando seu plano", "Costuma levar uns 10 segundos.", "Não deu para montar agora", "Seus dados estão salvos. Foi a conexão com o Nutri que falhou no meio do caminho.", "Tentar de novo", "Seu plano está pronto, {nome}", "Um dia comum", "Ver o dia de hoje", "Metas de hoje", "Não foi possível carregar seu dia", "Seu plano está quase pronto", "Não conseguimos montar seu plano", "Sua dieta", "dia de treino", "dia de descanso", "Nada registrado neste dia", "Toda refeição de hoje pode ser trocada. O Nutri ajuda quando o dia sair do plano.", "Refeição não encontrada", "O que vai no prato", "Trocar", "Trocado", "No lugar de {original}", "Marcar como feita", "Desmarcar refeição", "Trocar {item}", "Nenhuma dessas opções tem {restrições}", "Usar {opção}", "Cancelar", "Ainda não temos trocas cadastradas para este alimento. O Nutri consegue sugerir uma a partir do que você tem em casa.", "Perguntar ao Nutri", "Desfazer", "Não foi possível salvar. Tente de novo.", "Refazer meu plano", "Vamos montar um plano novo com suas respostas atuais. As refeições que você já marcou hoje ficam.", "Salvo. Quer refazer seu plano com isso?", "Refazer", "Horários das refeições atualizados", "Seu plano novo está pronto.".
- Marcar refeição é otimista (RF13): muda na hora, volta ao estado anterior se a API falhar, com o aviso "Não foi possível salvar. Tente de novo.".
- Polling de `GET /plans/{id}` a cada 1,5 s só enquanto `pending`/`generating` e só com a tela aberta.
- Folha de troca acessível: `radiogroup` com a primeira opção marcada, setas trocam a escolha, foco preso (Sheet do Plano 02), `Esc` fecha; a garantia lista **todas** as restrições.
- D11: telas refinadas com `frontend-design`, muita animação, identidade do mock; `text-musgo` só sobre `tinta`.
- E2E: no máximo 5 logins por minuto por e-mail (limite do backend) — cada teste que muda estado tem conta própria por navegador.

## Review Focus

1. **Marcar e desmarcar rápido várias vezes** (toque duplo no marcador; rede lenta) → o estado final na tela é o do servidor, sem piscar para o errado nem totais dessincronizados (Task 2, `recalcularDia` + `useMarcarRefeicao`).
2. **Trocar e sair da tela antes do toast sumir; voltar** → o "Desfazer" some depois de expirar e não reaparece para a mesma alteração (Tasks 6, 8).
3. **Gerando aberto por muito tempo / aba em segundo plano / plano que falha** → polling para ao sair; `failed` mostra o erro com "Tentar de novo" que cria outro plano e troca o `?plano=` (Task 5).
4. **Abrir `/dieta/xyz` ou `/dieta/almoco` num dia sem plano** → "Refeição não encontrada" ou o estado de "sem plano", nunca tela quebrada (Task 8).
5. **Semana cruzando o mês e datas fora do intervalo** (segunda 29/9 → domingo 5/10; hoje domingo) → a faixa de 7 dias mostra datas certas e nunca pede dia fora de [hoje − 90, hoje + 6] (Task 7, `semanaDe`).

---

### Task 1: Backend — contas de E2E do 04B e a falha roteirizada no compose

**Files (repo backend):**
- Modify: `database/seeders/E2ESeeder.php`, `tests/Feature/Seeders/E2ESeederTest.php`, `compose.yaml`

**Interfaces:**
- Produces (senha de todas: `senha1234`), uma por navegador (`{b}` = `chromium`/`webkit`):
  - `dia-{b}@e2e.pratoforte.test` — concluída, com plano (E2E-04, E2E-05).
  - `alergia-{b}@e2e.pratoforte.test` — concluída, alergia a amendoim e castanhas, com plano (E2E-06).
  - `mudanca-{b}@e2e.pratoforte.test` — concluída, com plano (E2E-11).
  - `falha-{b}@e2e.pratoforte.test` — todas as etapas respondidas, parada no resumo; a **primeira** geração falha quando `AI_FAKE_FAIL_PLAN_FOR` lista a conta (E2E-07).
  - `compose.yaml`: `api` e `queue` repassam `AI_FAKE_FAIL_PLAN_FOR` do ambiente (`AI_FAKE_FAIL_PLAN_FOR=… docker compose up -d`).

- [ ] **Step 1: Teste do seeder que deve falhar**

```bash
cd /home/alvez/atividade-extensionista/backend
git switch plano-04a-plano-alimentar && git switch -c plano-04b-telas
```

Em `tests/Feature/Seeders/E2ESeederTest.php`, acrescentar:
```php
it('cria as contas do 04B: dia, alergia e mudança com plano; falha parada no resumo', function () {
    $this->seed(E2ESeeder::class);
    $conta = fn (string $email) => User::where('email', $email)->sole();

    foreach (['chromium', 'webkit'] as $b) {
        foreach (['dia', 'alergia', 'mudanca'] as $tipo) {
            expect($conta("{$tipo}-{$b}@e2e.pratoforte.test")->activePlan()->first()?->status)->toBe(App\Enums\PlanStatus::Ready);
        }
        expect($conta("alergia-{$b}@e2e.pratoforte.test")->restrictions()->pluck('slug')->all())->toBe(['castanhas'])
            ->and($conta("falha-{$b}@e2e.pratoforte.test")->profile->nextStep()?->value)->toBe('resumo')
            ->and($conta("falha-{$b}@e2e.pratoforte.test")->mealPlans()->count())->toBe(0);
    }
});
```
Run: `docker compose run --rm api php artisan test tests/Feature/Seeders`
Expected: FAIL — as contas não existem.

- [ ] **Step 2: Implementar**

Em `database/seeders/E2ESeeder.php`, acrescentar `use App\Models\Restriction;` e, dentro do `foreach (['chromium', 'webkit'] as $navegador)`, depois do laço das contas `concluido`/`senha`:
```php
            // 04B: uma conta por teste que muda estado, para não passar do limite de login.
            foreach ([['Dora Dias', 'dia'], ['Ana Alves', 'alergia'], ['Mauro Mendes', 'mudanca']] as [$nome, $conta]) {
                $user = User::factory()->onboarded()->create(['name' => $nome, 'email' => "{$conta}-{$navegador}@e2e.pratoforte.test"]);
                if ($conta === 'alergia') {
                    $user->restrictions()->attach(Restriction::where('slug', 'castanhas')->sole());
                }
                $planos->requestGeneration($user);
            }

            // E2E-07: parada no resumo; com AI_FAKE_FAIL_PLAN_FOR, a primeira geração falha.
            User::factory()->answered()->create(['name' => 'Fábio Faria', 'email' => "falha-{$navegador}@e2e.pratoforte.test"]);
```

Em `compose.yaml`, nos serviços `api` e `queue`, acrescentar ao `environment`:
```yaml
      AI_FAKE_FAIL_PLAN_FOR: ${AI_FAKE_FAIL_PLAN_FOR:-} # só E2E-07
```

Run: `docker compose run --rm api php artisan test tests/Feature/Seeders && docker compose run --rm --no-deps api composer lint`
Expected: PASS e lint OK.

```bash
git add -A && git commit -m "test(e2e): contas do 04B (dia, alergia, mudança, falha) e falha roteirizada no compose

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Front — tipos, chamadas, recálculo otimista, hooks e MSW do dia e do plano

**Files (repo front):**
- Create: `src/features/dia/{tipos,regras,hooks}.ts`, `src/lib/api/{dia,planos}.ts`
- Create: `src/mocks/fixtures/dia.ts`, `src/mocks/handlers/dia.ts`; Modify: `src/mocks/handlers/index.ts`, `src/lib/chaves.ts`
- Test: `src/features/dia/regras.test.ts`, `src/features/dia/hooks.integration.test.tsx`

**Interfaces:**
- Consumes: `api`, `ApiError` (Plano 02), `CHAVES` (Plano 03), `useToast`.
- Produces:
  - Tipos (camelCase): `Slot`, `ItemDoDia`, `RefeicaoDoDia`, `Totais`, `Dia`, `OpcaoDeTroca`, `Substituicoes`, `StatusDoPlano`, `Plano`, `RefeicaoDoPlano`.
  - API: `getDia(data)`, `marcarRefeicao(slot, done)`, `getSubstituicoes(itemId)`, `trocarItem(itemId, foodId)`, `desfazer()`, `getPlano(id)`, `pedirPlano()` (409 `PLAN_ALREADY_GENERATING` devolve o id do que já gera), `getPlanoAtivo()`.
  - `CHAVES.dia(data)` (`['dia', data]`; hoje = `'today'`), `CHAVES.plano(id)`.
  - Regras puras: `recalcularDia(dia, slot, done): Dia` (RN24: consumido e restante; próxima refeição), `semanaDe(hojeIso): string[]` (segunda a domingo), `juntarComE(lista): string` ("a, b e c"), `planoSemAtivo(erro): { status, planId } | null` (lê o 409 `NO_ACTIVE_PLAN`).
  - Hooks: `useDia(data = 'today')`, `useMarcarRefeicao()` (otimista), `useSubstituicoes(itemId | null)`, `useTrocarItem()`, `useDesfazer()`, `usePlano(id | null)` (polling 1,5 s enquanto gera), `usePedirPlano()`.
  - MSW: `diaApi(parcial?)`, `refeicaoApi(slot, parcial?)`, `substituicoesApi`, `planoProntoApi(id)`, `respondendoDia(dia)`; handlers padrão `GET /days/today` com o dia da Camila e `GET /profile` com `perfilApi`.

- [ ] **Step 1: Branch e testes que devem falhar**

```bash
cd /home/alvez/atividade-extensionista/frontend
git switch plano-03-onboarding && git switch -c plano-04b-telas
```

`src/features/dia/regras.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { camelizar } from '@/lib/api/case';
import { ApiError } from '@/lib/api/errors';
import { diaApi } from '@/mocks/fixtures/dia';
import { juntarComE, planoSemAtivo, recalcularDia, semanaDe } from './regras';
import type { Dia } from './tipos';

const dia = () => camelizar<Dia>(diaApi());

describe('recalcularDia (RN24, otimista)', () => {
  it('marcar o almoço soma exatamente o almoço no consumido e move a próxima', () => {
    const antes = dia();
    const almoco = antes.meals.find((m) => m.slot === 'almoco')!;
    const cafe = antes.meals.find((m) => m.slot === 'cafe')!;

    const depois = recalcularDia(recalcularDia(antes, 'cafe', true), 'almoco', true);

    expect(depois.totals.consumed.calories).toBe(cafe.calories + almoco.calories);
    expect(depois.totals.remaining.calories).toBe(antes.totals.planned.calories - cafe.calories - almoco.calories);
    expect(depois.meals.map((m) => m.isNext)).toEqual([false, true, false, false, false]);
  });

  it('desmarcar volta ao que era', () => {
    const antes = dia();
    expect(recalcularDia(recalcularDia(antes, 'cafe', true), 'cafe', false)).toEqual(antes);
  });

  it('com tudo feito não há próxima', () => {
    let d = dia();
    for (const m of d.meals) d = recalcularDia(d, m.slot, true);
    expect(d.meals.some((m) => m.isNext)).toBe(false);
    expect(d.totals.remaining.calories).toBe(0);
  });
});

describe('semanaDe', () => {
  it.each([
    ['2026-09-28', ['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04']],
    ['2026-10-04', ['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04']],
    ['2026-12-31', ['2026-12-28', '2026-12-29', '2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02', '2027-01-03']],
  ])('semana de %s começa na segunda', (hoje, esperado) => {
    expect(semanaDe(hoje)).toEqual(esperado);
  });
});

describe('juntarComE e planoSemAtivo', () => {
  it('junta com vírgula e "e"', () => {
    expect(juntarComE(['amendoim e castanhas'])).toBe('amendoim e castanhas');
    expect(juntarComE(['lactose', 'glúten', 'camarão'])).toBe('lactose, glúten e camarão');
  });

  it('lê o status do plano no 409 NO_ACTIVE_PLAN', () => {
    const erro = new ApiError(409, 'NO_ACTIVE_PLAN', 'x', {}, { planStatus: 'generating', planId: 43 });
    expect(planoSemAtivo(erro)).toEqual({ status: 'generating', planId: 43 });
    expect(planoSemAtivo(new ApiError(500, 'SERVER_ERROR', 'x'))).toBeNull();
  });
});
```

`src/features/dia/hooks.integration.test.tsx`:
```tsx
import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { Toaster } from '@/components/ui/Toaster';
import { diaApi } from '@/mocks/fixtures/dia';
import { url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { novoClienteDeTeste } from '@/test/renderizar';
import { useDia, useMarcarRefeicao, usePedirPlano } from './hooks';

function comCliente() {
  const cliente = novoClienteDeTeste();
  const wrapper = ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={cliente}>
      <Toaster>{children}</Toaster>
    </QueryClientProvider>
  );
  return { cliente, wrapper };
}

describe('useMarcarRefeicao (RF13, otimista)', () => {
  it('muda na hora e fica com a resposta do servidor', async () => {
    let liberar!: () => void;
    const pausa = new Promise<void>((r) => (liberar = r));
    server.use(
      http.patch(url('/days/today/meals/cafe'), async () => {
        await pausa;
        return HttpResponse.json({ data: diaApi({ feitas: ['cafe'] }) });
      }),
    );
    const { wrapper } = comCliente();
    const { result } = renderHook(() => ({ dia: useDia(), marcar: useMarcarRefeicao() }), { wrapper });
    await waitFor(() => expect(result.current.dia.data).toBeDefined());

    act(() => result.current.marcar.mutate({ slot: 'cafe', done: true }));

    await waitFor(() => expect(result.current.dia.data!.meals[0].done).toBe(true)); // antes do servidor responder
    liberar();
    await waitFor(() => expect(result.current.marcar.isSuccess).toBe(true));
    expect(result.current.dia.data!.meals[0].done).toBe(true);
  });

  it('volta ao estado anterior e avisa quando a API falha', async () => {
    server.use(http.patch(url('/days/today/meals/cafe'), () => HttpResponse.error()));
    const { wrapper } = comCliente();
    const { result } = renderHook(() => ({ dia: useDia(), marcar: useMarcarRefeicao() }), { wrapper });
    await waitFor(() => expect(result.current.dia.data).toBeDefined());

    act(() => result.current.marcar.mutate({ slot: 'cafe', done: true }));

    await waitFor(() => expect(result.current.marcar.isError).toBe(true));
    expect(result.current.dia.data!.meals[0].done).toBe(false);
  });
});

describe('usePedirPlano', () => {
  it('usa o plano que já está gerando quando a API responde 409', async () => {
    server.use(
      http.post(url('/plans'), () =>
        HttpResponse.json({ message: 'x', code: 'PLAN_ALREADY_GENERATING', details: { plan_id: 77 } }, { status: 409 }),
      ),
    );
    const { wrapper } = comCliente();
    const { result } = renderHook(() => usePedirPlano(), { wrapper });

    let id = 0;
    await act(async () => {
      id = await result.current.mutateAsync();
    });

    expect(id).toBe(77);
  });
});
```

Run: `docker compose run --rm web npx vitest run --project unit --project integration src/features/dia`
Expected: FAIL — `./regras`, `./hooks` e `@/mocks/fixtures/dia` não resolvem.

- [ ] **Step 2: Tipos, chamadas e regras**

`src/features/dia/tipos.ts`:
```ts
import type { Macros } from '@/lib/types';

export type Slot = 'cafe' | 'lanche' | 'almoco' | 'pre-treino' | 'jantar';

export interface ItemDoDia {
  id: number | null;
  foodId: number;
  name: string;
  grams: number;
  amount: string;
  calories: number;
  macros: Macros;
  source: 'plan' | 'manual' | 'nutri';
  replacedFrom: string | null;
}

export interface RefeicaoDoDia {
  /** `null` na prévia de dias futuros (não gravada). */
  id: number | null;
  slot: Slot;
  name: string;
  time: string;
  note: string | null;
  position: number;
  done: boolean;
  isNext: boolean;
  summary: string;
  calories: number;
  macros: Macros;
  items: ItemDoDia[];
}

export interface Totais {
  calories: number;
  protein: number;
  carbs: number;
  fat: number;
}

/** `GET /days/{date}` (spec 03 §5), já em camelCase. */
export interface Dia {
  date: string;
  isToday: boolean;
  editable: boolean;
  materialized: boolean;
  isTrainingDay: boolean;
  targets: { kcal: number; proteinG: number; carbsG: number; fatG: number } | null;
  totals: { planned: Totais; consumed: Totais; remaining: Totais };
  meals: RefeicaoDoDia[];
  lastChange: { id: number; text: string; undoUntil: string } | null;
}

export interface OpcaoDeTroca {
  foodId: number;
  name: string;
  grams: number;
  amount: string;
  calories: number;
  macros: Macros;
  calorieDelta: number;
  note: string | null;
  inPantry: boolean;
}

export interface Substituicoes {
  item: { id: number; name: string; amount: string; calories: number; macros: Macros };
  options: OpcaoDeTroca[];
  guarantee: { restrictions: string[] };
}

export type StatusDoPlano = 'pending' | 'generating' | 'ready' | 'failed';

export interface RefeicaoDoPlano {
  slot: Slot;
  name: string;
  time: string;
  calories: number;
  summary: string;
}

/** `GET /plans/{plan}`. */
export interface Plano {
  id: number;
  status: StatusDoPlano;
  isActive: boolean;
  failureReason?: string;
  readyAt?: string;
  targets?: { kcal: number; proteinG: number; carbsG: number; fatG: number };
  meals?: RefeicaoDoPlano[];
}
```

`src/lib/api/dia.ts`:
```ts
import type { Dia, Slot, Substituicoes } from '@/features/dia/tipos';
import { api } from './client';

type Dados<T> = { data: T };

/** GET /days/{date} — `today` ou `YYYY-MM-DD`. */
export const getDia = (data = 'today') => api<Dados<Dia>>(`/days/${data}`).then((r) => r.data);

/** PATCH /days/today/meals/{slot} */
export const marcarRefeicao = (slot: Slot, done: boolean) =>
  api<Dados<Dia>>(`/days/today/meals/${slot}`, { method: 'PATCH', body: { done } }).then((r) => r.data);

/** GET /days/today/items/{item}/substitutions */
export const getSubstituicoes = (itemId: number) =>
  api<Dados<Substituicoes>>(`/days/today/items/${itemId}/substitutions`).then((r) => r.data);

/** POST /days/today/items/{item}/swap — as gramas são as do servidor (RN25). */
export const trocarItem = (itemId: number, foodId: number) =>
  api<Dados<Dia>>(`/days/today/items/${itemId}/swap`, { method: 'POST', body: { foodId } }).then((r) => r.data);

/** POST /days/today/undo */
export const desfazer = () => api<Dados<Dia>>('/days/today/undo', { method: 'POST' }).then((r) => r.data);
```

`src/lib/api/planos.ts`:
```ts
import type { Plano } from '@/features/dia/tipos';
import { api } from './client';
import { ApiError } from './errors';

type Dados<T> = { data: T };

/** GET /plans/{plan} */
export const getPlano = (id: number) => api<Dados<Plano>>(`/plans/${id}`).then((r) => r.data);

/** GET /plans/active */
export const getPlanoAtivo = () => api<Dados<Plano>>('/plans/active').then((r) => r.data);

/** POST /plans → id do plano novo; se já há um gerando (409), o id dele (RN19). */
export async function pedirPlano(): Promise<number> {
  try {
    return (await api<Dados<{ id: number }>>('/plans', { method: 'POST' })).data.id;
  } catch (erro) {
    const emAndamento = erro instanceof ApiError && erro.code === 'PLAN_ALREADY_GENERATING' ? erro.details.planId : null;
    if (typeof emAndamento === 'number') return emAndamento;
    throw erro;
  }
}
```

`src/lib/chaves.ts` (substituir inteiro):
```ts
/** Chaves do React Query usadas por mais de uma feature. `['me']` fica em `features/auth/hooks.ts`. */
export const CHAVES = {
  catalogo: ['catalogo'],
  onboarding: ['onboarding'],
  previa: ['previa'],
  perfil: ['perfil'],
  dias: ['dia'],
  dia: (data: string) => ['dia', data] as const,
  plano: (id: number) => ['plano', id] as const,
} as const;
```

`src/features/dia/regras.ts`:
```ts
import { ApiError } from '@/lib/api/errors';
import type { Dia, Slot, StatusDoPlano, Totais } from './tipos';

const umaCasa = (n: number) => Math.round(n * 10) / 10;

function somar(partes: Totais[]): Totais {
  const t = partes.reduce(
    (a, p) => ({ calories: a.calories + p.calories, protein: a.protein + p.protein, carbs: a.carbs + p.carbs, fat: a.fat + p.fat }),
    { calories: 0, protein: 0, carbs: 0, fat: 0 },
  );
  return { calories: t.calories, protein: umaCasa(t.protein), carbs: umaCasa(t.carbs), fat: umaCasa(t.fat) };
}

/**
 * Atualização otimista ao marcar/desmarcar (RF13): refaz consumido, restante e a próxima
 * refeição com a mesma regra do backend (RN24). A resposta da API substitui o resultado.
 */
export function recalcularDia(dia: Dia, slot: Slot, done: boolean): Dia {
  const meals = dia.meals.map((m) => (m.slot === slot ? { ...m, done } : m));
  const proxima = dia.isToday ? meals.find((m) => !m.done)?.slot : undefined;
  const planned = dia.totals.planned;
  const consumed = somar(meals.filter((m) => m.done).map((m) => ({ calories: m.calories, ...m.macros })));
  const remaining: Totais = {
    calories: Math.max(0, planned.calories - consumed.calories),
    protein: umaCasa(Math.max(0, planned.protein - consumed.protein)),
    carbs: umaCasa(Math.max(0, planned.carbs - consumed.carbs)),
    fat: umaCasa(Math.max(0, planned.fat - consumed.fat)),
  };

  return {
    ...dia,
    meals: meals.map((m) => ({ ...m, isNext: m.slot === proxima })),
    totals: { planned, consumed, remaining },
  };
}

/** Segunda a domingo da semana de `hojeIso` (YYYY-MM-DD), sem depender do fuso do aparelho. */
export function semanaDe(hojeIso: string): string[] {
  const [a, m, d] = hojeIso.split('-').map(Number);
  const hoje = new Date(Date.UTC(a, m - 1, d));
  const segunda = new Date(hoje);
  segunda.setUTCDate(hoje.getUTCDate() - ((hoje.getUTCDay() + 6) % 7));
  return Array.from({ length: 7 }, (_, i) => {
    const dia = new Date(segunda);
    dia.setUTCDate(segunda.getUTCDate() + i);
    return dia.toISOString().slice(0, 10);
  });
}

/** "a, b e c". */
export function juntarComE(itens: string[]): string {
  if (itens.length <= 1) return itens[0] ?? '';
  return `${itens.slice(0, -1).join(', ')} e ${itens[itens.length - 1]}`;
}

/** O 409 `NO_ACTIVE_PLAN` diz o status do último plano (gerando, falhou ou nenhum). */
export function planoSemAtivo(erro: unknown): { status: StatusDoPlano | null; planId: number | null } | null {
  if (!(erro instanceof ApiError) || erro.code !== 'NO_ACTIVE_PLAN') return null;
  return {
    status: (erro.details.planStatus as StatusDoPlano | null | undefined) ?? null,
    planId: (erro.details.planId as number | null | undefined) ?? null,
  };
}
```

- [ ] **Step 3: Hooks**

`src/features/dia/hooks.ts`:
```ts
'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useToast } from '@/components/ui/Toaster';
import * as diaApi from '@/lib/api/dia';
import * as planos from '@/lib/api/planos';
import { CHAVES } from '@/lib/chaves';
import { recalcularDia } from './regras';
import type { Dia, Slot } from './tipos';

export const ERRO_AO_MARCAR = 'Não foi possível salvar. Tente de novo.';

export const useDia = (data = 'today') => useQuery({ queryKey: CHAVES.dia(data), queryFn: () => diaApi.getDia(data) });

/** RF13 — otimista: muda na hora, desfaz se a API falhar, fica com a resposta do servidor. */
export function useMarcarRefeicao() {
  const cliente = useQueryClient();
  const avisar = useToast();
  const chave = CHAVES.dia('today');

  return useMutation({
    mutationFn: ({ slot, done }: { slot: Slot; done: boolean }) => diaApi.marcarRefeicao(slot, done),
    onMutate: async ({ slot, done }) => {
      await cliente.cancelQueries({ queryKey: chave });
      const anterior = cliente.getQueryData<Dia>(chave);
      if (anterior) cliente.setQueryData<Dia>(chave, recalcularDia(anterior, slot, done));
      return { anterior };
    },
    onError: (_erro, _vars, contexto) => {
      if (contexto?.anterior) cliente.setQueryData(chave, contexto.anterior);
      avisar({ texto: ERRO_AO_MARCAR });
    },
    onSuccess: (dia) => cliente.setQueryData(chave, dia),
  });
}

export const useSubstituicoes = (itemId: number | null) =>
  useQuery({
    queryKey: ['substituicoes', itemId],
    queryFn: () => diaApi.getSubstituicoes(itemId as number),
    enabled: itemId !== null,
    staleTime: 0,
  });

export function useTrocarItem() {
  const cliente = useQueryClient();
  return useMutation({
    mutationFn: ({ itemId, foodId }: { itemId: number; foodId: number }) => diaApi.trocarItem(itemId, foodId),
    onSuccess: (dia) => cliente.setQueryData(CHAVES.dia('today'), dia),
  });
}

export function useDesfazer() {
  const cliente = useQueryClient();
  return useMutation({
    mutationFn: diaApi.desfazer,
    onSuccess: (dia) => cliente.setQueryData(CHAVES.dia('today'), dia),
  });
}

/** Acompanha a geração: consulta a cada 1,5 s enquanto `pending`/`generating` (e só com a tela aberta). */
export const usePlano = (id: number | null) =>
  useQuery({
    queryKey: CHAVES.plano(id ?? 0),
    queryFn: () => planos.getPlano(id as number),
    enabled: id !== null,
    refetchInterval: (query) => {
      const status = query.state.data?.status;
      return status === 'ready' || status === 'failed' ? false : 1500;
    },
    refetchIntervalInBackground: false,
  });

/** Pede um plano novo; o id volta mesmo quando já há um gerando. Os dias em cache caducam. */
export function usePedirPlano() {
  const cliente = useQueryClient();
  return useMutation({
    mutationFn: planos.pedirPlano,
    onSuccess: () => void cliente.invalidateQueries({ queryKey: CHAVES.dias }),
  });
}
```

- [ ] **Step 4: MSW**

`src/mocks/fixtures/dia.ts`:
```ts
/** Dia e plano como a API devolve (snake_case) — o dia da Camila do mock (segunda, dia de treino). */

type Item = { food_id: number; name: string; grams: number; amount: string; calories: number; protein: number; carbs: number; fat: number };

const ITENS: Record<string, Item[]> = {
  cafe: [
    { food_id: 2, name: 'Ovos mexidos', grams: 100, amount: '100 g, mais ou menos 2 unidades', calories: 168, protein: 12.9, carbs: 0.6, fat: 12.1 },
    { food_id: 36, name: 'Pão francês', grams: 50, amount: '50 g, mais ou menos 1 unidade', calories: 150, protein: 4, carbs: 29.3, fat: 1.6 },
    { food_id: 41, name: 'Mamão', grams: 150, amount: '150 g, mais ou menos 1 fatia', calories: 60, protein: 0.8, carbs: 15.6, fat: 0.2 },
  ],
  lanche: [
    { food_id: 40, name: 'Banana', grams: 120, amount: '120 g, mais ou menos 2 unidades', calories: 118, protein: 1.6, carbs: 31.2, fat: 0.1 },
    { food_id: 17, name: 'Iogurte natural', grams: 170, amount: '170 g, mais ou menos 1 pote', calories: 87, protein: 7, carbs: 3.2, fat: 5.1 },
  ],
  almoco: [
    { food_id: 28, name: 'Arroz branco cozido', grams: 150, amount: '150 g, mais ou menos 6 colheres de sopa', calories: 192, protein: 3.8, carbs: 42.2, fat: 0.3 },
    { food_id: 21, name: 'Feijão carioca', grams: 86, amount: '86 g, mais ou menos 1 concha', calories: 65, protein: 4.1, carbs: 11.7, fat: 0.4 },
    { food_id: 3, name: 'Frango grelhado', grams: 120, amount: '120 g, mais ou menos 1 filé médio', calories: 191, protein: 38.4, carbs: 0, fat: 3 },
    { food_id: 49, name: 'Salada de alface e tomate', grams: 100, amount: '100 g', calories: 13, protein: 1.1, carbs: 2.4, fat: 0.2 },
  ],
  'pre-treino': [
    { food_id: 30, name: 'Batata-doce cozida', grams: 150, amount: '150 g, mais ou menos 1 unidade média', calories: 116, protein: 0.9, carbs: 27.6, fat: 0.2 },
    { food_id: 40, name: 'Banana', grams: 60, amount: '60 g, mais ou menos 1 unidade', calories: 59, protein: 0.8, carbs: 15.6, fat: 0.1 },
  ],
  jantar: [
    { food_id: 6, name: 'Patinho moído', grams: 120, amount: '120 g, mais ou menos 5 colheres de sopa', calories: 263, protein: 43.1, carbs: 0, fat: 8.8 },
    { food_id: 35, name: 'Cuscuz de milho', grams: 120, amount: '120 g, mais ou menos 2 fatias', calories: 136, protein: 2.6, carbs: 30.4, fat: 0.8 },
    { food_id: 50, name: 'Brócolis no vapor', grams: 80, amount: '80 g, mais ou menos 4 ramos', calories: 20, protein: 1.7, carbs: 3.5, fat: 0.4 },
  ],
};

const REFEICOES = [
  { slot: 'cafe', name: 'Café da manhã', time: '07:00', note: null },
  { slot: 'lanche', name: 'Lanche da manhã', time: '10:00', note: null },
  { slot: 'almoco', name: 'Almoço', time: '12:30', note: null },
  { slot: 'pre-treino', name: 'Pré-treino', time: '17:30', note: null },
  { slot: 'jantar', name: 'Jantar', time: '20:30', note: 'Depois do treino das 19h' },
] as const;

const umaCasa = (n: number) => Math.round(n * 10) / 10;

export function refeicaoApi(slot: string, i: number, feita: boolean, proxima: boolean) {
  const base = REFEICOES.find((r) => r.slot === slot)!;
  const itens = ITENS[slot].map((it, j) => ({
    id: 5000 + i * 10 + j,
    food_id: it.food_id,
    name: it.name,
    grams: it.grams,
    amount: it.amount,
    calories: it.calories,
    macros: { protein: it.protein, carbs: it.carbs, fat: it.fat },
    source: 'plan',
    replaced_from: null as string | null,
  }));
  const soma = (k: 'protein' | 'carbs' | 'fat') => umaCasa(itens.reduce((s, it) => s + it.macros[k], 0));
  return {
    id: 900 + i,
    ...base,
    position: i + 1,
    done: feita,
    is_next: proxima,
    summary: itens.map((it, j) => (j === 0 ? it.name : it.name.toLowerCase())).join(', ').replace(/, ([^,]*)$/, ' e $1'),
    calories: itens.reduce((s, it) => s + it.calories, 0),
    macros: { protein: soma('protein'), carbs: soma('carbs'), fat: soma('fat') },
    items: itens,
  };
}

/** `GET /days/today` com as refeições feitas que o teste quiser. */
export function diaApi(parcial: { feitas?: string[]; data?: string; hoje?: boolean; ultimaAlteracao?: { id: number; text: string } } = {}) {
  const feitas = parcial.feitas ?? [];
  const hoje = parcial.hoje ?? true;
  const proxima = hoje ? REFEICOES.find((r) => !feitas.includes(r.slot))?.slot : undefined;
  const meals = REFEICOES.map((r, i) => refeicaoApi(r.slot, i, feitas.includes(r.slot), r.slot === proxima));
  const somar = (lista: typeof meals) => ({
    calories: lista.reduce((s, m) => s + m.calories, 0),
    protein: umaCasa(lista.reduce((s, m) => s + m.macros.protein, 0)),
    carbs: umaCasa(lista.reduce((s, m) => s + m.macros.carbs, 0)),
    fat: umaCasa(lista.reduce((s, m) => s + m.macros.fat, 0)),
  });
  const planned = somar(meals);
  const consumed = somar(meals.filter((m) => m.done));
  return {
    date: parcial.data ?? '2026-09-28',
    is_today: hoje,
    editable: hoje,
    materialized: hoje,
    is_training_day: true,
    targets: { kcal: 2250, protein_g: 115, carbs_g: 305, fat_g: 65 },
    totals: {
      planned,
      consumed,
      remaining: {
        calories: Math.max(0, planned.calories - consumed.calories),
        protein: umaCasa(Math.max(0, planned.protein - consumed.protein)),
        carbs: umaCasa(Math.max(0, planned.carbs - consumed.carbs)),
        fat: umaCasa(Math.max(0, planned.fat - consumed.fat)),
      },
    },
    meals,
    last_change: parcial.ultimaAlteracao ? { ...parcial.ultimaAlteracao, undo_until: '2026-09-28T11:15:00-03:00' } : null,
  };
}

export const substituicoesApi = {
  item: { id: 5020, name: 'Arroz branco cozido', amount: '150 g, mais ou menos 6 colheres de sopa', calories: 192, macros: { protein: 3.8, carbs: 42.2, fat: 0.3 } },
  options: [
    { food_id: 30, name: 'Batata-doce cozida', grams: 230, amount: '230 g, mais ou menos 1,5 unidades médias', calories: 177, macros: { protein: 1.4, carbs: 42.3, fat: 0.2 }, calorie_delta: -15, note: 'Energia que dura até o treino', in_pantry: true },
    { food_id: 33, name: 'Tapioca', grams: 70, amount: '70 g, mais ou menos 1 tapioca média', calories: 168, macros: { protein: 0, carbs: 42, fat: 0 }, calorie_delta: -24, note: 'Sem glúten e pronta em 3 minutos', in_pantry: true },
    { food_id: 29, name: 'Arroz integral', grams: 165, amount: '165 g, mais ou menos 6,5 colheres de sopa', calories: 205, macros: { protein: 4.3, carbs: 42.6, fat: 1.7 }, calorie_delta: 13, note: 'Mais fibra segura a fome até o treino', in_pantry: false },
  ],
  guarantee: { restrictions: ['Amendoim e castanhas'] },
};

export function planoProntoApi(id: number) {
  return {
    id,
    status: 'ready',
    is_active: true,
    ready_at: '2026-09-28T10:05:12-03:00',
    targets: { kcal: 2250, protein_g: 115, carbs_g: 305, fat_g: 65 },
    meals: REFEICOES.map((r, i) => {
      const m = refeicaoApi(r.slot, i, false, false);
      return { slot: m.slot, name: m.name, time: m.time, calories: m.calories, summary: m.summary };
    }),
    rating: null,
  };
}
```

`src/mocks/handlers/dia.ts`:
```ts
import { http, HttpResponse } from 'msw';
import { diaApi } from '../fixtures/dia';
import { perfilApi } from '../fixtures/onboarding';
import { url } from './auth';

/** Padrão: o dia da Camila (nada feito) e o perfil dela. */
export const handlersDia = [
  http.get(url('/days/today'), () => HttpResponse.json({ data: diaApi() })),
  http.get(url('/profile'), () => HttpResponse.json({ data: perfilApi })),
];

/** `GET /days/{data}` com este corpo (snake_case). */
export const respondendoDia = (dia: ReturnType<typeof diaApi>, data = 'today') =>
  http.get(url(`/days/${data}`), () => HttpResponse.json({ data: dia }));
```

`src/mocks/handlers/index.ts` (substituir inteiro):
```ts
import type { RequestHandler } from 'msw';
import { handlersAuth } from './auth';
import { handlersDia } from './dia';
import { handlersOnboarding } from './onboarding';

/** Handlers padrão de todas as integrações. Cada teste troca o que precisar com `server.use()`. */
export const handlers: RequestHandler[] = [...handlersAuth, ...handlersOnboarding, ...handlersDia];
```

- [ ] **Step 5: Rodar e ver passar**

Run: `docker compose run --rm web npx vitest run --project unit --project integration src/features/dia`
Expected: PASS.

- [ ] **Step 6: Suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(dia): tipos, chamadas, recálculo otimista, hooks e MSW do dia e do plano

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 3: Componentes do dia — `DayRail`, `MealRow`, `FoodItemRow`, `WeekDayPicker`, `NoPlanState`, `AvisoDeAlteracao`

**Files (repo front):**
- Create: `src/features/dia/components/{DayRail,MealRow,FoodItemRow,WeekDayPicker,NoPlanState,AvisoDeAlteracao}.tsx` e as stories `DayRail.stories.tsx`, `MealRow.stories.tsx`, `FoodItemRow.stories.tsx`, `WeekDayPicker.stories.tsx`, `NoPlanState.stories.tsx`
- Delete: `src/components/app/DayRail.tsx` (a versão do protótipo; o Hoje passa a usar a nova na Task 6 — até lá o Hoje antigo continua importando a antiga, então a remoção acontece na Task 6)

**Interfaces:**
- Consumes: tipos da Task 2 (`RefeicaoDoDia`, `ItemDoDia`, `Slot`, `Dia`), `useDesfazer` (Task 2), `Toast` (Plano 02), `cascata`/`useMontado` (`@/lib/motion`), `kcal`/`gramas` (`@/lib/format`).
- Produces:
  - `DayRail({ refeicoes, aoAlternar })` — `aoAlternar(slot: Slot, done: boolean)`; botão "Marcar {nome} como feita" (`aria-pressed=false`) só na próxima; links `/dieta/{slot}`.
  - `MealRow({ refeicao, indice, clicavel })` — link `/dieta/{slot}` só se `clicavel`; selo "Próxima refeição" (`isNext`) ou a nota.
  - `FoodItemRow({ item, indice, ultimo, aoTrocar? })` — sem `aoTrocar` não há botão "Trocar".
  - `WeekDayPicker({ dias, hoje, selecionado, aoEscolher })` — `tablist` "Dias da semana"; setas ←/→ (e Home/End) movem a escolha e o foco; só a aba escolhida tem `tabIndex=0`.
  - `NoPlanState({ estado: 'gerando' | 'falhou', planId, aoTentarDeNovo, tentando? })`.
  - `AvisoDeAlteracao({ alteracao })` — toast com "Desfazer" da última alteração; lembra (no módulo) as já dispensadas.

- [ ] **Step 1: Stories com `play` que devem falhar**

`src/features/dia/components/DayRail.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { camelizar } from '@/lib/api/case';
import { diaApi } from '@/mocks/fixtures/dia';
import type { Dia } from '../tipos';
import { DayRail } from './DayRail';

const refeicoes = (feitas: string[] = []) => camelizar<Dia>(diaApi({ feitas })).meals;

const meta = {
  title: 'Dia/DayRail',
  component: DayRail,
  args: { refeicoes: refeicoes(), aoAlternar: fn() },
} satisfies Meta<typeof DayRail>;

export default meta;
type Story = StoryObj<typeof meta>;

export const ManhaNadaFeito: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await expect(tela.getByText('0 de 5 refeições')).toBeInTheDocument();
    await expect(tela.getByRole('heading', { name: 'Café da manhã' })).toBeInTheDocument();
    const marcar = tela.getByRole('button', { name: 'Marcar café da manhã como feita' });
    await expect(marcar).toHaveAttribute('aria-pressed', 'false');
    await userEvent.click(marcar);
    await expect(args.aoAlternar).toHaveBeenCalledWith('cafe', true);
  },
};

export const MeioDoDia: Story = {
  args: { refeicoes: refeicoes(['cafe', 'lanche']) },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByText('2 de 5 refeições')).toBeInTheDocument();
    await expect(tela.getByRole('heading', { name: 'Almoço' })).toBeInTheDocument();
    await expect(tela.getByRole('link', { name: 'Ver refeição' })).toHaveAttribute('href', '/dieta/almoco');
    await expect(tela.getByRole('link', { name: 'Café da manhã' })).toHaveAttribute('href', '/dieta/cafe');
  },
};

export const TudoFeito: Story = {
  args: { refeicoes: refeicoes(['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar']) },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByText('5 de 5 refeições')).toBeInTheDocument();
    await expect(tela.queryByRole('button')).toBeNull();
  },
};

export const UmaTrocada: Story = {
  args: {
    refeicoes: refeicoes(['cafe', 'lanche']).map((m) =>
      m.slot === 'almoco' ? { ...m, summary: 'Batata-doce cozida, feijão carioca, frango grelhado e salada de alface e tomate' } : m,
    ),
  },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText(/Batata-doce cozida, feijão/)).toBeInTheDocument();
  },
};
```

`src/features/dia/components/MealRow.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, within } from 'storybook/test';
import { camelizar } from '@/lib/api/case';
import { diaApi } from '@/mocks/fixtures/dia';
import type { Dia } from '../tipos';
import { MealRow } from './MealRow';

const dia = (feitas: string[] = []) => camelizar<Dia>(diaApi({ feitas })).meals;

const meta = {
  title: 'Dia/MealRow',
  component: MealRow,
  decorators: [(Story) => <ol className="list-none pl-6"><Story /></ol>],
  args: { refeicao: dia()[0], indice: 0, clicavel: true },
} satisfies Meta<typeof MealRow>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Proxima: Story = {
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByText('Próxima refeição')).toBeInTheDocument();
    await expect(tela.getByRole('link')).toHaveAttribute('href', '/dieta/cafe');
  },
};

export const Feita: Story = {
  args: { refeicao: dia(['cafe'])[0] },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByLabelText('Feita')).toBeInTheDocument();
  },
};

export const ComNota: Story = {
  args: { refeicao: dia()[4], indice: 4 },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('Depois do treino das 19h')).toBeInTheDocument();
  },
};

export const NaoClicavel: Story = {
  args: { refeicao: { ...dia()[0], isNext: false }, clicavel: false },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).queryByRole('link')).toBeNull();
  },
};

export const DiaSemTreino: Story = {
  args: { refeicao: { ...dia()[3], name: 'Lanche da tarde', isNext: false }, indice: 3, clicavel: false },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('Lanche da tarde')).toBeInTheDocument();
  },
};
```

`src/features/dia/components/FoodItemRow.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { camelizar } from '@/lib/api/case';
import { diaApi } from '@/mocks/fixtures/dia';
import type { Dia } from '../tipos';
import { FoodItemRow } from './FoodItemRow';

const arroz = camelizar<Dia>(diaApi()).meals[2].items[0];

const meta = {
  title: 'Dia/FoodItemRow',
  component: FoodItemRow,
  decorators: [(Story) => <ul className="list-none rounded-[20px] bg-white px-[18px]"><Story /></ul>],
  args: { item: arroz, indice: 0, ultimo: true, aoTrocar: fn() },
} satisfies Meta<typeof FoodItemRow>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Normal: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await expect(tela.getByText('150 g, mais ou menos 6 colheres de sopa')).toBeInTheDocument();
    await userEvent.click(tela.getByRole('button', { name: 'Trocar Arroz branco cozido' }));
    await expect(args.aoTrocar).toHaveBeenCalled();
  },
};

export const Trocado: Story = {
  args: { item: { ...arroz, name: 'Batata-doce cozida', replacedFrom: 'Arroz branco cozido', source: 'manual' } },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByText('Trocado')).toBeInTheDocument();
    await expect(tela.getByText('No lugar de arroz branco cozido')).toBeInTheDocument();
  },
};

export const SemBotaoTrocar: Story = {
  args: { aoTrocar: undefined },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).queryByRole('button')).toBeNull();
  },
};
```

`src/features/dia/components/WeekDayPicker.stories.tsx`:
```tsx
import { useState } from 'react';
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { semanaDe } from '../regras';
import { WeekDayPicker } from './WeekDayPicker';

const dias = semanaDe('2026-09-30');

const meta = {
  title: 'Dia/WeekDayPicker',
  component: WeekDayPicker,
  args: { dias, hoje: '2026-09-30', selecionado: '2026-09-30', aoEscolher: fn() },
  render: function Controlado(args) {
    const [selecionado, setSelecionado] = useState(args.selecionado);
    return (
      <WeekDayPicker
        {...args}
        selecionado={selecionado}
        aoEscolher={(d) => {
          setSelecionado(d);
          args.aoEscolher(d);
        }}
      />
    );
  },
} satisfies Meta<typeof WeekDayPicker>;

export default meta;
type Story = StoryObj<typeof meta>;

export const HojeSelecionado: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    const hoje = tela.getByRole('tab', { name: /qua 30/ });
    await expect(hoje).toHaveAttribute('aria-selected', 'true');
    await expect(hoje).toHaveAttribute('tabindex', '0');
    await expect(tela.getByRole('tab', { name: /seg 28/ })).toHaveAttribute('tabindex', '-1');

    hoje.focus();
    await userEvent.keyboard('{ArrowRight}');
    await expect(args.aoEscolher).toHaveBeenCalledWith('2026-10-01');
    await expect(tela.getByRole('tab', { name: /qui 1/ })).toHaveFocus();
    await userEvent.keyboard('{Home}');
    await expect(tela.getByRole('tab', { name: /seg 28/ })).toHaveFocus();
  },
};

export const OutroDia: Story = {
  args: { selecionado: '2026-10-04' },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('tab', { name: /dom 4/ })).toHaveAttribute('aria-selected', 'true');
  },
};
```

`src/features/dia/components/NoPlanState.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { NoPlanState } from './NoPlanState';

const meta = {
  title: 'Dia/NoPlanState',
  component: NoPlanState,
  args: { estado: 'gerando', planId: 43, aoTentarDeNovo: fn() },
} satisfies Meta<typeof NoPlanState>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Gerando: Story = {
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByRole('heading', { name: 'Seu plano está quase pronto' })).toBeInTheDocument();
    await expect(tela.getByRole('link', { name: 'Acompanhar' })).toHaveAttribute('href', '/onboarding/gerando?plano=43&voltar=%2Fhoje');
  },
};

export const Falhou: Story = {
  args: { estado: 'falhou' },
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await expect(tela.getByRole('heading', { name: 'Não conseguimos montar seu plano' })).toBeInTheDocument();
    await userEvent.click(tela.getByRole('button', { name: 'Tentar de novo' }));
    await expect(args.aoTentarDeNovo).toHaveBeenCalled();
  },
};
```

Run: `docker compose run --rm web npx vitest run --project storybook src/features/dia`
Expected: FAIL — os componentes não existem.

- [ ] **Step 2: Implementar os componentes** (marcação do protótipo, agora com os tipos da API)

`src/features/dia/components/DayRail.tsx`:
```tsx
"use client";

import Link from "next/link";
import { IconeCheck } from "@/components/icons";
import { kcal } from "@/lib/format";
import { cascata, useMontado } from "@/lib/motion";
import type { RefeicaoDoDia, Slot } from "../tipos";

const ALTURA_LINHA = 46;

/**
 * A linha do dia: o dia inteiro como um traço vertical, com a próxima refeição aberta na posição dela.
 * Responde "o que eu preciso fazer hoje?" com comida, e não com número.
 */
export function DayRail({
  refeicoes,
  aoAlternar,
}: {
  refeicoes: RefeicaoDoDia[];
  aoAlternar: (slot: Slot, done: boolean) => void;
}) {
  const montado = useMontado(120);
  const proxima = refeicoes.find((m) => m.isNext);
  const feitas = refeicoes.filter((m) => m.done).length;
  const indiceProxima = proxima ? refeicoes.indexOf(proxima) : refeicoes.length;

  return (
    <section className="animate-escala rounded-3xl bg-tinta px-[18px] pt-4 pb-[18px] text-neve">
      <div className="mb-3 flex items-baseline justify-between">
        <h2 className="animate-entra font-display text-[15px] font-semibold">Seu dia</h2>
        <p className="animate-entra text-[12.5px] text-musgo" style={{ animationDelay: "80ms" }}>
          {feitas} de {refeicoes.length} refeições
        </p>
      </div>

      <ol className="relative list-none pl-[26px]">
        <span className="absolute top-2.5 bottom-2.5 left-1.5 block w-0.5 rounded-full bg-breu" />
        {/* o trecho verde cresce até a refeição da vez */}
        <span
          className="absolute top-2.5 left-1.5 block w-0.5 rounded-full bg-mata transition-[height] duration-[900ms] ease-[cubic-bezier(.22,1,.36,1)]"
          style={{ height: montado ? `${indiceProxima * ALTURA_LINHA}px` : "0px" }}
        />

        {refeicoes.map((refeicao, i) =>
          refeicao.slot === proxima?.slot ? (
            <ProximaRefeicao key={refeicao.slot} refeicao={refeicao} indice={i} aoAlternar={aoAlternar} />
          ) : (
            <LinhaCompacta key={refeicao.slot} refeicao={refeicao} indice={i} />
          ),
        )}
      </ol>
    </section>
  );
}

function LinhaCompacta({ refeicao, indice }: { refeicao: RefeicaoDoDia; indice: number }) {
  const feita = refeicao.done;
  return (
    <li className="relative flex h-[46px] animate-entra-lado-esq items-center gap-2.5" style={cascata(indice, 70, 140)}>
      {feita ? (
        <span className="absolute left-0 flex size-3.5 animate-pop items-center justify-center rounded-full bg-mata text-tinta">
          <IconeCheck size={9} strokeWidth={2.4} />
        </span>
      ) : (
        <span className="absolute left-0.5 size-2.5 rounded-full border-[1.5px] border-[#4a5d52] transition-colors duration-300" />
      )}
      <span className={`w-11 text-[12.5px] ${feita ? "text-cinza-treino" : "text-musgo"}`}>{refeicao.time}</span>
      <Link
        href={`/dieta/${refeicao.slot}`}
        className={`flex-1 text-[14.5px] transition-colors duration-200 hover:text-gema ${
          feita ? "text-musgo line-through decoration-musgo/40" : "text-neve"
        }`}
      >
        {refeicao.name}
      </Link>
      <span className={`text-[12.5px] ${feita ? "text-cinza-treino" : "text-musgo"}`}>{kcal(refeicao.calories)}</span>
    </li>
  );
}

function ProximaRefeicao({
  refeicao,
  indice,
  aoAlternar,
}: {
  refeicao: RefeicaoDoDia;
  indice: number;
  aoAlternar: (slot: Slot, done: boolean) => void;
}) {
  return (
    <li className="relative my-2 animate-escala" style={cascata(indice, 70, 180)}>
      {/* o marcador da vez pulsa devagar: é o único ponto em movimento da tela */}
      <span className="absolute top-5 -left-7 block size-[18px]">
        <span className="absolute inset-0 animate-halo rounded-full bg-gema" />
        <span className="absolute inset-0 rounded-full bg-gema shadow-[0_0_0_4px_var(--color-tinta)]" />
      </span>

      <div className="rounded-2xl bg-white p-3.5 text-tinta shadow-[0_18px_40px_-26px_rgba(0,0,0,.8)]">
        <div className="flex items-baseline justify-between gap-3">
          <h3 className="font-display text-[21px] font-bold tracking-[-0.02em]">{refeicao.name}</h3>
          <span className="shrink-0 text-[12.5px] font-semibold text-fumo">{refeicao.time}</span>
        </div>
        <p className="mt-1.5 text-[13.5px] leading-snug text-fumo first-letter:uppercase">{refeicao.summary}</p>
        <div className="mt-3 flex gap-4 border-t border-fio pt-2.5">
          <span className="text-[13px] font-semibold">
            {Math.round(refeicao.calories)} <span className="font-medium text-fumo">kcal</span>
          </span>
          <span className="text-[13px] font-semibold">
            {Math.round(refeicao.macros.protein)} g <span className="font-medium text-fumo">proteína</span>
          </span>
        </div>
        <div className="mt-3 flex gap-2">
          <Link
            href={`/dieta/${refeicao.slot}`}
            className="group relative flex h-11 flex-1 items-center justify-center overflow-hidden rounded-full bg-gema text-sm font-semibold text-tinta transition-[filter,box-shadow] duration-250 hover:brightness-[.97] hover:shadow-[0_8px_20px_-10px_rgba(21,37,28,.6)] active:scale-[0.97]"
          >
            <span className="pointer-events-none absolute inset-0 -translate-x-full bg-linear-100 from-transparent via-white/45 to-transparent transition-transform duration-700 ease-out group-hover:translate-x-full motion-reduce:hidden" />
            <span className="relative">Ver refeição</span>
          </Link>
          <button
            type="button"
            onClick={() => aoAlternar(refeicao.slot, true)}
            aria-pressed={false}
            aria-label={`Marcar ${refeicao.name.toLowerCase()} como feita`}
            className="group flex size-11 shrink-0 items-center justify-center rounded-full border-[1.5px] border-tinta transition-[background-color,color] duration-250 hover:bg-tinta hover:text-neve active:scale-90"
          >
            <IconeCheck
              size={19}
              strokeWidth={2}
              className="transition-transform duration-300 ease-[cubic-bezier(.34,1.56,.64,1)] group-hover:scale-110"
            />
          </button>
        </div>
      </div>
    </li>
  );
}
```

`src/features/dia/components/MealRow.tsx`:
```tsx
import Link from "next/link";
import { IconeCheck } from "@/components/icons";
import { kcal } from "@/lib/format";
import { cascata } from "@/lib/motion";
import type { RefeicaoDoDia } from "../tipos";

/** Uma refeição na lista da Dieta. Só hoje abre o detalhe (RN23). */
export function MealRow({ refeicao, indice, clicavel }: { refeicao: RefeicaoDoDia; indice: number; clicavel: boolean }) {
  const proxima = refeicao.isNext;

  const corpo = (
    <>
      <div className="flex items-baseline gap-2.5">
        <span className="w-11 text-[12.5px] font-semibold text-fumo">{refeicao.time}</span>
        <span className="flex-1 text-base font-semibold tracking-[-0.01em]">{refeicao.name}</span>
        <span className={`text-[12.5px] ${proxima ? "font-semibold" : "text-fumo"}`}>{kcal(refeicao.calories)}</span>
      </div>
      <p className="mt-1 pl-[54px] text-[13px] leading-snug text-fumo first-letter:uppercase">{refeicao.summary}</p>
      {proxima ? (
        <span className="mt-2.5 ml-[54px] inline-flex h-[26px] items-center gap-1.5 rounded-full bg-gema-fraca px-2.5">
          <span className="size-1.5 rounded-full bg-gema" />
          <span className="text-[11.5px] font-semibold text-gema-texto">Próxima refeição</span>
        </span>
      ) : refeicao.note ? (
        <span className="mt-2.5 ml-[54px] inline-flex h-[26px] items-center rounded-full bg-mata-fraca px-2.5 text-[11.5px] font-semibold text-mata-texto">
          {refeicao.note}
        </span>
      ) : null}
    </>
  );

  return (
    <li className="relative mb-2.5 animate-entra-lado-esq last:mb-0" style={cascata(indice, 75, 180)}>
      {refeicao.done ? (
        <span
          aria-label="Feita"
          role="img"
          className="absolute top-[22px] -left-6 flex size-3 animate-pop items-center justify-center rounded-full bg-mata text-white"
        >
          <IconeCheck size={8} strokeWidth={2.4} />
        </span>
      ) : proxima ? (
        <span className="absolute top-5 -left-[26px] block size-4">
          <span className="absolute inset-0 animate-halo rounded-full bg-gema" />
          <span className="absolute inset-0 rounded-full bg-gema shadow-[0_0_0_3px_var(--color-papel)]" />
        </span>
      ) : (
        <span className="absolute top-[23px] -left-[23px] size-2.5 rounded-full border-[1.5px] border-pedra bg-papel" />
      )}

      {clicavel ? (
        <Link
          href={`/dieta/${refeicao.slot}`}
          className={`block rounded-[18px] border bg-white px-4 py-3.5 transition-[border-color,box-shadow,transform] duration-250 ease-[cubic-bezier(.22,1,.36,1)] hover:-translate-y-0.5 hover:border-pedra hover:shadow-[0_14px_30px_-22px_rgba(21,37,28,.9)] active:scale-[0.99] ${
            proxima ? "border-tinta shadow-[inset_0_0_0_1px_var(--color-tinta)]" : "border-transparent"
          } ${refeicao.done ? "opacity-60" : ""}`}
        >
          {corpo}
        </Link>
      ) : (
        <div className="block rounded-[18px] border border-transparent bg-white px-4 py-3.5">{corpo}</div>
      )}
    </li>
  );
}
```

`src/features/dia/components/FoodItemRow.tsx`:
```tsx
import { gramas, kcal } from "@/lib/format";
import { cascata } from "@/lib/motion";
import type { ItemDoDia } from "../tipos";

/** Um alimento do prato: porção em medida caseira, kcal e macros, selo "Trocado". */
export function FoodItemRow({
  item,
  indice,
  ultimo,
  aoTrocar,
}: {
  item: ItemDoDia;
  indice: number;
  ultimo: boolean;
  aoTrocar?: () => void;
}) {
  return (
    <li
      style={cascata(indice, 65, 360)}
      className={`flex animate-entra-lado-esq items-start gap-3 py-[15px] ${ultimo ? "" : "border-b border-fio"}`}
    >
      <div className="flex-1">
        <div className="flex items-center gap-2">
          <span className="text-[15px] font-semibold tracking-[-0.01em]">{item.name}</span>
          {item.replacedFrom ? (
            <span className="inline-flex h-[22px] animate-pop items-center rounded-full bg-mata-fraca px-2.5 text-[11px] font-semibold text-mata-texto">
              Trocado
            </span>
          ) : null}
        </div>
        <p className="mt-0.5 text-[13px] text-fumo">{item.amount}</p>
        <p className="mt-1 text-xs text-fumo">
          <b className="font-semibold text-[#3d4a42]">{kcal(item.calories)}</b>
          {item.macros.protein >= 1 ? `   ${gramas(item.macros.protein)} proteína` : ""}
          {item.macros.carbs >= 1 ? `   ${gramas(item.macros.carbs)} carbo` : ""}
          {item.macros.fat >= 5 ? `   ${gramas(item.macros.fat)} gordura` : ""}
        </p>
        {item.replacedFrom ? <p className="mt-1 text-xs text-fumo">No lugar de {item.replacedFrom.toLowerCase()}</p> : null}
      </div>
      {aoTrocar ? (
        <button
          type="button"
          onClick={aoTrocar}
          className="flex h-11 shrink-0 items-center rounded-full border-[1.5px] border-linha px-4 text-[13px] font-semibold transition-[border-color,background-color,transform] duration-250 hover:-translate-y-px hover:border-tinta hover:bg-white active:scale-95"
        >
          Trocar
          <span className="sr-only"> {item.name}</span>
        </button>
      ) : null}
    </li>
  );
}
```

`src/features/dia/components/WeekDayPicker.tsx`:
```tsx
"use client";

import { useRef } from "react";
import { cascata } from "@/lib/motion";

const DIAS_CURTOS = ["dom", "seg", "ter", "qua", "qui", "sex", "sáb"];
const meioDia = (iso: string) => new Date(`${iso}T12:00:00`);

/** Segunda a domingo; setas, Home e End trocam o dia (padrão de abas do WAI-ARIA). */
export function WeekDayPicker({
  dias,
  hoje,
  selecionado,
  aoEscolher,
}: {
  dias: string[];
  hoje: string;
  selecionado: string;
  aoEscolher: (dia: string) => void;
}) {
  const abas = useRef<(HTMLButtonElement | null)[]>([]);

  function escolher(indice: number) {
    const destino = Math.max(0, Math.min(dias.length - 1, indice));
    aoEscolher(dias[destino]);
    abas.current[destino]?.focus();
  }

  function aoTeclar(evento: React.KeyboardEvent, indice: number) {
    const para = { ArrowRight: indice + 1, ArrowLeft: indice - 1, Home: 0, End: dias.length - 1 }[evento.key];
    if (para === undefined) return;
    evento.preventDefault();
    escolher(para);
  }

  return (
    <div className="mt-3.5 flex justify-between" role="tablist" aria-label="Dias da semana">
      {dias.map((dia, i) => {
        const ativo = dia === selecionado;
        const data = meioDia(dia);
        return (
          <button
            key={dia}
            ref={(el) => {
              abas.current[i] = el;
            }}
            type="button"
            role="tab"
            aria-selected={ativo}
            aria-current={dia === hoje ? "date" : undefined}
            tabIndex={ativo ? 0 : -1}
            onClick={() => aoEscolher(dia)}
            onKeyDown={(e) => aoTeclar(e, i)}
            style={cascata(i, 45, 120)}
            className={`flex h-14 w-[42px] animate-pop flex-col items-center justify-center gap-[3px] rounded-[14px] border transition-[background-color,color,border-color,transform,box-shadow] duration-250 ease-[cubic-bezier(.34,1.56,.64,1)] active:scale-90 ${
              ativo
                ? "-translate-y-0.5 border-tinta bg-tinta text-white shadow-[0_10px_20px_-14px_rgba(21,37,28,1)]"
                : "border-linha bg-white text-tinta hover:border-pedra"
            }`}
          >
            <span className="text-[10.5px] font-medium opacity-80">{DIAS_CURTOS[data.getDay()]}</span>
            <span className="text-[15px] font-semibold">{data.getDate()}</span>
          </button>
        );
      })}
    </div>
  );
}
```

`src/features/dia/components/NoPlanState.tsx`:
```tsx
import Link from "next/link";
import { Button } from "@/components/ui/Button";

/** Hoje/Dieta sem plano ativo (409 `NO_ACTIVE_PLAN`): o plano está gerando ou a última geração falhou. */
export function NoPlanState({
  estado,
  planId,
  aoTentarDeNovo,
  tentando = false,
}: {
  estado: "gerando" | "falhou";
  planId: number | null;
  aoTentarDeNovo: () => void;
  tentando?: boolean;
}) {
  const gerando = estado === "gerando";
  return (
    <section className="animate-escala rounded-3xl bg-tinta px-6 py-7 text-neve">
      <span className="relative mb-5 block size-10">
        <span className={`absolute inset-0 rounded-full ${gerando ? "animate-halo bg-gema/60" : "bg-alerta/30"}`} />
        <span className={`absolute inset-2.5 rounded-full ${gerando ? "animate-respira bg-gema" : "bg-alerta"}`} />
      </span>
      <h2 className="animate-entra font-display text-[24px] leading-tight font-bold tracking-[-0.025em]">
        {gerando ? "Seu plano está quase pronto" : "Não conseguimos montar seu plano"}
      </h2>
      <p className="mt-2 animate-entra text-[14px] leading-normal text-musgo" style={{ animationDelay: "80ms" }}>
        {gerando
          ? "O Nutri está terminando de encaixar as refeições nos seus horários."
          : "Seus dados estão salvos. Foi a conexão com o Nutri que falhou no meio do caminho."}
      </p>
      <div className="mt-5 animate-entra" style={{ animationDelay: "160ms" }}>
        {gerando && planId !== null ? (
          <Link
            href={`/onboarding/gerando?plano=${planId}&voltar=${encodeURIComponent("/hoje")}`}
            className="inline-flex h-11 items-center rounded-full bg-gema px-5 text-sm font-semibold text-tinta transition-transform duration-200 active:scale-95"
          >
            Acompanhar
          </Link>
        ) : (
          <Button variante="contorno-escuro" carregando={tentando} onClick={aoTentarDeNovo}>
            Tentar de novo
          </Button>
        )}
      </div>
    </section>
  );
}
```

`src/features/dia/components/AvisoDeAlteracao.tsx`:
```tsx
"use client";

import { useState } from "react";
import { Toast } from "@/components/ui/Toast";
import { useDesfazer } from "../hooks";
import type { Dia } from "../tipos";

/** Alterações cujo aviso já sumiu: voltar à tela não mostra de novo (a API guarda por 15 min). */
const dispensadas = new Set<number>();

/** Toast da última alteração do dia, com "Desfazer" (RF15). */
export function AvisoDeAlteracao({ alteracao }: { alteracao: Dia["lastChange"] }) {
  const desfazer = useDesfazer();
  const [, redesenhar] = useState(0);

  if (!alteracao || dispensadas.has(alteracao.id)) return null;
  const dispensar = () => {
    dispensadas.add(alteracao.id);
    redesenhar((n) => n + 1);
  };

  return (
    <Toast
      key={alteracao.id}
      texto={alteracao.text}
      acao={{
        rotulo: "Desfazer",
        onClick: () => {
          dispensar();
          desfazer.mutate();
        },
      }}
      aoExpirar={dispensar}
    />
  );
}
```

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm web npx vitest run --project storybook src/features/dia`
Expected: PASS (inclui axe).

- [ ] **Step 4: Lint e commit**

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck"`
Expected: OK.

```bash
git add -A && git commit -m "feat(dia): DayRail, MealRow, FoodItemRow, WeekDayPicker, NoPlanState e aviso de alteração

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `SubstitutionSheet` — a folha de troca

**Files (repo front):**
- Create: `src/features/dia/components/SubstitutionSheet.tsx`, `SubstitutionSheet.stories.tsx`

**Interfaces:**
- Consumes: `Sheet`, `Button`, `Skeleton` (Plano 02), `Substituicoes`/`ItemDoDia` (Task 2), `juntarComE` (Task 2).
- Produces: `SubstitutionSheet({ item, substituicoes, carregando, erro, trocando, aoTentarDeNovo, aoTrocar, aoFechar })` — `item: ItemDoDia | null` (null = fechada); `aoTrocar(foodId: number)`; o `radiogroup` "Opções de troca" começa na 1ª opção; setas ↑/↓/←/→ trocam a escolha e o foco (só a marcada tem `tabIndex=0`).

- [ ] **Step 1: Stories que devem falhar**

`src/features/dia/components/SubstitutionSheet.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { camelizar } from '@/lib/api/case';
import { diaApi, substituicoesApi } from '@/mocks/fixtures/dia';
import type { Dia, Substituicoes } from '../tipos';
import { SubstitutionSheet } from './SubstitutionSheet';

const arroz = camelizar<Dia>(diaApi()).meals[2].items[0];
const opcoes = camelizar<Substituicoes>(substituicoesApi);

const meta = {
  title: 'Dia/SubstitutionSheet',
  component: SubstitutionSheet,
  args: {
    item: arroz,
    substituicoes: opcoes,
    carregando: false,
    erro: false,
    trocando: false,
    aoTentarDeNovo: fn(),
    aoTrocar: fn(),
    aoFechar: fn(),
  },
} satisfies Meta<typeof SubstitutionSheet>;

export default meta;
type Story = StoryObj<typeof meta>;
const folha = (canvasElement: HTMLElement) => within(canvasElement.ownerDocument.body);

export const ComOpcoes: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = folha(canvasElement);
    await expect(await tela.findByRole('dialog', { name: 'Trocar arroz branco cozido' })).toBeInTheDocument();
    const primeira = tela.getByRole('radio', { name: /Batata-doce cozida/ });
    await expect(primeira).toHaveAttribute('aria-checked', 'true');
    await expect(tela.getByText('−15 kcal')).toBeInTheDocument();
    await expect(tela.getByText('+13 kcal')).toBeInTheDocument();

    primeira.focus();
    await userEvent.keyboard('{ArrowDown}');
    const tapioca = tela.getByRole('radio', { name: /Tapioca/ });
    await expect(tapioca).toHaveAttribute('aria-checked', 'true');
    await expect(tapioca).toHaveFocus();

    await userEvent.click(tela.getByRole('button', { name: 'Usar tapioca' }));
    await expect(args.aoTrocar).toHaveBeenCalledWith(33);
  },
};

export const GarantiaComTodasAsRestricoes: Story = {
  args: { substituicoes: { ...opcoes, guarantee: { restrictions: ['Intolerância a lactose', 'Glúten', 'camarão'] } } },
  play: async ({ canvasElement }) => {
    await expect(
      await folha(canvasElement).findByText('Nenhuma dessas opções tem intolerância a lactose, glúten e camarão.'),
    ).toBeInTheDocument();
  },
};

export const Carregando: Story = {
  args: { substituicoes: undefined, carregando: true },
  play: async ({ canvasElement }) => {
    await expect(await folha(canvasElement).findByLabelText('Buscando opções')).toBeInTheDocument();
  },
};

export const Vazia: Story = {
  args: { substituicoes: { ...opcoes, options: [] } },
  play: async ({ canvasElement }) => {
    const tela = folha(canvasElement);
    await expect(await tela.findByText(/Ainda não temos trocas cadastradas para este alimento/)).toBeInTheDocument();
    await expect(tela.getByRole('link', { name: 'Perguntar ao Nutri' })).toHaveAttribute(
      'href',
      `/nutri?pergunta=${encodeURIComponent('Não tenho arroz branco cozido em casa. O que uso no lugar?')}`,
    );
  },
};

export const Erro: Story = {
  args: { substituicoes: undefined, erro: true },
  play: async ({ canvasElement, args }) => {
    await userEvent.click(await folha(canvasElement).findByRole('button', { name: 'Tentar de novo' }));
    await expect(args.aoTentarDeNovo).toHaveBeenCalled();
  },
};

export const Trocando: Story = {
  args: { trocando: true },
  play: async ({ canvasElement }) => {
    await expect(await folha(canvasElement).findByRole('button', { name: /Usar batata-doce cozida/ })).toHaveAttribute('aria-busy', 'true');
  },
};

export const EscFecha: Story = {
  play: async ({ canvasElement, args }) => {
    await folha(canvasElement).findByRole('dialog');
    await userEvent.keyboard('{Escape}');
    await expect(args.aoFechar).toHaveBeenCalled();
  },
};
```

Run: `docker compose run --rm web npx vitest run --project storybook src/features/dia/components/SubstitutionSheet.stories.tsx`
Expected: FAIL — componente inexistente.

- [ ] **Step 2: Implementar**

`src/features/dia/components/SubstitutionSheet.tsx`:
```tsx
"use client";

import { useRef, useState } from "react";
import Link from "next/link";
import { IconeCheck } from "@/components/icons";
import { Button } from "@/components/ui/Button";
import { Sheet } from "@/components/ui/Sheet";
import { Skeleton } from "@/components/ui/Skeleton";
import { gramas } from "@/lib/format";
import { cascata } from "@/lib/motion";
import { juntarComE } from "../regras";
import type { ItemDoDia, Substituicoes } from "../tipos";

const delta = (n: number) => `${n > 0 ? "+" : n < 0 ? "−" : ""}${Math.abs(Math.round(n))} kcal`;

/** RF14 — trocar um alimento por um equivalente; toda opção já respeita as restrições (RN17). */
export function SubstitutionSheet({
  item,
  substituicoes,
  carregando,
  erro,
  trocando,
  aoTentarDeNovo,
  aoTrocar,
  aoFechar,
}: {
  item: ItemDoDia | null;
  substituicoes: Substituicoes | undefined;
  carregando: boolean;
  erro: boolean;
  trocando: boolean;
  aoTentarDeNovo: () => void;
  aoTrocar: (foodId: number) => void;
  aoFechar: () => void;
}) {
  return (
    <Sheet
      aberta={item !== null}
      aoFechar={aoFechar}
      titulo={`Trocar ${item?.name.toLowerCase() ?? ""}`}
      descricao={
        item
          ? `${item.amount} trazem ${gramas(item.macros.carbs)} de carboidrato e ${gramas(item.macros.protein)} de proteína. Estas opções chegam perto e mantêm o resto do prato.`
          : undefined
      }
    >
      {item === null ? null : carregando ? (
        <div className="mt-4 flex flex-col gap-2" role="status" aria-label="Buscando opções">
          <Skeleton className="h-[86px]" />
          <Skeleton className="h-[86px]" />
          <Skeleton className="h-[86px]" />
        </div>
      ) : erro || !substituicoes ? (
        <div className="mt-4">
          <p className="text-sm leading-normal text-fumo">Não foi possível buscar as opções agora.</p>
          <Button variante="contorno" className="mt-4" onClick={aoTentarDeNovo}>
            Tentar de novo
          </Button>
        </div>
      ) : substituicoes.options.length === 0 ? (
        <div className="mt-4">
          <p className="text-sm leading-normal text-fumo">
            Ainda não temos trocas cadastradas para este alimento. O Nutri consegue sugerir uma a partir do que você tem em casa.
          </p>
          <Link
            href={`/nutri?pergunta=${encodeURIComponent(`Não tenho ${item.name.toLowerCase()} em casa. O que uso no lugar?`)}`}
            className="mt-4 flex h-[54px] items-center justify-center rounded-full bg-gema text-base font-semibold text-tinta"
          >
            Perguntar ao Nutri
          </Link>
        </div>
      ) : (
        <Opcoes key={item.id} substituicoes={substituicoes} trocando={trocando} aoTrocar={aoTrocar} aoFechar={aoFechar} />
      )}
    </Sheet>
  );
}

function Opcoes({
  substituicoes,
  trocando,
  aoTrocar,
  aoFechar,
}: {
  substituicoes: Substituicoes;
  trocando: boolean;
  aoTrocar: (foodId: number) => void;
  aoFechar: () => void;
}) {
  const { options, guarantee } = substituicoes;
  const [escolhida, setEscolhida] = useState(options[0].foodId);
  const radios = useRef<(HTMLButtonElement | null)[]>([]);
  const opcao = options.find((o) => o.foodId === escolhida) ?? options[0];

  function aoTeclar(evento: React.KeyboardEvent, indice: number) {
    const passo = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 }[evento.key];
    if (passo === undefined) return;
    evento.preventDefault();
    const destino = (indice + passo + options.length) % options.length;
    setEscolhida(options[destino].foodId);
    radios.current[destino]?.focus();
  }

  return (
    <>
      <div className="mt-4 flex flex-col gap-2" role="radiogroup" aria-label="Opções de troca">
        {options.map((o, i) => {
          const marcada = o.foodId === escolhida;
          return (
            <button
              key={o.foodId}
              ref={(el) => {
                radios.current[i] = el;
              }}
              type="button"
              role="radio"
              aria-checked={marcada}
              tabIndex={marcada ? 0 : -1}
              onClick={() => setEscolhida(o.foodId)}
              onKeyDown={(e) => aoTeclar(e, i)}
              style={cascata(i, 55, 80)}
              className={`flex w-full animate-entra items-start gap-3 rounded-2xl border bg-white px-[15px] py-[13px] text-left transition-[border-color,box-shadow,transform] duration-250 active:scale-[0.99] ${
                marcada ? "border-tinta shadow-[inset_0_0_0_1px_var(--color-tinta)]" : "border-linha hover:-translate-y-px hover:border-pedra"
              }`}
            >
              <span
                className={`mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border-[1.5px] transition-[background-color,border-color,transform] duration-250 ease-[cubic-bezier(.34,1.56,.64,1)] ${
                  marcada ? "scale-110 border-tinta bg-tinta" : "border-[#c3ccc0]"
                }`}
              >
                <span
                  className={`block size-2 rounded-full bg-gema transition-transform duration-250 ease-[cubic-bezier(.34,1.56,.64,1)] ${
                    marcada ? "scale-100" : "scale-0"
                  }`}
                />
              </span>
              <span className="flex-1">
                <span className="flex items-baseline justify-between gap-2.5">
                  <span className="text-[15px] font-semibold tracking-[-0.01em]">{o.name}</span>
                  <span className={`shrink-0 text-[12.5px] font-semibold ${o.calorieDelta <= 0 ? "text-mata" : "text-fumo"}`}>
                    {delta(o.calorieDelta)}
                  </span>
                </span>
                <span className="mt-0.5 block text-[13px] text-fumo">{o.amount}</span>
                <span className="mt-1 block text-xs text-fumo">
                  {gramas(o.macros.carbs)} de carboidrato.{o.note ? ` ${o.note}` : ""}
                </span>
              </span>
            </button>
          );
        })}
      </div>

      {guarantee.restrictions.length > 0 ? (
        <div
          className="mt-3.5 flex animate-entra items-start gap-2.5 rounded-[14px] bg-mata-fraca px-3.5 py-3"
          style={{ animationDelay: "320ms" }}
        >
          <IconeCheck size={17} strokeWidth={1.9} className="mt-0.5 shrink-0 text-mata" />
          <span className="text-[12.5px] leading-snug text-mata-texto">
            Nenhuma dessas opções tem {juntarComE(guarantee.restrictions.map((r) => r.toLocaleLowerCase("pt-BR")))}.
          </span>
        </div>
      ) : null}

      <div className="mt-4 flex animate-entra flex-col gap-2" style={{ animationDelay: "380ms" }}>
        <Button carregando={trocando} onClick={() => aoTrocar(opcao.foodId)}>
          Usar {opcao.name.toLowerCase()}
        </Button>
        <button type="button" onClick={aoFechar} className="flex h-12 items-center justify-center text-[14.5px] font-semibold text-fumo">
          Cancelar
        </button>
      </div>
    </>
  );
}
```

- [ ] **Step 3: Rodar, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project storybook src/features/dia && npm run lint && npm run typecheck"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(dia): folha de troca com radiogroup por setas e garantia de todas as restrições

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Gerando e Pronto ligados à API; Resumo leva o id do plano

**Files (repo front):**
- Create: `src/features/dia/components/GerandoTela.tsx`, `src/features/dia/components/ProntoTela.tsx`, `src/features/dia/components/GerandoTela.integration.test.tsx`, `src/features/dia/components/ProntoTela.integration.test.tsx`
- Modify: `src/app/onboarding/gerando/page.tsx`, `src/app/onboarding/pronto/page.tsx`, `src/features/onboarding/components/EtapaResumo.tsx`, `src/features/onboarding/components/EtapaResumo.integration.test.tsx`, `src/lib/api/onboarding.ts`, `eslint.config.mjs`
- Modify: `src/mocks/handlers/dia.ts` (handler `GET /plans/:id` pronto)

**Interfaces:**
- Consumes: `usePlano`, `usePedirPlano` (Task 2), `useToast`, `useMe`, `useDadosOnboarding`.
- Produces:
  - `/onboarding/gerando?plano={id}[&voltar={rota}]` — `ready` → `router.replace('/onboarding/pronto?plano={id}')`, ou para `voltar` com o aviso "Seu plano novo está pronto."; sem `plano` → `router.replace('/hoje')`; `voltar` só aceita caminho interno (`/…`, não `//…`).
  - `/onboarding/pronto?plano={id}` — lê `GET /plans/{id}`; plano não `ready` → volta a Gerando.
  - Resumo → `recarregarEm('/onboarding/gerando?plano={id}')`.
  - `destinoSeguro(voltar: string | null): string | null` exportada de `GerandoTela.tsx`.

- [ ] **Step 1: Testes que devem falhar**

`src/features/dia/components/GerandoTela.integration.test.tsx`:
```tsx
import { act, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { planoProntoApi } from '@/mocks/fixtures/dia';
import { url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { definirUrl, redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { destinoSeguro, GerandoTela } from './GerandoTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

/** Respostas de GET /plans/42, em ordem; a última se repete. */
function statusDoPlano(...status: ('pending' | 'generating' | 'ready' | 'failed')[]) {
  let i = 0;
  server.use(
    http.get(url('/plans/42'), () => {
      const s = status[Math.min(i++, status.length - 1)];
      return HttpResponse.json({ data: s === 'ready' ? planoProntoApi(42) : { id: 42, status: s, is_active: false, ...(s === 'failed' ? { failure_reason: 'AI_UNAVAILABLE' } : {}) } });
    }),
  );
}

beforeEach(() => {
  redefinirNavegacao();
  vi.useFakeTimers({ shouldAdvanceTime: true });
});
afterEach(() => vi.useRealTimers());

describe('Gerando (S09)', () => {
  it('consulta a cada 1,5 s até ficar pronto e vai para o Pronto', async () => {
    definirUrl('/onboarding/gerando?plano=42');
    statusDoPlano('pending', 'generating', 'ready');

    renderizar(<GerandoTela />);
    expect(await screen.findByRole('heading', { name: 'Montando seu plano' })).toBeInTheDocument();
    expect(screen.getByText('Costuma levar uns 10 segundos.')).toBeInTheDocument();

    await act(() => vi.advanceTimersByTimeAsync(3500));

    await vi.waitFor(() => expect(roteador.replace).toHaveBeenCalledWith('/onboarding/pronto?plano=42'));
  });

  it('com voltar, volta para a tela de origem avisando', async () => {
    definirUrl('/onboarding/gerando?plano=42&voltar=%2Fperfil');
    statusDoPlano('ready');

    renderizar(<GerandoTela />);
    await act(() => vi.advanceTimersByTimeAsync(1000));

    await vi.waitFor(() => expect(roteador.replace).toHaveBeenCalledWith('/perfil'));
    expect(await screen.findByText('Seu plano novo está pronto.')).toBeInTheDocument();
  });

  it('falhou: "Tentar de novo" pede outro plano e troca o id na URL (E2E-07)', async () => {
    definirUrl('/onboarding/gerando?plano=42&voltar=%2Fperfil');
    statusDoPlano('failed');
    server.use(http.post(url('/plans'), () => HttpResponse.json({ data: { id: 43, status: 'pending' } }, { status: 202 })));

    renderizar(<GerandoTela />);
    expect(await screen.findByRole('heading', { name: 'Não deu para montar agora' })).toBeInTheDocument();
    expect(screen.getByText('Seus dados estão salvos. Foi a conexão com o Nutri que falhou no meio do caminho.')).toBeInTheDocument();
    await userEvent.setup({ advanceTimers: vi.advanceTimersByTime }).click(screen.getByRole('button', { name: 'Tentar de novo' }));

    await vi.waitFor(() => expect(roteador.replace).toHaveBeenCalledWith('/onboarding/gerando?plano=43&voltar=%2Fperfil'));
  });

  it('sem plano na URL, vai para o Hoje', async () => {
    definirUrl('/onboarding/gerando');
    renderizar(<GerandoTela />);
    await vi.waitFor(() => expect(roteador.replace).toHaveBeenCalledWith('/hoje'));
  });

  it.each([
    ['/perfil', '/perfil'],
    ['//evil.example', null],
    ['https://evil.example', null],
    [null, null],
  ])('voltar %s → %s', (voltar, esperado) => {
    expect(destinoSeguro(voltar)).toBe(esperado);
  });
});
```

`src/features/dia/components/ProntoTela.integration.test.tsx`:
```tsx
import { screen } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { planoProntoApi } from '@/mocks/fixtures/dia';
import { url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { definirUrl, redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { ProntoTela } from './ProntoTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => redefinirNavegacao());

describe('Pronto (S10)', () => {
  it('mostra o dia comum vindo do plano', async () => {
    definirUrl('/onboarding/pronto?plano=42');
    server.use(http.get(url('/plans/42'), () => HttpResponse.json({ data: planoProntoApi(42) })));

    renderizar(<ProntoTela />);

    expect(await screen.findByText('Um dia comum')).toBeInTheDocument();
    expect(screen.getByText('Café da manhã')).toBeInTheDocument();
    expect(screen.getByText('Jantar')).toBeInTheDocument();
    expect(screen.getByText('2.250 kcal')).toBeInTheDocument();
    expect(screen.getByText('115 g')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Ver o dia de hoje' })).toHaveAttribute('href', '/hoje');
  });

  it('plano que ainda não está pronto volta para o Gerando', async () => {
    definirUrl('/onboarding/pronto?plano=42');
    server.use(http.get(url('/plans/42'), () => HttpResponse.json({ data: { id: 42, status: 'generating', is_active: false } })));

    renderizar(<ProntoTela />);

    await vi.waitFor(() => expect(roteador.replace).toHaveBeenCalledWith('/onboarding/gerando?plano=42'));
  });
});
```

Em `src/features/onboarding/components/EtapaResumo.integration.test.tsx`, no teste "conclui e recarrega na tela Gerando", trocar a resposta e a expectativa:
```tsx
        return HttpResponse.json({ data: { plan: { id: 42, status: 'pending' } } }, { status: 202 });
```
```tsx
    await waitFor(() => expect(recarregarEm).toHaveBeenCalledWith('/onboarding/gerando?plano=42'));
```

Run: `docker compose run --rm web npx vitest run --project integration src/features/dia src/features/onboarding/components/EtapaResumo.integration.test.tsx`
Expected: FAIL — `GerandoTela`/`ProntoTela` não existem; o Resumo ainda recarrega em `/onboarding/gerando`.

- [ ] **Step 2: Implementar**

`src/lib/api/onboarding.ts` — o comentário e o tipo da conclusão:
```ts
/** POST /onboarding/complete — 202 com o plano que começou a ser gerado (200 e o mesmo plano se já concluído). */
export const concluirOnboarding = () =>
  api<Dados<{ plan: { id: number; status: string } }>>('/onboarding/complete', { method: 'POST' }).then((r) => r.data);
```

`src/features/onboarding/components/EtapaResumo.tsx` — em `gerar()`, guardar o plano e usá-lo na recarga:
```tsx
  async function gerar() {
    setErro(null);
    let plano: number;
    try {
      plano = (await concluir.mutateAsync()).plan.id;
    } catch (e) {
      const falha = comoApiError(e);
      const etapa = falha.details.step;
      if (falha.code === "VALIDATION_ERROR" && typeof etapa === "string") {
        avisar({ texto: "Falta completar esta etapa." });
        router.push(`/onboarding/${etapa}?de=resumo`);
        return;
      }
      setErro(falha);
      return;
    }
    // Recarga: o `['me']` em cache ainda diz "onboarding incompleto" e o guarda voltaria para cá.
    recarregarEm(`/onboarding/gerando?plano=${plano}`);
  }
```

`src/features/dia/components/GerandoTela.tsx`:
```tsx
"use client";

import { useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { IconeCheck, MarcaNutri } from "@/components/icons";
import { Button } from "@/components/ui/Button";
import { useToast } from "@/components/ui/Toaster";
import { usePedirPlano, usePlano } from "../hooks";

const PASSOS = [
  "Lendo seu perfil",
  "Calculando calorias e proteína",
  "Escolhendo alimentos da sua lista",
  "Encaixando nos seus horários",
  "Conferindo suas restrições",
];

/** Só caminhos do próprio app: `/perfil` sim, `//site` e `https://…` não. */
export function destinoSeguro(voltar: string | null): string | null {
  return voltar && voltar.startsWith("/") && !voltar.startsWith("//") ? voltar : null;
}

/** S09 — acompanha a geração (polling de 1,5 s em `usePlano`). */
export function GerandoTela() {
  const busca = useSearchParams();
  const router = useRouter();
  const id = Number(busca.get("plano")) || null;
  const voltar = destinoSeguro(busca.get("voltar"));

  useEffect(() => {
    if (id === null) router.replace("/hoje");
  }, [id, router]);

  if (id === null) return null;
  return <Acompanhamento key={id} id={id} voltar={voltar} />;
}

function Acompanhamento({ id, voltar }: { id: number; voltar: string | null }) {
  const router = useRouter();
  const avisar = useToast();
  const plano = usePlano(id);
  const pedir = usePedirPlano();
  const [passo, setPasso] = useState(0);
  const status = plano.data?.status;
  const falhou = status === "failed" || plano.isError;

  // Os passos andam por tempo até o último, que só conclui com o plano pronto.
  useEffect(() => {
    const relogio = setInterval(() => setPasso((p) => Math.min(p + 1, PASSOS.length - 1)), 520);
    return () => clearInterval(relogio);
  }, []);

  useEffect(() => {
    if (status !== "ready") return;
    const vai = setTimeout(() => {
      if (voltar) {
        avisar({ texto: "Seu plano novo está pronto." });
        router.replace(voltar);
      } else {
        router.replace(`/onboarding/pronto?plano=${id}`);
      }
    }, 350);
    return () => clearTimeout(vai);
  }, [status, voltar, id, router, avisar]);

  const concluido = status === "ready" ? PASSOS.length : passo;

  async function tentarDeNovo() {
    const novo = await pedir.mutateAsync();
    router.replace(`/onboarding/gerando?plano=${novo}${voltar ? `&voltar=${encodeURIComponent(voltar)}` : ""}`);
  }

  return (
    <div className="mx-auto flex min-h-dvh w-full max-w-[430px] flex-col justify-between bg-tinta px-8 pt-13 pb-10 text-neve area-segura-cima area-segura-baixo">
      <div className="flex items-center gap-2.5">
        <MarcaNutri size={18} />
        <span className="font-display text-[17px] font-bold tracking-[-0.015em]">Prato Forte</span>
      </div>

      {falhou ? (
        <div>
          <h1 className="animate-balanca font-display text-[32px] leading-tight font-bold tracking-[-0.03em]">Não deu para montar agora</h1>
          <p className="mt-3 max-w-[280px] text-[14.5px] leading-normal text-musgo">
            Seus dados estão salvos. Foi a conexão com o Nutri que falhou no meio do caminho.
          </p>
          <Button variante="contorno-escuro" className="mt-6" carregando={pedir.isPending} onClick={() => void tentarDeNovo()}>
            Tentar de novo
          </Button>
        </div>
      ) : (
        <div>
          <div className="relative mb-8 size-14">
            <span className="absolute inset-0 rounded-full border-[1.5px] border-gema motion-safe:animate-[pulso_2.6s_cubic-bezier(.2,.6,.3,1)_infinite]" />
            <span className="absolute inset-0 rounded-full border-[1.5px] border-gema [animation-delay:1.3s] motion-safe:animate-[pulso_2.6s_cubic-bezier(.2,.6,.3,1)_infinite]" />
            <span className="absolute inset-3.5 rounded-full bg-gema" />
          </div>

          <h1 className="animate-entra font-display text-[32px] leading-tight font-bold tracking-[-0.03em]">Montando seu plano</h1>
          <p className="mt-3 max-w-[280px] text-[14.5px] leading-normal text-musgo">
            Estamos cruzando seus dados com os alimentos que você marcou.
          </p>

          <ul className="mt-7 flex list-none flex-col gap-4" aria-live="polite">
            {PASSOS.map((texto, i) => {
              const feito = i < concluido;
              const atual = i === concluido;
              return (
                <li key={texto} className="flex animate-entra-lado-esq items-center gap-3" style={{ animationDelay: `${160 + i * 90}ms` }}>
                  {feito ? (
                    <span className="flex size-5 shrink-0 animate-pop items-center justify-center rounded-full bg-mata text-white">
                      <IconeCheck size={11} strokeWidth={2.4} />
                    </span>
                  ) : atual ? (
                    <span className="relative flex size-5 shrink-0 items-center justify-center rounded-full border-[1.5px] border-gema">
                      <span className="absolute inset-0 animate-halo rounded-full bg-gema/50" />
                      <span className="size-[7px] animate-respira rounded-full bg-gema" />
                    </span>
                  ) : (
                    <span className="size-5 shrink-0 rounded-full border-[1.5px] border-grafite" />
                  )}
                  <span
                    className={`text-[14.5px] transition-colors duration-400 ${
                      feito ? "text-salvia" : atual ? "font-semibold text-neve" : "text-cinza-treino"
                    }`}
                  >
                    {texto}
                  </span>
                </li>
              );
            })}
          </ul>
        </div>
      )}

      <div>
        <div className="relative h-1 overflow-hidden rounded-full bg-breu">
          <span
            className="block h-full rounded-full bg-gema transition-[width] duration-700 ease-[cubic-bezier(.22,1,.36,1)]"
            style={{ width: `${Math.min(100, (concluido / PASSOS.length) * 100)}%` }}
          />
          <span className="brilho absolute inset-0 block opacity-40" />
        </div>
        <p className="mt-3 text-[12.5px] text-[#8d998f]">Costuma levar uns 10 segundos.</p>
      </div>

      <style>{`@keyframes pulso { 0% { transform: scale(1); opacity: .5 } 80%, 100% { transform: scale(2.6); opacity: 0 } }`}</style>
    </div>
  );
}
```

`src/app/onboarding/gerando/page.tsx` (substituir inteiro):
```tsx
import { GerandoTela } from "@/features/dia/components/GerandoTela";

export default function Gerando() {
  return <GerandoTela />;
}
```

`src/features/dia/components/ProntoTela.tsx`:
```tsx
"use client";

import { useEffect } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { ErrorState } from "@/components/app/ErrorState";
import { Screen } from "@/components/app/Screen";
import { IconeCheck } from "@/components/icons";
import { ButtonLink } from "@/components/ui/Button";
import { Skeleton } from "@/components/ui/Skeleton";
import { useMe } from "@/features/auth/hooks";
import { useDadosOnboarding } from "@/features/onboarding/hooks";
import { kcal } from "@/lib/format";
import { cascata } from "@/lib/motion";
import { usePlano } from "../hooks";

/** S10 — o plano que acabou de ficar pronto, como "um dia comum". */
export function ProntoTela() {
  const busca = useSearchParams();
  const router = useRouter();
  const id = Number(busca.get("plano")) || null;
  const plano = usePlano(id);
  const nome = useMe().data?.preferredName ?? "";
  const treino = useDadosOnboarding().data?.answers.trainingTime ?? "…";
  const status = plano.data?.status;

  useEffect(() => {
    if (id === null) router.replace("/hoje");
    else if (status && status !== "ready") router.replace(`/onboarding/gerando?plano=${id}`);
  }, [id, status, router]);

  if (plano.isError) {
    return (
      <Screen>
        <main className="flex-1 px-6 pt-16">
          <ErrorState
            titulo="Não foi possível abrir seu plano"
            descricao="Ele está salvo. Foi a conexão que falhou agora."
            aoTentarDeNovo={() => void plano.refetch()}
          />
        </main>
      </Screen>
    );
  }

  if (status !== "ready" || !plano.data?.meals || !plano.data.targets) {
    return (
      <Screen>
        <main className="flex-1 px-6 pt-16">
          <Skeleton className="size-11 rounded-full" />
          <Skeleton className="mt-6 h-20" />
          <Skeleton className="mt-6 h-[320px] rounded-[20px]" />
        </main>
      </Screen>
    );
  }

  const { meals, targets } = plano.data;

  return (
    <Screen>
      <main className="flex-1 px-6 pt-16 area-segura-cima">
        <span className="relative flex size-11 items-center justify-center rounded-full bg-mata text-white">
          <span className="absolute inset-0 animate-halo rounded-full bg-mata" />
          <span className="relative flex animate-pop items-center justify-center">
            <IconeCheck size={22} strokeWidth={2.1} />
          </span>
        </span>

        <h1
          className="mt-[22px] animate-entra font-display text-[34px] leading-[1.05] font-bold tracking-[-0.03em]"
          style={{ animationDelay: "160ms" }}
        >
          Seu plano está pronto{nome ? `, ${nome}` : ""}
        </h1>
        <p className="mt-3 animate-entra text-[15px] leading-relaxed text-fumo" style={{ animationDelay: "260ms" }}>
          Cinco refeições montadas com o que você marcou, encaixadas entre o trabalho e o treino das {treino}.
        </p>

        <section className="mt-[22px] animate-escala rounded-[20px] bg-white px-[18px] pt-1.5 pb-3.5" style={{ animationDelay: "340ms" }}>
          <div className="flex items-baseline justify-between py-3.5">
            <h2 className="font-display text-[15px] font-semibold">Um dia comum</h2>
            <span className="text-[12.5px] text-fumo">{kcal(targets.kcal)}</span>
          </div>
          {meals.map((meal, i) => (
            <div
              key={meal.slot}
              style={cascata(i, 70, 460)}
              className={`flex min-h-[52px] animate-entra-lado-esq items-center gap-3 ${i < meals.length - 1 ? "border-b border-fio" : ""}`}
            >
              <span className="w-[46px] text-[12.5px] text-fumo">{meal.time}</span>
              <span className="flex-1 text-[14.5px] font-medium">{meal.name}</span>
              <span className="text-[12.5px] text-fumo">{kcal(meal.calories)}</span>
            </div>
          ))}
          <div className="mt-1 flex animate-entra gap-[18px] border-t border-tinta pt-3.5" style={{ animationDelay: "840ms" }}>
            <span className="text-[13px] font-semibold">
              {targets.proteinG} g <span className="font-medium text-fumo">proteína</span>
            </span>
            <span className="text-[13px] font-semibold">
              {targets.carbsG} g <span className="font-medium text-fumo">carboidrato</span>
            </span>
            <span className="text-[13px] font-semibold">
              {targets.fatG} g <span className="font-medium text-fumo">gordura</span>
            </span>
          </div>
        </section>

        <p className="mt-4 animate-entra text-[13.5px] leading-normal text-fumo" style={{ animationDelay: "920ms" }}>
          Faltou algum alimento ou o horário não bate? O Nutri ajusta qualquer refeição em segundos.
        </p>
      </main>

      <footer className="flex shrink-0 animate-entra flex-col gap-3 px-6 pt-3.5 pb-8 area-segura-baixo" style={{ animationDelay: "1000ms" }}>
        <ButtonLink href="/hoje">Ver o dia de hoje</ButtonLink>
        <ButtonLink href="/nutri" variante="texto" className="h-11">
          Ajustar alguma coisa com o Nutri
        </ButtonLink>
      </footer>
    </Screen>
  );
}
```

O teste do Pronto procura "115 g" como texto exato; o `<span>` tem "115 g " e o filho "proteína" — ajuste o seletor do teste para `screen.getByText((_, el) => el?.textContent === '115 g proteína')` se o `getByText('115 g')` não casar (registre a ruling).

`src/app/onboarding/pronto/page.tsx` (substituir inteiro):
```tsx
import { ProntoTela } from "@/features/dia/components/ProntoTela";

export default function Pronto() {
  return <ProntoTela />;
}
```

`eslint.config.mjs` — tirar `'src/app/onboarding/pronto/page.tsx'` do primeiro bloco LEGADO e `'src/app/onboarding/gerando/page.tsx'` do segundo.

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm web npx vitest run --project integration src/features/dia src/features/onboarding`
Expected: PASS.

- [ ] **Step 4: Suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(plano): Gerando acompanha GET /plans/{id} e Pronto mostra o plano real

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Hoje ligado à API

**Files (repo front):**
- Create: `src/features/dia/components/HojeTela.tsx`, `src/features/dia/components/HojeTela.integration.test.tsx`
- Modify: `src/app/(app)/hoje/page.tsx`
- Delete: `src/components/app/DayRail.tsx`

**Interfaces:**
- Consumes: `useDia`, `useMarcarRefeicao`, `usePedirPlano`, `planoSemAtivo` (Task 2); `DayRail`, `NoPlanState`, `AvisoDeAlteracao` (Task 3); `usePerfil`, `iniciais` (Plano 03).
- Produces: `HojeTela` — S11 completa; `NutriBar` com `href="/nutri?pergunta=…"`.

- [ ] **Step 1: Teste de integração que deve falhar**

`src/features/dia/components/HojeTela.integration.test.tsx`:
```tsx
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { diaApi } from '@/mocks/fixtures/dia';
import { erroDaApi, url } from '@/mocks/handlers/auth';
import { respondendoDia } from '@/mocks/handlers/dia';
import { server } from '@/mocks/server';
import { redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { HojeTela } from './HojeTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => redefinirNavegacao());

describe('Hoje (S11)', () => {
  it('mostra o dia da API: linha do dia, metas e atalho do Nutri', async () => {
    server.use(respondendoDia(diaApi({ feitas: ['cafe'] })));

    renderizar(<HojeTela />);

    expect(await screen.findByText('1 de 5 refeições')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /, Camila$/ })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Metas de hoje' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Perguntar ao Nutri sobre o lanche da manhã/ })).toHaveAttribute(
      'href',
      `/nutri?pergunta=${encodeURIComponent('Tenho uma dúvida sobre o lanche da manhã de hoje.')}`,
    );
  });

  it('marca a refeição na hora e envia ao servidor (RF13)', async () => {
    let corpo: unknown;
    server.use(
      http.patch(url('/days/today/meals/cafe'), async ({ request }) => {
        corpo = await request.json();
        return HttpResponse.json({ data: diaApi({ feitas: ['cafe'] }) });
      }),
    );

    renderizar(<HojeTela />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Marcar café da manhã como feita' }));

    expect(await screen.findByText('1 de 5 refeições')).toBeInTheDocument();
    expect(corpo).toEqual({ done: true });
  });

  it('se salvar falhar, volta como estava e avisa', async () => {
    server.use(http.patch(url('/days/today/meals/cafe'), () => erroDaApi(500, 'SERVER_ERROR', 'x')));

    renderizar(<HojeTela />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Marcar café da manhã como feita' }));

    expect(await screen.findByText('Não foi possível salvar. Tente de novo.')).toBeInTheDocument();
    expect(screen.getByText('0 de 5 refeições')).toBeInTheDocument();
  });

  it('última alteração vira toast com "Desfazer"', async () => {
    let desfez = false;
    server.use(
      respondendoDia(diaApi({ ultimaAlteracao: { id: 7, text: 'Arroz branco cozido trocado por batata-doce cozida' } })),
      http.post(url('/days/today/undo'), () => {
        desfez = true;
        return HttpResponse.json({ data: diaApi() });
      }),
    );

    renderizar(<HojeTela />);
    const aviso = await screen.findByText('Arroz branco cozido trocado por batata-doce cozida');
    await userEvent.setup().click(within(aviso.closest('[role="status"]') as HTMLElement).getByRole('button', { name: 'Desfazer' }));

    await vi.waitFor(() => expect(desfez).toBe(true));
  });

  it.each([
    ['generating', 'Seu plano está quase pronto'],
    ['failed', 'Não conseguimos montar seu plano'],
  ])('sem plano ativo (%s) mostra o estado certo', async (status, titulo) => {
    server.use(
      http.get(url('/days/today'), () =>
        HttpResponse.json({ message: 'x', code: 'NO_ACTIVE_PLAN', details: { plan_status: status, plan_id: 43 } }, { status: 409 }),
      ),
    );

    renderizar(<HojeTela />);

    expect(await screen.findByRole('heading', { name: titulo })).toBeInTheDocument();
  });

  it('falhou: "Tentar de novo" pede um plano e abre o Gerando', async () => {
    server.use(
      http.get(url('/days/today'), () =>
        HttpResponse.json({ message: 'x', code: 'NO_ACTIVE_PLAN', details: { plan_status: 'failed', plan_id: 43 } }, { status: 409 }),
      ),
      http.post(url('/plans'), () => HttpResponse.json({ data: { id: 44, status: 'pending' } }, { status: 202 })),
    );

    renderizar(<HojeTela />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Tentar de novo' }));

    await vi.waitFor(() => expect(roteador.push).toHaveBeenCalledWith('/onboarding/gerando?plano=44&voltar=%2Fhoje'));
  });

  it('erro de rede mostra o ErrorState com "Tentar de novo"', async () => {
    server.use(http.get(url('/days/today'), () => erroDaApi(500, 'SERVER_ERROR', 'x')));

    renderizar(<HojeTela />);

    expect(await screen.findByText('Não foi possível carregar seu dia')).toBeInTheDocument();
    server.use(respondendoDia(diaApi()));
    await userEvent.setup().click(screen.getByRole('button', { name: 'Tentar de novo' }));
    expect(await screen.findByText('0 de 5 refeições')).toBeInTheDocument();
  });
});
```

Run: `docker compose run --rm web npx vitest run --project integration src/features/dia/components/HojeTela.integration.test.tsx`
Expected: FAIL — `HojeTela` não existe.

- [ ] **Step 2: Implementar**

`src/features/dia/components/HojeTela.tsx`:
```tsx
"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { BottomNav } from "@/components/app/BottomNav";
import { ErrorState } from "@/components/app/ErrorState";
import { NutriBar } from "@/components/app/NutriBar";
import { Screen } from "@/components/app/Screen";
import { CountUp } from "@/components/ui/CountUp";
import { Rail, ReguaPeso } from "@/components/ui/Rail";
import { EsqueletoDoDia } from "@/components/ui/Skeleton";
import { iniciais } from "@/features/perfil/formato";
import { usePerfil } from "@/features/perfil/hooks";
import { dataPorExtenso, peso, saudacao } from "@/lib/format";
import { useDia, useMarcarRefeicao, usePedirPlano } from "../hooks";
import { planoSemAtivo } from "../regras";
import { AvisoDeAlteracao } from "./AvisoDeAlteracao";
import { DayRail } from "./DayRail";
import { NoPlanState } from "./NoPlanState";

/** S11 — o dia de hoje. */
export function HojeTela() {
  const router = useRouter();
  const dia = useDia();
  const perfil = usePerfil();
  const marcar = useMarcarRefeicao();
  const pedir = usePedirPlano();
  const semPlano = planoSemAtivo(dia.error);

  async function tentarDeNovo() {
    const id = await pedir.mutateAsync();
    router.push(`/onboarding/gerando?plano=${id}&voltar=${encodeURIComponent("/hoje")}`);
  }

  if (!dia.data || !perfil.data) {
    return (
      <Screen>
        <header className="px-5 pt-5 pb-3 area-segura-cima">
          <div className="h-[68px]" />
        </header>
        <main className="flex-1 px-5">
          {semPlano ? (
            <NoPlanState
              estado={semPlano.status === "failed" || semPlano.status === null ? "falhou" : "gerando"}
              planId={semPlano.planId}
              tentando={pedir.isPending}
              aoTentarDeNovo={() => void tentarDeNovo()}
            />
          ) : dia.isError || perfil.isError ? (
            <ErrorState
              titulo="Não foi possível carregar seu dia"
              descricao="Seu plano está salvo. Só a conexão falhou agora."
              aoTentarDeNovo={() => {
                void dia.refetch();
                void perfil.refetch();
              }}
            />
          ) : (
            <EsqueletoDoDia />
          )}
        </main>
        <BottomNav />
      </Screen>
    );
  }

  const { meals, totals, targets, date, lastChange } = dia.data;
  const eu = perfil.data;
  const proxima = meals.find((m) => m.isNext);
  const metas = targets ?? { proteinG: totals.planned.protein, carbsG: totals.planned.carbs, fatG: totals.planned.fat };

  return (
    <Screen>
      <header className="flex shrink-0 items-center justify-between px-5 pt-5 pb-3 area-segura-cima">
        <div>
          <p className="animate-entra text-[12.5px] text-fumo">{dataPorExtenso(date)}</p>
          <h1 className="mt-0.5 animate-entra font-display text-[26px] font-bold tracking-[-0.025em]" style={{ animationDelay: "70ms" }}>
            {saudacao()}, {eu.preferredName}
          </h1>
        </div>
        <Link
          href="/perfil"
          aria-label="Abrir seu perfil"
          className="flex size-[42px] shrink-0 animate-pop items-center justify-center rounded-full bg-tinta text-sm font-semibold text-neve transition-transform duration-300 hover:scale-105"
        >
          {iniciais(eu.name)}
        </Link>
      </header>

      <AvisoDeAlteracao alteracao={lastChange} />

      <main className="flex-1 px-5 pt-3">
        <DayRail refeicoes={meals} aoAlternar={(slot, done) => marcar.mutate({ slot, done })} />

        <section className="mt-4 animate-entra" style={{ animationDelay: "420ms" }}>
          <div className="mb-2.5 flex items-baseline justify-between">
            <h2 className="font-display text-[15px] font-semibold">Metas de hoje</h2>
            <p className="text-[12.5px] text-fumo">
              <CountUp valor={Math.round(totals.consumed.calories)} duracao={1100} /> de{" "}
              {Math.round(totals.planned.calories).toLocaleString("pt-BR")} kcal
            </p>
          </div>

          <Rail rotulo="Proteína" valor={totals.consumed.protein} meta={metas.proteinG} atraso={480} />
          <Rail rotulo="Carboidrato" valor={totals.consumed.carbs} meta={metas.carbsG} cor="bg-gema" atraso={560} />
          <Rail rotulo="Gordura" valor={totals.consumed.fat} meta={metas.fatG} cor="bg-mata" atraso={640} />

          {eu.goalWeightKg !== null ? (
            <Link
              href="/evolucao"
              className="mt-3 flex h-10 items-center gap-3 border-t border-linha pt-3 transition-colors hover:text-mata"
            >
              <span className="w-[74px] shrink-0 text-[12.5px] text-fumo">Peso</span>
              <ReguaPeso inicio={eu.startWeightKg} atual={eu.currentWeightKg} meta={eu.goalWeightKg} />
              <span className="w-[108px] shrink-0 text-right text-[12.5px] font-semibold tabular-nums">
                {peso(eu.currentWeightKg)} de {peso(eu.goalWeightKg)}
              </span>
            </Link>
          ) : null}
        </section>
      </main>

      <NutriBar
        className="mx-5 mt-4 mb-2.5 animate-entra"
        style={{ animationDelay: "760ms" }}
        href={
          proxima
            ? `/nutri?pergunta=${encodeURIComponent(`Tenho uma dúvida sobre o ${proxima.name.toLowerCase()} de hoje.`)}`
            : "/nutri"
        }
        texto={proxima ? `Perguntar ao Nutri sobre o ${proxima.name.toLowerCase()}` : "Perguntar alguma coisa ao Nutri"}
      />

      <BottomNav />
    </Screen>
  );
}
```

`src/app/(app)/hoje/page.tsx` (substituir inteiro):
```tsx
import { HojeTela } from "@/features/dia/components/HojeTela";

export default function Hoje() {
  return <HojeTela />;
}
```

```bash
git rm src/components/app/DayRail.tsx
```

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project integration src/features/dia && npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(dia): Hoje lê o dia da API, marca refeição otimista e trata dia sem plano

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Dieta — semana, prévia e histórico

**Files (repo front):**
- Create: `src/features/dia/components/DietaTela.tsx`, `src/features/dia/components/DietaTela.integration.test.tsx`
- Modify: `src/app/(app)/dieta/page.tsx`

**Interfaces:**
- Consumes: `useDia`, `semanaDe`, `planoSemAtivo` (Task 2); `WeekDayPicker`, `MealRow`, `NoPlanState` (Task 3).
- Produces: `DietaTela` — S12. Busca `GET /days/today` para saber a data de hoje e `GET /days/{data}` para os outros dias da semana.

- [ ] **Step 1: Teste que deve falhar**

`src/features/dia/components/DietaTela.integration.test.tsx`:
```tsx
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { diaApi } from '@/mocks/fixtures/dia';
import { respondendoDia } from '@/mocks/handlers/dia';
import { server } from '@/mocks/server';
import { redefinirNavegacao } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { DietaTela } from './DietaTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => redefinirNavegacao());

describe('Dieta (S12)', () => {
  it('abre em hoje, com a semana de segunda a domingo e refeições clicáveis', async () => {
    server.use(respondendoDia(diaApi({ data: '2026-09-30' })));

    renderizar(<DietaTela />);

    expect(await screen.findByRole('tab', { name: /qua 30/ })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getAllByRole('tab')).toHaveLength(7);
    expect(screen.getByRole('tab', { name: /seg 28/ })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /dom 4/ })).toBeInTheDocument();
    expect(screen.getByText('Quarta-feira, dia de treino')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Almoço/ })).toHaveAttribute('href', '/dieta/almoco');
    expect(screen.getByText('Toda refeição de hoje pode ser trocada. O Nutri ajuda quando o dia sair do plano.')).toBeInTheDocument();
  });

  it('outro dia mostra a prévia sem links (RN23)', async () => {
    server.use(
      respondendoDia(diaApi({ data: '2026-09-30' })),
      respondendoDia(diaApi({ data: '2026-10-01', hoje: false }), '2026-10-01'),
    );

    renderizar(<DietaTela />);
    await userEvent.setup().click(await screen.findByRole('tab', { name: /qui 1/ }));

    expect(await screen.findByText('Quinta-feira, dia de treino')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /Almoço/ })).toBeNull();
  });

  it('dia passado sem registro mostra "Nada registrado neste dia"', async () => {
    server.use(
      respondendoDia(diaApi({ data: '2026-09-30' })),
      respondendoDia({ ...diaApi({ data: '2026-09-28', hoje: false }), meals: [] }, '2026-09-28'),
    );

    renderizar(<DietaTela />);
    await userEvent.setup().click(await screen.findByRole('tab', { name: /seg 28/ }));

    expect(await screen.findByText('Nada registrado neste dia')).toBeInTheDocument();
  });
});
```

Run: `docker compose run --rm web npx vitest run --project integration src/features/dia/components/DietaTela.integration.test.tsx`
Expected: FAIL — `DietaTela` não existe.

- [ ] **Step 2: Implementar**

`src/features/dia/components/DietaTela.tsx`:
```tsx
"use client";

import { useState } from "react";
import Link from "next/link";
import { BottomNav } from "@/components/app/BottomNav";
import { ErrorState } from "@/components/app/ErrorState";
import { Screen } from "@/components/app/Screen";
import { MarcaNutri } from "@/components/icons";
import { Skeleton } from "@/components/ui/Skeleton";
import { kcal } from "@/lib/format";
import { useMontado } from "@/lib/motion";
import { useDia } from "../hooks";
import { planoSemAtivo, semanaDe } from "../regras";
import { MealRow } from "./MealRow";
import { NoPlanState } from "./NoPlanState";
import { WeekDayPicker } from "./WeekDayPicker";

const DIAS_LONGOS = ["Domingo", "Segunda-feira", "Terça-feira", "Quarta-feira", "Quinta-feira", "Sexta-feira", "Sábado"];

/** S12 — a semana: hoje editável, futuro em prévia, passado como foi (RN22, RN23). */
export function DietaTela() {
  const hoje = useDia();
  const [escolhido, setEscolhido] = useState<string | null>(null);
  const dataHoje = hoje.data?.date;
  const selecionado = escolhido ?? dataHoje ?? null;
  const outro = useDia(selecionado && selecionado !== dataHoje ? selecionado : "today");
  const dia = selecionado === dataHoje ? hoje : outro;

  return (
    <Screen>
      <header className="shrink-0 px-5 pt-5 pb-3.5 area-segura-cima">
        <div className="flex items-center justify-between">
          <h1 className="animate-entra font-display text-[26px] font-bold tracking-[-0.025em]">Sua dieta</h1>
          <Link
            href="/nutri"
            className="group flex h-[38px] animate-entra items-center gap-[7px] rounded-full border border-linha bg-white px-3.5 text-[13px] font-semibold transition-[border-color,box-shadow] duration-250 hover:border-pedra hover:shadow-[0_8px_20px_-16px_rgba(21,37,28,.9)]"
            style={{ animationDelay: "80ms" }}
          >
            <span className="transition-transform duration-500 ease-[cubic-bezier(.34,1.56,.64,1)] group-hover:scale-125">
              <MarcaNutri size={16} />
            </span>
            Nutri
          </Link>
        </div>

        {dataHoje && selecionado ? (
          <WeekDayPicker dias={semanaDe(dataHoje)} hoje={dataHoje} selecionado={selecionado} aoEscolher={setEscolhido} />
        ) : (
          <Skeleton className="mt-3.5 h-14" />
        )}
      </header>

      <main className="flex-1 px-5 pt-1">
        <Conteudo dia={dia} />
      </main>

      <BottomNav />
    </Screen>
  );
}

function Conteudo({ dia }: { dia: ReturnType<typeof useDia> }) {
  const montado = useMontado(160);
  const semPlano = planoSemAtivo(dia.error);

  if (semPlano) {
    return (
      <NoPlanState
        estado={semPlano.status === "generating" || semPlano.status === "pending" ? "gerando" : "falhou"}
        planId={semPlano.planId}
        aoTentarDeNovo={() => void dia.refetch()}
      />
    );
  }
  if (dia.isError) {
    return (
      <ErrorState
        titulo="Não foi possível carregar sua dieta"
        descricao="Seu plano está salvo. Foi a conexão que falhou."
        aoTentarDeNovo={() => void dia.refetch()}
      />
    );
  }
  if (!dia.data) {
    return (
      <>
        <Skeleton className="h-[110px] rounded-[18px]" />
        <Skeleton className="mt-2.5 h-[110px] rounded-[18px]" />
        <Skeleton className="mt-2.5 h-[110px] rounded-[18px]" />
      </>
    );
  }

  const { date, isToday, isTrainingDay, meals, totals } = dia.data;
  const semana = new Date(`${date}T12:00:00`).getDay();
  const feitasAte = isToday ? Math.max(0, meals.findIndex((m) => !m.done)) : 0;

  return (
    <>
      <div className="mb-3 flex items-baseline justify-between">
        <p className="text-[13px] text-fumo">
          {DIAS_LONGOS[semana]}
          {isTrainingDay ? ", dia de treino" : ", dia de descanso"}
        </p>
        {meals.length > 0 ? <p className="text-[13px] font-semibold">{kcal(totals.planned.calories)}</p> : null}
      </div>

      {meals.length === 0 ? (
        <p className="mt-8 animate-entra text-center text-[14.5px] text-fumo">Nada registrado neste dia</p>
      ) : (
        <ol className="relative list-none pl-6">
          <span className="absolute top-4 bottom-4 left-[5px] block w-0.5 rounded-full bg-linha" />
          {feitasAte > 0 ? (
            <span
              className="absolute top-4 left-[5px] block w-0.5 rounded-full bg-mata transition-[height] duration-[900ms] ease-[cubic-bezier(.22,1,.36,1)]"
              style={{ height: montado ? `${feitasAte * 118}px` : "0px" }}
            />
          ) : null}
          {meals.map((refeicao, i) => (
            <MealRow key={`${date}-${refeicao.slot}`} refeicao={refeicao} indice={i} clicavel={isToday} />
          ))}
        </ol>
      )}

      {isToday ? (
        <p className="mt-4 animate-entra text-[12.5px] leading-normal text-fumo" style={{ animationDelay: "620ms" }}>
          Toda refeição de hoje pode ser trocada. O Nutri ajuda quando o dia sair do plano.
        </p>
      ) : null}
    </>
  );
}
```

`src/app/(app)/dieta/page.tsx` (substituir inteiro):
```tsx
import { DietaTela } from "@/features/dia/components/DietaTela";

export default function Dieta() {
  return <DietaTela />;
}
```

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project integration src/features/dia && npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(dia): Dieta com a semana da API — prévia no futuro, histórico no passado

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Detalhe da refeição — trocar, desfazer, marcar

**Files (repo front):**
- Create: `src/features/dia/components/DetalheTela.tsx`, `src/features/dia/components/DetalheTela.integration.test.tsx`
- Modify: `src/app/(app)/dieta/[refeicao]/page.tsx`, `eslint.config.mjs`

**Interfaces:**
- Consumes: `useDia`, `useMarcarRefeicao`, `useSubstituicoes`, `useTrocarItem`, `planoSemAtivo` (Task 2); `FoodItemRow`, `SubstitutionSheet`, `AvisoDeAlteracao`, `NoPlanState` (Tasks 3–4).
- Produces: `DetalheTela({ slot })` — S13; `NutriBar` "Não tenho {item mais proteico} em casa" → `/nutri?pergunta=…`.

- [ ] **Step 1: Teste que deve falhar**

`src/features/dia/components/DetalheTela.integration.test.tsx`:
```tsx
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { diaApi, substituicoesApi } from '@/mocks/fixtures/dia';
import { erroDaApi, url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { redefinirNavegacao } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { DetalheTela } from './DetalheTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => redefinirNavegacao());

/** O dia depois de trocar o arroz do almoço pela batata-doce. */
function diaTrocado() {
  const dia = diaApi({ ultimaAlteracao: { id: 9, text: 'Arroz branco cozido trocado por batata-doce cozida' } });
  const almoco = dia.meals[2];
  almoco.items[0] = { ...almoco.items[0], food_id: 30, name: 'Batata-doce cozida', grams: 230, source: 'manual', replaced_from: 'Arroz branco cozido' };
  return dia;
}

describe('Detalhe da refeição (S13)', () => {
  it('troca pela folha, mostra o selo e o toast, e desfaz', async () => {
    let trocou: unknown;
    server.use(
      http.get(url('/days/today/items/5020/substitutions'), () => HttpResponse.json({ data: substituicoesApi })),
      http.post(url('/days/today/items/5020/swap'), async ({ request }) => {
        trocou = await request.json();
        return HttpResponse.json({ data: diaTrocado() });
      }),
      http.post(url('/days/today/undo'), () => HttpResponse.json({ data: diaApi() })),
    );
    const usuario = userEvent.setup();

    renderizar(<DetalheTela slot="almoco" />);
    await usuario.click(await screen.findByRole('button', { name: 'Trocar Arroz branco cozido' }));
    const folha = await screen.findByRole('dialog', { name: 'Trocar arroz branco cozido' });
    await usuario.click(await within(folha).findByRole('button', { name: 'Usar batata-doce cozida' }));

    expect(trocou).toEqual({ food_id: 30 });
    expect(await screen.findByText('Trocado')).toBeInTheDocument();
    expect(screen.getByText('No lugar de arroz branco cozido')).toBeInTheDocument();

    const aviso = screen.getByText('Arroz branco cozido trocado por batata-doce cozida').closest('[role="status"]') as HTMLElement;
    await usuario.click(within(aviso).getByRole('button', { name: 'Desfazer' }));

    expect(await screen.findByRole('button', { name: 'Trocar Arroz branco cozido' })).toBeInTheDocument();
    expect(screen.queryByText('Trocado')).toBeNull();
  });

  it('409 SUBSTITUTION_NOT_ALLOWED recarrega as opções', async () => {
    let buscas = 0;
    server.use(
      http.get(url('/days/today/items/5020/substitutions'), () => {
        buscas++;
        return HttpResponse.json({ data: substituicoesApi });
      }),
      http.post(url('/days/today/items/5020/swap'), () => erroDaApi(409, 'SUBSTITUTION_NOT_ALLOWED', 'Essa troca não está mais disponível.')),
    );
    const usuario = userEvent.setup();

    renderizar(<DetalheTela slot="almoco" />);
    await usuario.click(await screen.findByRole('button', { name: 'Trocar Arroz branco cozido' }));
    await usuario.click(await screen.findByRole('button', { name: 'Usar batata-doce cozida' }));

    await vi.waitFor(() => expect(buscas).toBe(2));
  });

  it('marca como feita e mostra "Desmarcar refeição"', async () => {
    server.use(http.patch(url('/days/today/meals/almoco'), () => HttpResponse.json({ data: diaApi({ feitas: ['almoco'] }) })));

    renderizar(<DetalheTela slot="almoco" />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Marcar como feita' }));

    expect(await screen.findByRole('button', { name: 'Desmarcar refeição' })).toBeInTheDocument();
    expect(screen.getByText('Refeição feita')).toBeInTheDocument();
  });

  it('Nutri pergunta pelo alimento mais proteico', async () => {
    renderizar(<DetalheTela slot="almoco" />);

    expect(await screen.findByRole('link', { name: /Não tenho frango grelhado em casa/ })).toHaveAttribute(
      'href',
      `/nutri?pergunta=${encodeURIComponent('Não tenho frango grelhado em casa. O que uso no lugar?')}`,
    );
  });

  it('slot que não existe mostra "Refeição não encontrada"', async () => {
    renderizar(<DetalheTela slot="ceia" />);

    expect(await screen.findByRole('heading', { name: 'Refeição não encontrada' })).toBeInTheDocument();
  });
});
```

Run: `docker compose run --rm web npx vitest run --project integration src/features/dia/components/DetalheTela.integration.test.tsx`
Expected: FAIL — `DetalheTela` não existe.

- [ ] **Step 2: Implementar**

`src/features/dia/components/DetalheTela.tsx`:
```tsx
"use client";

import { useState } from "react";
import Link from "next/link";
import { NutriBar } from "@/components/app/NutriBar";
import { Screen } from "@/components/app/Screen";
import { TopBar } from "@/components/app/TopBar";
import { IconeCheck } from "@/components/icons";
import { Button } from "@/components/ui/Button";
import { CountUp } from "@/components/ui/CountUp";
import { RailSimples } from "@/components/ui/Rail";
import { Skeleton } from "@/components/ui/Skeleton";
import { comoApiError } from "@/lib/api/errors";
import { gramas, porcentagem } from "@/lib/format";
import { useDia, useMarcarRefeicao, useSubstituicoes, useTrocarItem } from "../hooks";
import { planoSemAtivo } from "../regras";
import type { ItemDoDia } from "../tipos";
import { AvisoDeAlteracao } from "./AvisoDeAlteracao";
import { FoodItemRow } from "./FoodItemRow";
import { NoPlanState } from "./NoPlanState";
import { SubstitutionSheet } from "./SubstitutionSheet";

/** S13 — uma refeição de hoje: o que vai no prato, trocas (RF14), desfazer (RF15) e marcar (RF13). */
export function DetalheTela({ slot }: { slot: string }) {
  const dia = useDia();
  const marcar = useMarcarRefeicao();
  const trocar = useTrocarItem();
  const [alvo, setAlvo] = useState<ItemDoDia | null>(null);
  const opcoes = useSubstituicoes(alvo?.id ?? null);
  const semPlano = planoSemAtivo(dia.error);

  if (semPlano) {
    return (
      <Screen>
        <TopBar voltarPara="/dieta" rotuloVoltar="Voltar para a dieta" />
        <main className="flex-1 px-5 pt-3">
          <NoPlanState estado={semPlano.status === "failed" ? "falhou" : "gerando"} planId={semPlano.planId} aoTentarDeNovo={() => void dia.refetch()} />
        </main>
      </Screen>
    );
  }

  if (!dia.data) {
    return (
      <Screen>
        <TopBar voltarPara="/dieta" rotuloVoltar="Voltar para a dieta" />
        <main className="flex-1 px-5 pt-3">
          <Skeleton className="h-10 w-40" />
          <Skeleton className="mt-4 h-[180px] rounded-3xl" />
          <Skeleton className="mt-5 h-[300px] rounded-3xl" />
        </main>
      </Screen>
    );
  }

  const refeicao = dia.data.meals.find((m) => m.slot === slot);

  if (!refeicao) {
    return (
      <Screen>
        <TopBar voltarPara="/dieta" rotuloVoltar="Voltar para a dieta" />
        <main className="flex-1 px-5 pt-6">
          <h1 className="font-display text-2xl font-bold">Refeição não encontrada</h1>
          <p className="mt-2 text-sm text-fumo">Esse horário não está no seu plano de hoje.</p>
          <Link href="/dieta" className="mt-4 inline-block text-sm font-semibold text-mata">
            Ver as refeições de hoje
          </Link>
        </main>
      </Screen>
    );
  }

  const { targets, totals, lastChange } = dia.data;
  const metas = targets ?? { kcal: totals.planned.calories, proteinG: totals.planned.protein, carbsG: totals.planned.carbs, fatG: totals.planned.fat };
  const proteico = [...refeicao.items].sort((a, b) => b.macros.protein - a.macros.protein)[0];

  function usar(foodId: number) {
    if (!alvo?.id) return;
    trocar.mutate(
      { itemId: alvo.id, foodId },
      {
        onSuccess: () => setAlvo(null),
        onError: (e) => {
          if (comoApiError(e).code === "SUBSTITUTION_NOT_ALLOWED") void opcoes.refetch();
        },
      },
    );
  }

  return (
    <Screen>
      <TopBar
        voltarPara="/dieta"
        rotuloVoltar="Voltar para a dieta"
        direita={
          refeicao.done ? (
            <span className="inline-flex h-[30px] animate-pop items-center gap-1.5 rounded-full bg-mata-fraca px-3 text-[11.5px] font-semibold text-mata-texto">
              <IconeCheck size={12} strokeWidth={2.4} />
              Refeição feita
            </span>
          ) : refeicao.isNext ? (
            <span className="inline-flex h-[30px] items-center gap-1.5 rounded-full bg-gema-fraca px-3">
              <span className="size-1.5 rounded-full bg-gema" />
              <span className="text-[11.5px] font-semibold text-gema-texto">Próxima refeição</span>
            </span>
          ) : null
        }
      />

      <AvisoDeAlteracao alteracao={lastChange} />

      <main className="flex-1 px-5 pt-3">
        <h1 className="animate-entra font-display text-[34px] leading-[1.05] font-bold tracking-[-0.03em]">{refeicao.name}</h1>
        <p className="mt-1.5 animate-entra text-[13.5px] text-fumo" style={{ animationDelay: "80ms" }}>
          Hoje às {refeicao.time}
          {refeicao.note ? `, ${refeicao.note.toLowerCase()}` : ""}
        </p>

        <section className="mt-4 animate-escala rounded-[20px] bg-white px-[18px] py-4" style={{ animationDelay: "140ms" }}>
          <div className="flex items-baseline justify-between gap-3">
            <p className="font-display text-4xl font-bold tracking-[-0.03em]">
              <CountUp valor={Math.round(refeicao.calories)} duracao={1000} />{" "}
              <span className="text-[17px] font-semibold tracking-normal text-fumo">kcal</span>
            </p>
            <span className="shrink-0 text-[12.5px] text-fumo">{Math.round(porcentagem(refeicao.calories, metas.kcal))}% do seu dia</span>
          </div>

          <div className="mt-3.5 border-t border-fio pt-3.5">
            <RailSimples rotulo="Proteína" valor={gramas(refeicao.macros.protein)} proporcao={porcentagem(refeicao.macros.protein, metas.proteinG)} cor="bg-tinta" atraso={320} />
            <RailSimples rotulo="Carboidrato" valor={gramas(refeicao.macros.carbs)} proporcao={porcentagem(refeicao.macros.carbs, metas.carbsG)} cor="bg-gema" atraso={400} />
            <RailSimples rotulo="Gordura" valor={gramas(refeicao.macros.fat)} proporcao={porcentagem(refeicao.macros.fat, metas.fatG)} cor="bg-mata" atraso={480} />
            <p className="mt-2.5 text-xs text-fumo">As barras mostram quanto esta refeição cobre da sua meta do dia.</p>
          </div>
        </section>

        <h2 className="mt-5 animate-entra font-display text-[15px] font-semibold" style={{ animationDelay: "300ms" }}>
          O que vai no prato
        </h2>

        <ul className="mt-2.5 list-none rounded-[20px] bg-white px-[18px]">
          {refeicao.items.map((item, i) => (
            <FoodItemRow
              key={item.id ?? `${item.foodId}-${i}`}
              item={item}
              indice={i}
              ultimo={i === refeicao.items.length - 1}
              aoTrocar={item.id !== null && dia.data.editable ? () => setAlvo(item) : undefined}
            />
          ))}
        </ul>
      </main>

      <footer className="flex shrink-0 animate-entra flex-col gap-2.5 px-5 pt-3.5 pb-7 area-segura-baixo" style={{ animationDelay: "560ms" }}>
        {proteico ? (
          <NutriBar
            href={`/nutri?pergunta=${encodeURIComponent(`Não tenho ${proteico.name.toLowerCase()} em casa. O que uso no lugar?`)}`}
            texto={`Não tenho ${proteico.name.toLowerCase()} em casa`}
          />
        ) : null}
        <Button variante={refeicao.done ? "contorno" : "primaria"} onClick={() => marcar.mutate({ slot: refeicao.slot, done: !refeicao.done })}>
          {refeicao.done ? "Desmarcar refeição" : "Marcar como feita"}
        </Button>
      </footer>

      <SubstitutionSheet
        item={alvo}
        substituicoes={opcoes.data}
        carregando={opcoes.isPending && alvo !== null}
        erro={opcoes.isError}
        trocando={trocar.isPending}
        aoTentarDeNovo={() => void opcoes.refetch()}
        aoTrocar={usar}
        aoFechar={() => setAlvo(null)}
      />
    </Screen>
  );
}
```

`src/app/(app)/dieta/[refeicao]/page.tsx` (substituir inteiro):
```tsx
"use client";

import { useParams } from "next/navigation";
import { DetalheTela } from "@/features/dia/components/DetalheTela";

export default function DetalheRefeicao() {
  const { refeicao } = useParams<{ refeicao: string }>();
  return <DetalheTela slot={refeicao} />;
}
```

`eslint.config.mjs` — tirar `'src/app/(app)/dieta/\\[refeicao\\]/page.tsx'` do segundo bloco LEGADO.

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project integration src/features/dia && npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(dia): detalhe da refeição com troca pela API, desfazer e marcar

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Perfil — "Refazer meu plano" e os efeitos da RN21; `plan-store` sem `localStorage`

**Files (repo front):**
- Create: `src/features/dia/useEfeitoNoPlano.ts`, `src/features/dia/useEfeitoNoPlano.integration.test.tsx`, `src/features/perfil/components/RefazerPlano.tsx`, `src/features/perfil/components/RefazerPlano.integration.test.tsx`
- Modify: `src/features/onboarding/useEtapa.ts`, `src/features/perfil/components/PreferenciasTela.tsx`, `src/features/perfil/components/PerfilTela.tsx`, `src/lib/plan-store.tsx`, `src/mocks/handlers/onboarding.ts`

**Interfaces:**
- Consumes: `usePedirPlano` (Task 2), `useToast`, `Sheet`, `Button`.
- Produces:
  - `useEfeitoNoPlano()` → `(meta: { planEffect: EfeitoNoPlano; planId: number | null }, opcoes?: { aviso?: string }) => void`. Faz: `regeneration_started` → `router.push('/onboarding/gerando?plano={id}&voltar=%2Fperfil')`; `regeneration_suggested` → toast "Salvo. Quer refazer seu plano com isso?" + ação "Refazer" (pede e abre o Gerando) e `router.push('/perfil')`; `times_updated` → "Horários das refeições atualizados" e `/perfil`; `none` → "Salvo." e `/perfil`. Todo efeito ≠ `none` invalida `['dia']`. `opcoes.aviso` troca o texto do toast (usado pelo "meta ajustada").
  - `RefazerPlano()` — botão "Refazer meu plano" + folha de confirmação.
  - `gravandoEtapa(etapa, meta)` aceita `plan_effect`/`plan_id`.

- [ ] **Step 1: Testes que devem falhar**

`src/features/dia/useEfeitoNoPlano.integration.test.tsx`:
```tsx
import { act, renderHook, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Toaster } from '@/components/ui/Toaster';
import { url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { redefinirNavegacao, roteador } from '@/test/next-navigation';
import { novoClienteDeTeste } from '@/test/renderizar';
import { useEfeitoNoPlano } from './useEfeitoNoPlano';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

function montar() {
  const cliente = novoClienteDeTeste();
  const invalidar = vi.spyOn(cliente, 'invalidateQueries');
  const wrapper = ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={cliente}>
      <Toaster>{children}</Toaster>
    </QueryClientProvider>
  );
  return { invalidar, ...renderHook(() => useEfeitoNoPlano(), { wrapper }) };
}

beforeEach(() => redefinirNavegacao());

describe('useEfeitoNoPlano (RN21)', () => {
  it('regeneration_started abre o Gerando com volta ao Perfil', () => {
    const { result, invalidar } = montar();
    act(() => result.current({ planEffect: 'regeneration_started', planId: 51 }));
    expect(roteador.push).toHaveBeenCalledWith('/onboarding/gerando?plano=51&voltar=%2Fperfil');
    expect(invalidar).toHaveBeenCalledWith({ queryKey: ['dia'] });
  });

  it('regeneration_suggested volta ao Perfil e oferece "Refazer"', async () => {
    server.use(http.post(url('/plans'), () => HttpResponse.json({ data: { id: 52, status: 'pending' } }, { status: 202 })));
    const { result } = montar();

    act(() => result.current({ planEffect: 'regeneration_suggested', planId: null }));

    expect(roteador.push).toHaveBeenCalledWith('/perfil');
    expect(screen.getByText('Salvo. Quer refazer seu plano com isso?')).toBeInTheDocument();
    await userEvent.setup().click(screen.getByRole('button', { name: 'Refazer' }));
    await vi.waitFor(() => expect(roteador.push).toHaveBeenCalledWith('/onboarding/gerando?plano=52&voltar=%2Fperfil'));
  });

  it.each([
    ['times_updated', 'Horários das refeições atualizados'],
    ['none', 'Salvo.'],
  ] as const)('%s avisa "%s" e volta ao Perfil', (efeito, texto) => {
    const { result } = montar();
    act(() => result.current({ planEffect: efeito, planId: null }));
    expect(screen.getByText(texto)).toBeInTheDocument();
    expect(roteador.push).toHaveBeenCalledWith('/perfil');
  });
});
```

`src/features/perfil/components/RefazerPlano.integration.test.tsx`:
```tsx
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { RefazerPlano } from './RefazerPlano';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => redefinirNavegacao());

describe('Refazer meu plano (RF18)', () => {
  it('confirma e abre o Gerando voltando ao Perfil', async () => {
    server.use(http.post(url('/plans'), () => HttpResponse.json({ data: { id: 60, status: 'pending' } }, { status: 202 })));
    const usuario = userEvent.setup();

    renderizar(<RefazerPlano />);
    await usuario.click(screen.getByRole('button', { name: 'Refazer meu plano' }));

    expect(
      await screen.findByText('Vamos montar um plano novo com suas respostas atuais. As refeições que você já marcou hoje ficam.'),
    ).toBeInTheDocument();
    await usuario.click(screen.getByRole('button', { name: 'Refazer' }));

    await vi.waitFor(() => expect(roteador.push).toHaveBeenCalledWith('/onboarding/gerando?plano=60&voltar=%2Fperfil'));
  });

  it('limite do dia mostra a mensagem da API', async () => {
    server.use(
      http.post(url('/plans'), () =>
        HttpResponse.json({ message: 'Você já refez o plano 5 vezes hoje. Tente amanhã.', code: 'TOO_MANY_REQUESTS' }, { status: 429 }),
      ),
    );
    const usuario = userEvent.setup();

    renderizar(<RefazerPlano />);
    await usuario.click(screen.getByRole('button', { name: 'Refazer meu plano' }));
    await usuario.click(await screen.findByRole('button', { name: 'Refazer' }));

    expect(await screen.findByText('Você já refez o plano 5 vezes hoje. Tente amanhã.')).toBeInTheDocument();
    expect(roteador.push).not.toHaveBeenCalled();
  });
});
```

Em `src/features/perfil/components/PreferenciasTela.integration.test.tsx`, acrescentar (use o `perfilApi` e o salvamento que o arquivo já monta; ajuste só a resposta do `PUT /profile/preferences`):
```tsx
  it('restrição nova leva ao Gerando (RN21)', async () => {
    server.use(
      http.put(url('/profile/preferences'), () =>
        HttpResponse.json({ data: perfilApi, meta: { plan_effect: 'regeneration_started', plan_id: 51 } }),
      ),
    );
    const usuario = userEvent.setup();

    renderizar(<PreferenciasTela />);
    await usuario.click(await screen.findByRole('checkbox', { name: /Frutos do mar/ }));
    await usuario.click(screen.getByRole('button', { name: 'Salvar' }));

    await vi.waitFor(() => expect(roteador.push).toHaveBeenCalledWith('/onboarding/gerando?plano=51&voltar=%2Fperfil'));
  });
```

Run: `docker compose run --rm web npx vitest run --project integration src/features/dia/useEfeitoNoPlano.integration.test.tsx src/features/perfil`
Expected: FAIL — `useEfeitoNoPlano`/`RefazerPlano` não existem; Preferências ainda vai para `/perfil`.

- [ ] **Step 2: Implementar**

`src/features/dia/useEfeitoNoPlano.ts`:
```ts
'use client';

import { useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import { useCallback } from 'react';
import { useToast } from '@/components/ui/Toaster';
import type { EfeitoNoPlano } from '@/features/onboarding/tipos';
import { CHAVES } from '@/lib/chaves';
import { usePedirPlano } from './hooks';

const GERANDO = (id: number) => `/onboarding/gerando?plano=${id}&voltar=${encodeURIComponent('/perfil')}`;

/** RN21 — o que a tela faz depois de salvar uma mudança do Perfil. */
export function useEfeitoNoPlano() {
  const router = useRouter();
  const avisar = useToast();
  const cliente = useQueryClient();
  const pedir = usePedirPlano();

  return useCallback(
    (meta: { planEffect: EfeitoNoPlano; planId: number | null }, opcoes: { aviso?: string } = {}) => {
      if (meta.planEffect !== 'none') void cliente.invalidateQueries({ queryKey: CHAVES.dias });

      if (meta.planEffect === 'regeneration_started' && meta.planId !== null) {
        router.push(GERANDO(meta.planId));
        return;
      }
      if (meta.planEffect === 'regeneration_suggested') {
        avisar({
          texto: opcoes.aviso ?? 'Salvo. Quer refazer seu plano com isso?',
          acao: {
            rotulo: 'Refazer',
            onClick: () => void pedir.mutateAsync().then((id) => router.push(GERANDO(id))),
          },
        });
      } else {
        avisar({ texto: opcoes.aviso ?? (meta.planEffect === 'times_updated' ? 'Horários das refeições atualizados' : 'Salvo.') });
      }
      router.push('/perfil');
    },
    [avisar, cliente, pedir, router],
  );
}
```

`src/features/onboarding/useEtapa.ts` — usar o efeito ao editar pelo Perfil. Acrescentar o import `import { useEfeitoNoPlano } from '@/features/dia/useEfeitoNoPlano';`, a linha `const aplicarEfeito = useEfeitoNoPlano();` logo depois de `const salvarEtapa = useSalvarEtapa(etapa);`, e trocar o trecho de `let avisos` até o fim de `salvar` por:
```ts
    let meta: MetaDaResposta;
    try {
      meta = (await salvarEtapa.mutateAsync(corpo)).meta;
    } catch (e) {
      const erro = comoApiError(e);
      if (erro.code === 'VALIDATION_ERROR') setErrosCampo(primeirasMensagens(erro.fieldErrors));
      else setErroGeral(erro.code === 'NETWORK_ERROR' ? new ApiError(0, 'NETWORK_ERROR', ERRO_AO_SALVAR) : erro);
      return;
    }

    const metaAjustada = meta.warnings.includes('GOAL_WEIGHT_RESET');
    if (editando) {
      // Pelo Perfil: o efeito no plano decide o destino (RN21); o aviso da meta tem prioridade no texto.
      aplicarEfeito(meta, metaAjustada ? { aviso: AVISO_META_AJUSTADA } : {});
      return;
    }
    if (metaAjustada) avisar({ texto: AVISO_META_AJUSTADA });
    router.push(destino);
  }
```
e acrescentar `MetaDaResposta` ao `import type { EtapaEditavel } from './tipos';` (→ `import type { EtapaEditavel, MetaDaResposta } from './tipos';`). O "Ver meta" do aviso de meta ajustada sai (o toast agora carrega "Refazer" quando o plano sugere refazer). Ajuste o teste de integração existente que esperava "Ver meta" — é o mesmo texto `AVISO_META_AJUSTADA`, agora sem a ação; registre a ruling.

`src/features/perfil/components/PreferenciasTela.tsx` — no `FormPreferencias`: acrescentar `import { useEfeitoNoPlano } from "@/features/dia/useEfeitoNoPlano";`, `const aplicarEfeito = useEfeitoNoPlano();`, guardar a resposta e trocar o fim do salvamento:
```tsx
    let resposta: Awaited<ReturnType<typeof salvar.mutateAsync>>;
    try {
      resposta = await salvar.mutateAsync({
        restrictions: catalogo.restrictions.map((r) => r.slug).filter((s) => escolhas.restrictions.includes(s)),
        otherRestrictions: pendente ? [...escolhas.otherRestrictions, pendente] : escolhas.otherRestrictions,
        pantryItems: catalogo.pantry.flatMap((g) => g.items.map((i) => i.slug)).filter((s) => escolhas.pantryItems.includes(s)),
        dislikedFoodIds: catalogo.dislikeOptions.map((d) => d.id).filter((id) => escolhas.dislikedFoodIds.includes(id)),
      });
    } catch (e) {
      setErro(comoApiError(e));
      return;
    }
    aplicarEfeito(resposta.meta); // RN21: restrição nova refaz o plano; cozinha/"não curto" sugere refazer
```
(remover as linhas `avisar({ texto: "Salvo." }); router.push("/perfil");` e, se ficarem sem uso, `useRouter`/`useToast` do `FormPreferencias`.)

`src/features/perfil/components/RefazerPlano.tsx`:
```tsx
"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/Button";
import { FormError } from "@/components/ui/FormError";
import { Sheet } from "@/components/ui/Sheet";
import { usePedirPlano } from "@/features/dia/hooks";
import { comoApiError } from "@/lib/api/errors";

/** RF18 — pede um plano novo com as respostas atuais; as refeições feitas hoje ficam (RN20). */
export function RefazerPlano() {
  const router = useRouter();
  const pedir = usePedirPlano();
  const [aberta, setAberta] = useState(false);

  async function refazer() {
    try {
      const id = await pedir.mutateAsync();
      router.push(`/onboarding/gerando?plano=${id}&voltar=${encodeURIComponent("/perfil")}`);
    } catch {
      // a mensagem aparece na folha
    }
  }

  return (
    <>
      <Button variante="contorno" className="mt-3.5 w-full animate-entra" onClick={() => setAberta(true)}>
        Refazer meu plano
      </Button>
      <Sheet
        aberta={aberta}
        aoFechar={() => {
          setAberta(false);
          pedir.reset();
        }}
        titulo="Refazer meu plano"
        descricao="Vamos montar um plano novo com suas respostas atuais. As refeições que você já marcou hoje ficam."
      >
        {pedir.isError ? <FormError className="mt-4" erro={comoApiError(pedir.error)} /> : null}
        <div className="mt-5 flex flex-col gap-2">
          <Button carregando={pedir.isPending} onClick={() => void refazer()}>
            Refazer
          </Button>
          <button type="button" onClick={() => setAberta(false)} className="flex h-12 items-center justify-center text-[14.5px] font-semibold text-fumo">
            Cancelar
          </button>
        </div>
      </Sheet>
    </>
  );
}
```
Confira a assinatura do `FormError` do Plano 02 (`src/components/ui/FormError.tsx`) e passe a mensagem do jeito que ele recebe (ele mostra `erro.message`); se a prop tiver outro nome, ajuste aqui.

`src/features/perfil/components/PerfilTela.tsx` — trocar as linhas 134–135 (o comentário e a `NutriBar` "Refazer meu plano com o Nutri") por `<RefazerPlano />`, com `import { RefazerPlano } from "./RefazerPlano";`; se `NutriBar` ficar sem uso, tirar o import (I18: o Nutri não refaz planos).

`src/mocks/handlers/onboarding.ts` — `gravandoEtapa` passa o efeito:
```ts
export function gravandoEtapa(etapa: string, meta: { warnings?: string[]; planEffect?: string; planId?: number | null } = {}) {
```
e na resposta `meta: { plan_effect: meta.planEffect ?? 'none', plan_id: meta.planId ?? null, warnings: meta.warnings ?? [] },`.

`src/lib/plan-store.tsx` — o dia sai do protótipo: apagar a constante `CHAVE`, o bloco que lê `window.localStorage` em `carregar` (fica `setPlan(d);`), a função `salvar` e os `localStorage.setItem` de `alternarRefeicao`. Depois rode `grep -rn "alternarRefeicao\|substituirAlimento\|desfazer\|ultimaAlteracao\|limparAviso" src --include=*.tsx` — o que só Hoje/Dieta/Detalhe usavam e ficou sem uso sai do provider (e do tipo do contexto).

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm web npx vitest run --project integration src/features`
Expected: PASS.

- [ ] **Step 4: Suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(perfil): Refazer meu plano e efeitos da RN21 ao salvar; plano sai do localStorage

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: E2E-01 (fim), E2E-04, E2E-05, E2E-06, E2E-07, E2E-11 e a fila no CI

**Files (repo front):**
- Create: `e2e/dia.spec.ts`, `e2e/plano.spec.ts`
- Modify: `e2e/contas.ts`, `e2e/cadastro.spec.ts`, `.github/workflows/ci.yml`, `README.md`

**Interfaces:**
- Consumes: contas da Task 1 (backend); telas das Tasks 5–9.
- Produces: `conta(tipo, navegador)` em `e2e/contas.ts`.

- [ ] **Step 1: Subir o backend com a fila e a falha roteirizada**

```bash
cd /home/alvez/atividade-extensionista/backend
export AI_FAKE_FAIL_PLAN_FOR=falha-chromium@e2e.pratoforte.test,falha-webkit@e2e.pratoforte.test
docker compose up -d
docker compose exec api php artisan migrate:fresh --seeder=E2ESeeder --force
docker compose exec api printenv AI_FAKE_FAIL_PLAN_FOR
```
Expected: os dois e-mails impressos; `docker compose ps` mostra `queue` e `scheduler` rodando.

- [ ] **Step 2: Escrever os E2E**

`e2e/contas.ts` — acrescentar:
```ts
/** Contas do 04B, uma por navegador: `dia`, `alergia`, `mudanca`, `falha`. */
export const conta = (tipo: 'dia' | 'alergia' | 'mudanca' | 'falha', navegador: string) => `${tipo}-${navegador}@e2e.pratoforte.test`;
```

`e2e/cadastro.spec.ts` — no fim do teste do cadastro, trocar `await expect(page).toHaveURL(/\/onboarding\/gerando$/);` por:
```ts
  await expect(page).toHaveURL(/\/onboarding\/gerando\?plano=\d+$/);
  await expect(page.getByRole('heading', { name: /Seu plano está pronto/ })).toBeVisible({ timeout: 30_000 });
  await expect(page.getByText('Um dia comum')).toBeVisible();
  await page.getByRole('link', { name: 'Ver o dia de hoje' }).click();

  await expect(page).toHaveURL(/\/hoje$/);
  await expect(page.getByText('0 de 5 refeições')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Metas de hoje' })).toBeVisible();
```

`e2e/dia.spec.ts`:
```ts
import { expect, test } from '@playwright/test';
import { conta, entrar } from './contas';

test('marcar refeição persiste ao recarregar; desmarcar volta (E2E-04, CA06)', async ({ page, browserName }) => {
  await entrar(page, conta('dia', browserName));
  await expect(page).toHaveURL(/\/hoje$/);
  const antes = await page.getByText(/de 5 refeições/).textContent();
  const feitas = Number(antes?.split(' ')[0]);

  const marcar = page.getByRole('button', { name: /^Marcar .* como feita$/ });
  const nome = (await marcar.getAttribute('aria-label'))!.replace(/^Marcar /, '').replace(/ como feita$/, '');
  await marcar.click();
  await expect(page.getByText(`${feitas + 1} de 5 refeições`)).toBeVisible();

  await page.reload();
  await expect(page.getByText(`${feitas + 1} de 5 refeições`)).toBeVisible();

  // desmarca pelo detalhe, para o teste poder rodar de novo
  await page.getByRole('link', { name: new RegExp(`^${nome}$`, 'i') }).click();
  await page.getByRole('button', { name: 'Desmarcar refeição' }).click();
  await page.getByRole('link', { name: 'Voltar para a dieta' }).click();
  await page.goto('/hoje');
  await expect(page.getByText(`${feitas} de 5 refeições`)).toBeVisible();
});

test('trocar alimento na folha, ver o toast e desfazer (E2E-05, CA08, CA09)', async ({ page, browserName }) => {
  await entrar(page, conta('dia', browserName));
  await expect(page).toHaveURL(/\/hoje$/);
  await page.goto('/dieta/almoco');

  const trocar = page.getByRole('button', { name: /^Trocar / }).first();
  const original = (await trocar.textContent())!.replace(/^Trocar\s*/, '').trim();
  await trocar.click();
  const folha = page.getByRole('dialog');
  await expect(folha.getByRole('radio').first()).toHaveAttribute('aria-checked', 'true');
  await folha.getByRole('button', { name: /^Usar / }).click();

  await expect(page.getByText('Trocado')).toBeVisible();
  await expect(page.getByText(`No lugar de ${original.toLowerCase()}`)).toBeVisible();
  await page.getByRole('button', { name: 'Desfazer' }).click();

  await expect(page.getByRole('button', { name: `Trocar ${original}` })).toBeVisible();
  await expect(page.getByText('Trocado')).toHaveCount(0);
});

test('com alergia a castanhas, nada no prato nem nas trocas tem castanha (E2E-06, CA02)', async ({ page, browserName }) => {
  await entrar(page, conta('alergia', browserName));
  await expect(page).toHaveURL(/\/hoje$/);

  for (const slot of ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar']) {
    await page.goto(`/dieta/${slot}`);
    const prato = page.getByRole('list').filter({ has: page.getByRole('button', { name: /^Trocar / }) });
    await expect(prato).toBeVisible();
    await expect(prato).not.toContainText(/castanha|amendoim|nozes/i);
  }

  await page.goto('/dieta/lanche');
  await page.getByRole('button', { name: /^Trocar / }).first().click();
  const folha = page.getByRole('dialog');
  await expect(folha.getByText(/Nenhuma dessas opções tem amendoim e castanhas/)).toBeVisible();
  await expect(folha.getByRole('radiogroup')).not.toContainText(/castanha|amendoim|nozes/i);
});
```

`e2e/plano.spec.ts`:
```ts
import { expect, test } from '@playwright/test';
import { conta, entrar } from './contas';

test('falha da IA → "Tentar de novo" → pronto (E2E-07)', async ({ page, browserName }) => {
  await entrar(page, conta('falha', browserName));
  await expect(page).toHaveURL(/\/onboarding\/resumo$/);
  await page.getByRole('button', { name: 'Gerar meu plano' }).click();

  await expect(page.getByRole('heading', { name: 'Não deu para montar agora' })).toBeVisible({ timeout: 30_000 });
  await page.getByRole('button', { name: 'Tentar de novo' }).click();

  await expect(page.getByRole('heading', { name: /Seu plano está pronto/ })).toBeVisible({ timeout: 30_000 });
});

test('restrição nova no Perfil refaz o plano e volta ao Perfil (E2E-11, CA07)', async ({ page, browserName }) => {
  await entrar(page, conta('mudanca', browserName));
  await expect(page).toHaveURL(/\/hoje$/);
  await page.goto('/perfil/preferencias');

  await page.getByRole('checkbox', { name: /Intolerância a lactose/ }).click();
  await page.getByRole('button', { name: 'Salvar' }).click();

  await expect(page).toHaveURL(/\/onboarding\/gerando\?plano=\d+&voltar=%2Fperfil$/);
  await expect(page).toHaveURL(/\/perfil$/, { timeout: 30_000 });
  await expect(page.getByText('Seu plano novo está pronto.')).toBeVisible();

  for (const slot of ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar']) {
    await page.goto(`/dieta/${slot}`);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByRole('main')).not.toContainText(/iogurte|leite|queijo|requeijão/i);
  }
});
```

- [ ] **Step 3: Rodar os E2E**

Run: `docker compose run --rm web npm run e2e`
Expected: PASS em chromium e webkit (os 14 anteriores + 6 novos). Se um teste que muda estado falhar numa segunda rodada, reseed (`migrate:fresh --seeder=E2ESeeder`) antes — E2E-07 e E2E-11 só passam em banco recém-semeado.

- [ ] **Step 4: CI com fila e falha roteirizada**

`.github/workflows/ci.yml`, job `e2e`: no `env:` do job acrescentar
```yaml
      AI_FAKE_FAIL_PLAN_FOR: falha-chromium@e2e.pratoforte.test,falha-webkit@e2e.pratoforte.test
```
e no passo "API (Laravel) com as contas do E2E", depois do `php artisan serve …&`:
```yaml
          php artisan queue:work --tries=1 --timeout=170 --sleep=1 > /tmp/queue.log 2>&1 &
```
e no `if: failure()` do upload, acrescentar `/tmp/api.log` e `/tmp/queue.log` a `path`.

`README.md` do front — na seção de E2E, acrescentar: "Os E2E do plano alimentar precisam da fila do backend (`docker compose up -d` no repo backend sobe `queue`) e, para o E2E-07, de `AI_FAKE_FAIL_PLAN_FOR=falha-chromium@e2e.pratoforte.test,falha-webkit@e2e.pratoforte.test` no ambiente do backend. Rode `migrate:fresh --seeder=E2ESeeder` antes de cada rodada."

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "test(e2e): dia, troca, alergia, falha da IA e restrição nova (E2E-01, 04, 05, 06, 07, 11)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Acabamento visual (D11) com `frontend-design`

**Files (repo front):**
- Modify: `src/features/dia/components/{NoPlanState,WeekDayPicker,SubstitutionSheet,AvisoDeAlteracao}.tsx`, `src/features/perfil/components/RefazerPlano.tsx`

**Interfaces:**
- Consumes: tudo acima. Produces: nada novo — só visual.

- [ ] **Step 1: Refinar com o skill `frontend-design`**

Invocar `frontend-design` com: "Refinar NoPlanState, WeekDayPicker (foco visível de teclado), SubstitutionSheet (transição da opção marcada, estado de erro), AvisoDeAlteracao e o botão/folha 'Refazer meu plano' do Prato Forte: microinterações generosas fiéis ao mock (tinta, gema, mata, musgo), sem mudar props, textos, roles, aria nem ids; só tokens do globals.css e animações transform/opacity; respeitar prefers-reduced-motion; text-musgo só sobre tinta."

- [ ] **Step 2: Suíte inteira e E2E**

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm run build"` e depois `docker compose run --rm web npm run e2e` (banco recém-semeado).
Expected: tudo verde.

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "style(dia): acabamento das telas do plano alimentar (D11)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
