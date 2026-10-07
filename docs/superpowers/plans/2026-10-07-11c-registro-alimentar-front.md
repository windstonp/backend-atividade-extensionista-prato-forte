# Plano 11C — Registro alimentar (front) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A tela da refeição vira "o que eu comi × a sugestão": régua da meta, lista de registros editável, sugestão com "+" por item e "Adicionar os n", folha para buscar no catálogo / cadastrar alimento próprio; Hoje e Dieta perdem o "marcar como feita" e ganham ontem.

**Architecture:** Tipos e cliente da API acompanham o formato compatível da spec 09 §5 (`items` = sugestão; `entries`, `consumed`, `status`, `goalMet` novos). Regras puras em `features/dia/registro.ts` (frase da meta, situação espelhada do RN48 para o otimismo, atalhos de quantidade, prévia). Hooks de escrita recebem a data **da tela** (hoje ou ontem). Componentes de apresentação com story + `play`; a tela (`DetalheTela`) e a folha (`AddFoodSheet`) são contêineres testados com MSW.

**Tech Stack:** Next 16, React 19, TanStack Query, Tailwind v4 (tokens de `globals.css`), Storybook 10 + Vitest + Testing Library + MSW, Playwright. Comandos no contêiner: `docker run --rm -v $PWD:/app -w /app node:22-bookworm sh -c '…'` a partir de `frontend/` (ou `npm` local, se houver Node).

**Spec:** `backend/specs/09-registro-alimentar/spec.md` (RF31–RF37, S13, S13a, S11, S12, CA31–CA45, §8), `backend/specs/08-design-system/spec.md` (primitivos), `backend/specs/00-fundacao/regras-de-negocio.md` (RN46–RN48).

**Onde rodar:** front, branch nova `registro-alimentar` a partir de `plano-10b-design-system`. **Depende do Plano 11A** (API) para o E2E; as tasks 1–8 usam MSW e não precisam do backend.

## Global Constraints

- Identidade do mock e D11: só tokens de `globals.css` (`tinta`, `papel`, `gema`, `mata`, `mata-media`, `alerta`, `fumo`, `linha`, `fio`, …), Bricolage (display) + Instrument Sans; movimento com `transform`/`opacity`/`width`; `prefers-reduced-motion` respeitado.
- O elemento memorável é a **régua da refeição**; o resto fica quieto (registro = cartão branco; sugestão = texto `fumo` sobre `papel`, sem cartão).
- Textos exatamente como na spec 09 (sentença, sem caixa alta): "O que você comeu", "Adicionar alimento", "Sugestão para bater a meta", "Montamos com o que você tem em casa.", "Adicionar os {n}", "Nada registrado ainda. Toque em + numa sugestão ou adicione o que você comeu.", "Quanto você comeu?", "Buscar alimento", "Você costuma comer", "Não achamos "{termo}".", "Cadastrar alimento", "Perguntar ao Nutri o que mais se parece", "Copie da tabela nutricional da embalagem.", "Salvar e continuar", "Meta batida.", "Registrar refeição".
- `+` tem alvo de 44 px (círculo visível 30 px) e nome "Registrar {alimento}, {quantidade}"; ✓ é botão desabilitado "{alimento} já registrado".
- Régua: `role="meter"` com `aria-valuemin=0`, `aria-valuemax` = meta, `aria-valuenow` = registrado, `aria-valuetext` = frase; frase também numa região `aria-live="polite"`.
- Escritas vão para a **data da tela** (`dia.date`), nunca para `today` (RN23 — virada da meia-noite).
- Nenhum dado de domínio fixo fora de `src/mocks/` (D12; o CI checa).

## Review Focus

1. **"+" rápido duas vezes** — o segundo toque no mesmo item, antes da resposta, não envia de novo (o item já aparece ✓ no otimismo). Teste em Task 6.
2. **Erro de rede no "+"** — o registro otimista some, o "+" volta e aparece o toast "Não foi possível salvar. Tente de novo.". Teste em Task 6.
3. **Quantidade com vírgula** — "150,5" vira 150.5; vazio, 0 e > 2000 desabilitam "Adicionar" com a mensagem do campo. Teste em Task 4.
4. **Dia virou com a tela de ontem aberta** — `DAY_NOT_EDITABLE` mostra "O dia virou. Atualizamos para hoje." e recarrega (comportamento atual de `diaVirou`). Teste em Task 6.
5. **Busca digitando rápido** — só a última busca aparece (debounce 250 ms + `queryKey` com o termo; respostas antigas não sobrescrevem). Teste em Task 5.

---

### Task 1: Tipos, cliente da API e fixtures

**Files:**
- Modify: `src/features/dia/tipos.ts`, `src/lib/api/dia.ts`, `src/mocks/fixtures/dia.ts`, `src/mocks/handlers/dia.ts`
- Create: `src/lib/api/alimentos.ts`, `src/mocks/fixtures/alimentos.ts`, `src/mocks/handlers/alimentos.ts`; registre o handler em `src/mocks/handlers/index.ts`
- Test: `src/lib/api/alimentos.integration.test.ts`

**Interfaces:**
- Produces (tipos): `Medida = 'g' | 'ml'`; `ItemDoDia` + `measure: Medida; registered: boolean`; `Registro { id: number; foodId: number | null; customFoodId: number | null; suggestionItemId: number | null; name: string; amount: number; measure: Medida; amountText: string; calories: number; macros: Macros; conflicts: string[] }`; `StatusDaMeta { calories: 'below'|'ok'|'above'; protein: 'below'|'ok'; fat: 'ok'|'above' }`; `RefeicaoDoDia` + `consumed: Totais; status: StatusDaMeta | null; goalMet: boolean; entries: Registro[]`; `AlimentoBusca { id: number; kind: 'catalog'|'custom'; name: string; measure: Medida; group: string | null; per100: Totais; portion: { amount: number; text: string } | null; household: { label: string; labelPlural: string | null; amount: number } | null; conflicts: string[]; lastAmount?: number }`; `NovaEntrada = { suggestionItemId: number; amount?: number } | { foodId: number; amount: number } | { customFoodId: number; amount: number }`; `AlimentoProprioDados { name: string; measure: Medida; per100: Totais }`.
- Produces (API): `registrar(data: string, slot: Slot, entries: NovaEntrada[]): Promise<Dia>`; `editarRegistro(data: string, id: number, amount: number): Promise<Dia>`; `removerRegistro(data: string, id: number): Promise<Dia>`; `buscarAlimentos(q: string, signal?: AbortSignal): Promise<AlimentoBusca[]>`; `alimentosRecentes(): Promise<AlimentoBusca[]>`; `criarAlimentoProprio(d: AlimentoProprioDados): Promise<AlimentoBusca>`; `editarAlimentoProprio(id: number, d: Partial<AlimentoProprioDados>): Promise<AlimentoBusca>`; `apagarAlimentoProprio(id: number): Promise<void>`. **Remove** `marcarRefeicao`.
- Produces (fixtures): `diaApi({ registros?: Record<slot, RegistroApi[]>, … })` — `done` passa a ser "tem registro"; `feitas: ['almoco']` continua funcionando e gera registros iguais à sugestão; `registroApi(…)`; `alimentosApi` (leite, arroz, barra própria), `recentesApi`.

- [ ] **Step 1: Teste que falha**

`src/lib/api/alimentos.integration.test.ts`:

```ts
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { alimentosApi } from '@/mocks/fixtures/alimentos';
import { url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { buscarAlimentos, criarAlimentoProprio } from './alimentos';
import { registrar } from './dia';

describe('API de alimentos e registros', () => {
  it('busca devolve em camelCase', async () => {
    server.use(http.get(url('/foods'), ({ request }) => {
      expect(new URL(request.url).searchParams.get('q')).toBe('leite');
      return HttpResponse.json({ data: alimentosApi });
    }));
    const [leite] = await buscarAlimentos('leite');
    expect(leite.per100.calories).toBe(61);
    expect(leite.household?.labelPlural).toBe('copos');
  });

  it('registrar manda snake_case para a data da tela', async () => {
    let corpo: unknown;
    server.use(http.post(url('/days/2026-09-27/meals/jantar/entries'), async ({ request }) => {
      corpo = await request.json();
      return HttpResponse.json({ data: (await import('@/mocks/fixtures/dia')).diaApi({ data: '2026-09-27', hoje: false }) }, { status: 201 });
    }));
    await registrar('2026-09-27', 'jantar', [{ foodId: 12, amount: 200 }, { suggestionItemId: 5040 }]);
    expect(corpo).toEqual({ entries: [{ food_id: 12, amount: 200 }, { suggestion_item_id: 5040 }] });
  });

  it('cadastro de alimento próprio', async () => {
    server.use(http.post(url('/custom-foods'), async ({ request }) => HttpResponse.json({ data: { id: 4, kind: 'custom', ...(await request.json() as object), group: null, portion: null, household: null, conflicts: [] } }, { status: 201 })));
    const a = await criarAlimentoProprio({ name: 'Barra', measure: 'g', per100: { calories: 380, protein: 30, carbs: 35, fat: 12 } });
    expect(a.kind).toBe('custom');
  });
});
```

- [ ] **Step 2: Ver falhar**

Run: `npx vitest run --project integration src/lib/api/alimentos.integration.test.ts`
Expected: FAIL — módulos `@/mocks/fixtures/alimentos` e `./alimentos` não existem.

- [ ] **Step 3: Implementar**

`src/features/dia/tipos.ts` — acrescente os tipos da seção Interfaces e os campos novos em `ItemDoDia` e `RefeicaoDoDia` (comente: `/** Sugestão da refeição (D13): o que recomendamos, não o que foi comido. */` em `items`; `/** Tem pelo menos um registro (RN46). */` em `done`; `/** Meta da refeição = soma da sugestão (RN48). */` em `calories`/`macros`).

`src/lib/api/dia.ts` — remova `marcarRefeicao` e acrescente:

```ts
/** POST /days/{date}/meals/{slot}/entries — 1 a 10 alimentos (spec 09 §5). */
export const registrar = (data: string, slot: Slot, entries: NovaEntrada[]) =>
  api<Dados<Dia>>(`/days/${data}/meals/${slot}/entries`, { method: 'POST', body: { entries } }).then((r) => r.data);

/** PATCH /days/{date}/entries/{entry} */
export const editarRegistro = (data: string, id: number, amount: number) =>
  api<Dados<Dia>>(`/days/${data}/entries/${id}`, { method: 'PATCH', body: { amount } }).then((r) => r.data);

/** DELETE /days/{date}/entries/{entry} */
export const removerRegistro = (data: string, id: number) =>
  api<Dados<Dia>>(`/days/${data}/entries/${id}`, { method: 'DELETE' }).then((r) => r.data);
```

`src/lib/api/alimentos.ts`:

```ts
import type { AlimentoBusca, AlimentoProprioDados } from '@/features/dia/tipos';
import { api } from './client';

type Dados<T> = { data: T };

/** GET /foods?q= — catálogo ativo + alimentos próprios (RN50). */
export const buscarAlimentos = (q: string, signal?: AbortSignal) =>
  api<Dados<AlimentoBusca[]>>(`/foods?q=${encodeURIComponent(q)}`, { signal }).then((r) => r.data);

/** GET /foods/recent — até 8 mais registrados em 30 dias. */
export const alimentosRecentes = () => api<Dados<AlimentoBusca[]>>('/foods/recent').then((r) => r.data);

export const criarAlimentoProprio = (dados: AlimentoProprioDados) =>
  api<Dados<AlimentoBusca>>('/custom-foods', { method: 'POST', body: dados }).then((r) => r.data);

export const editarAlimentoProprio = (id: number, dados: Partial<AlimentoProprioDados>) =>
  api<Dados<AlimentoBusca>>(`/custom-foods/${id}`, { method: 'PATCH', body: dados }).then((r) => r.data);

export const apagarAlimentoProprio = (id: number) => api<void>(`/custom-foods/${id}`, { method: 'DELETE' });
```

(O cliente converte `per100` ↔ `per_100` via `snakear`/`camelizar`; confira em `src/lib/api/case.ts` que "per100" vira "per_100" — dígito depois de letra. Se não virar, acrescente a regra em `case.ts` com teste em `case.test.ts`: `snakear({ per100: 1 })` → `{ per_100: 1 }` e `camelizar({ per_100: 1 })` → `{ per100: 1 }`.)

`src/mocks/fixtures/dia.ts` — em `refeicaoApi`, cada item ganha `measure: 'g'` e `registered`; a refeição ganha `entries`, `consumed`, `status`, `goal_met`; `done` = há registro. Assinatura nova:

```ts
export type RegistroApi = {
  id: number; food_id: number | null; custom_food_id: number | null; suggestion_item_id: number | null;
  name: string; amount: number; measure: 'g' | 'ml'; amount_text: string; calories: number;
  macros: { protein: number; carbs: number; fat: number }; conflicts: string[];
};

/** Registro igual a um item sugerido (o "+"). */
export function registroDaSugestao(item: ReturnType<typeof refeicaoApi>['items'][number], id = 7000 + item.id): RegistroApi {
  return { id, food_id: item.food_id, custom_food_id: null, suggestion_item_id: item.id, name: item.name, amount: item.grams,
    measure: 'g', amount_text: item.amount, calories: item.calories, macros: item.macros, conflicts: [] };
}
```

Em `refeicaoApi(slot, i, feita, proxima, registros?: RegistroApi[])`: se `registros` vier, use-os; senão, `feita` gera `itens.map(registroDaSugestao)`. Calcule `consumed` somando os registros, `status`/`goal_met` com a mesma regra do RN48 (copie a função `statusDaMeta` da Task 2 — os fixtures não importam código de produção; duplique as 8 linhas com um comentário "espelho de RN48 para os fixtures"), `done: registros.length > 0`, `items[j].registered` = algum registro aponta para o item. Em `diaApi`, aceite `registros?: Partial<Record<string, RegistroApi[]>>` e some `totals.consumed` pelos `consumed` das refeições; `editable` passa a aceitar `parcial.editavel ?? hoje`.

`src/mocks/fixtures/alimentos.ts`:

```ts
/** Resultados de busca como a API devolve (snake_case). */
export const alimentosApi = [
  { id: 12, kind: 'catalog', name: 'Leite integral', measure: 'ml', group: 'laticinio', per_100: { calories: 61, protein: 2.9, carbs: 4.3, fat: 3.2 },
    portion: { amount: 200, text: '200 ml, mais ou menos 1 copo' }, household: { label: 'copo', label_plural: 'copos', amount: 200 }, conflicts: [] },
  { id: 28, kind: 'catalog', name: 'Arroz branco cozido', measure: 'g', group: 'carboidrato', per_100: { calories: 128, protein: 2.5, carbs: 28.1, fat: 0.2 },
    portion: { amount: 150, text: '150 g, mais ou menos 6 colheres de sopa' }, household: { label: 'colher de sopa', label_plural: 'colheres de sopa', amount: 25 }, conflicts: [] },
  { id: 4, kind: 'custom', name: 'Barra de cereal caseira', measure: 'g', group: null, per_100: { calories: 380, protein: 30, carbs: 35, fat: 12 },
    portion: null, household: null, conflicts: [] },
];

export const leiteComLactose = { ...alimentosApi[0], conflicts: ['Intolerância a lactose'] };

export const recentesApi = [{ ...alimentosApi[1], last_amount: 180 }];
```

`src/mocks/handlers/alimentos.ts`:

```ts
import { http, HttpResponse } from 'msw';
import { alimentosApi, recentesApi } from '../fixtures/alimentos';
import { url } from './auth';

/** Padrão: busca filtra a lista fixa pelo termo; recentes fixos. */
export const handlersAlimentos = [
  http.get(url('/foods'), ({ request }) => {
    const q = (new URL(request.url).searchParams.get('q') ?? '').toLowerCase();
    return HttpResponse.json({ data: alimentosApi.filter((a) => a.name.toLowerCase().includes(q)) });
  }),
  http.get(url('/foods/recent'), () => HttpResponse.json({ data: recentesApi })),
];
```

Registre `handlersAlimentos` em `src/mocks/handlers/index.ts` junto dos outros.

- [ ] **Step 4: Ver passar; consertar quem usava `marcarRefeicao`**

Run: `npx vitest run --project integration src/lib/api && npm run typecheck`
Expected: teste PASS; o typecheck aponta `hooks.ts` (`useMarcarRefeicao`) e quem o usa. Remova já `useMarcarRefeicao`/`useMarcandoRefeicao` de `hooks.ts`; em `HojeTela` passe `aoAlternar={() => {}}` e `ocupado={false}` ao `DayRail`, e em `DetalheTela` tire o botão "Marcar como feita". As duas telas são refeitas nas Tasks 6–7. Registre no ledger: "Task 1: Ruling — marcação removida já aqui para manter o typecheck verde; telas refeitas nas Tasks 6–7".

- [ ] **Step 5: Commit**

```bash
git add src/features/dia src/lib/api src/mocks
git commit -m "feat(registro): tipos, API e fixtures do registro alimentar (spec 09 §5)"
```

---

### Task 2: Regras puras — situação, frase, atalhos, prévia e otimismo (RN48)

**Files:**
- Create: `src/features/dia/registro.ts`
- Test: `src/features/dia/registro.test.ts`

**Interfaces:**
- Produces: `statusDaMeta(meta: Totais, consumido: Totais): { status: StatusDaMeta; goalMet: boolean }`; `fraseDaMeta(meta: Totais, consumido: Totais, temRegistro: boolean): string`; `atalhosDeQuantidade(a: AlimentoBusca): { rotulo: string; amount: number }[]`; `previa(per100: Totais, amount: number): Totais`; `per100DoRegistro(r: Registro): Totais`; `lerQuantidade(texto: string): number | null` (aceita vírgula; `null` se vazio, ≤ 0, > 2000 ou mais de 1 casa); `registrarSugestaoOtimista(dia: Dia, slot: Slot, itemIds: number[]): Dia`; `metaDaRefeicao(r: RefeicaoDoDia): Totais`.

- [ ] **Step 1: Testes que falham**

```ts
import { describe, expect, it } from 'vitest';
import { camelizar } from '@/lib/api/case';
import { diaApi } from '@/mocks/fixtures/dia';
import { atalhosDeQuantidade, fraseDaMeta, lerQuantidade, previa, registrarSugestaoOtimista, statusDaMeta } from './registro';
import type { AlimentoBusca, Dia } from './tipos';

const meta = { calories: 450, protein: 25, carbs: 60, fat: 12 };
const c = (calories: number, protein = 25, fat = 10) => ({ calories, protein, carbs: 50, fat });

describe('RN48 espelhado', () => {
  it.each([
    [404, 'below', false], [405, 'ok', true], [495, 'ok', true], [496, 'above', true],
  ] as const)('%i kcal → %s, batida=%s', (kcal, status, batida) => {
    const r = statusDaMeta(meta, c(kcal));
    expect(r.status.calories).toBe(status);
    expect(r.goalMet).toBe(batida);
  });

  it('frases', () => {
    expect(fraseDaMeta(meta, c(0, 0, 0), false)).toBe('Nada registrado ainda.');
    expect(fraseDaMeta(meta, c(288, 0, 0), true)).toBe('Faltam 162 kcal e 25 g de proteína.');
    expect(fraseDaMeta(meta, c(430, 24, 11), true)).toBe('Meta batida.');
    expect(fraseDaMeta(meta, c(560, 30, 11), true)).toBe('Meta batida. 110 kcal acima da sugestão.');
    expect(fraseDaMeta(meta, c(450, 25, 15), true)).toBe('Meta batida. 3 g de gordura acima da sugestão.');
    expect(fraseDaMeta(meta, c(450, 10, 11), true)).toBe('Faltam 15 g de proteína.');
  });
});

describe('quantidade', () => {
  it('vírgula, limites e casas (Review Focus 3)', () => {
    expect(lerQuantidade('150,5')).toBe(150.5);
    expect(lerQuantidade(' 200 ')).toBe(200);
    expect(lerQuantidade('')).toBeNull();
    expect(lerQuantidade('0')).toBeNull();
    expect(lerQuantidade('2000,1')).toBeNull();
    expect(lerQuantidade('1,25')).toBeNull();
    expect(lerQuantidade('abc')).toBeNull();
  });

  it('atalhos sem repetir quantidade', () => {
    const leite = camelizar<AlimentoBusca>({ id: 12, kind: 'catalog', name: 'Leite integral', measure: 'ml', group: 'laticinio',
      per_100: { calories: 61, protein: 2.9, carbs: 4.3, fat: 3.2 }, portion: { amount: 200, text: '' },
      household: { label: 'copo', label_plural: 'copos', amount: 200 }, conflicts: [] });
    expect(atalhosDeQuantidade(leite)).toEqual([
      { rotulo: '1 copo · 200 ml', amount: 200 },
      { rotulo: '2 copos · 400 ml', amount: 400 },
      { rotulo: '½ porção · 100 ml', amount: 100 },
    ]);
  });

  it('prévia por 100', () => {
    expect(previa({ calories: 61, protein: 2.9, carbs: 4.3, fat: 3.2 }, 200)).toEqual({ calories: 122, protein: 5.8, carbs: 8.6, fat: 6.4 });
  });
});

describe('otimismo do "+"', () => {
  it('registra o item, marca ✓, soma consumido do dia e da refeição', () => {
    const dia = camelizar<Dia>(diaApi());
    const item = dia.meals[2].items[0];
    const depois = registrarSugestaoOtimista(dia, 'almoco', [item.id as number]);
    const almoco = depois.meals[2];

    expect(almoco.done).toBe(true);
    expect(almoco.items[0].registered).toBe(true);
    expect(almoco.entries[0]).toMatchObject({ name: item.name, amount: item.grams, suggestionItemId: item.id });
    expect(almoco.consumed.calories).toBe(item.calories);
    expect(depois.totals.consumed.calories).toBe(item.calories);
    expect(depois.meals.find((m) => m.isNext)?.slot).toBe('cafe');
  });
});
```

- [ ] **Step 2: Ver falhar**

Run: `npx vitest run --project unit src/features/dia/registro.test.ts`
Expected: FAIL — módulo não existe.

- [ ] **Step 3: Implementar**

```ts
import type { AlimentoBusca, Dia, Registro, RefeicaoDoDia, Slot, StatusDaMeta, Totais } from './tipos';

const umaCasa = (n: number) => Math.round(n * 10) / 10;

export const metaDaRefeicao = (r: RefeicaoDoDia): Totais => ({ calories: r.calories, ...r.macros });

/** RN48 — mesma conta do backend (MealGoalStatus), para o otimismo. */
export function statusDaMeta(meta: Totais, consumido: Totais): { status: StatusDaMeta; goalMet: boolean } {
  const calories = consumido.calories * 10 < meta.calories * 9 ? 'below' : consumido.calories * 10 > meta.calories * 11 ? 'above' : 'ok';
  const protein = umaCasa(consumido.protein * 10) >= umaCasa(meta.protein * 9) ? 'ok' : 'below';
  const fat = umaCasa(consumido.fat * 10) <= umaCasa(meta.fat * 11) ? 'ok' : 'above';
  return { status: { calories, protein, fat }, goalMet: calories !== 'below' && protein === 'ok' };
}

/** RN48 — a frase da régua. */
export function fraseDaMeta(meta: Totais, consumido: Totais, temRegistro: boolean): string {
  if (!temRegistro) return 'Nada registrado ainda.';
  const { status, goalMet } = statusDaMeta(meta, consumido);
  if (goalMet) {
    const notas = ['Meta batida.'];
    if (status.calories === 'above') notas.push(`${Math.round(consumido.calories - meta.calories)} kcal acima da sugestão.`);
    if (status.fat === 'above') notas.push(`${Math.round(consumido.fat - meta.fat)} g de gordura acima da sugestão.`);
    return notas.join(' ');
  }
  const faltas: string[] = [];
  if (status.calories === 'below') faltas.push(`${Math.round(meta.calories - consumido.calories)} kcal`);
  if (status.protein === 'below') faltas.push(`${Math.round(meta.protein - consumido.protein)} g de proteína`);
  return `Faltam ${faltas.join(' e ')}.`;
}

const numero = (n: number) => (Number.isInteger(n) ? String(n) : String(n).replace('.', ','));

/** Atalhos da folha: medida caseira (1 e 2) e meia porção; sem repetir quantidade. */
export function atalhosDeQuantidade(a: AlimentoBusca): { rotulo: string; amount: number }[] {
  const lista: { rotulo: string; amount: number }[] = [];
  const mais = (rotulo: string, amount: number) => {
    if (amount > 0 && amount <= 2000 && !lista.some((x) => x.amount === amount)) lista.push({ rotulo, amount });
  };
  if (a.household) {
    mais(`1 ${a.household.label} · ${numero(a.household.amount)} ${a.measure}`, a.household.amount);
    mais(`2 ${a.household.labelPlural ?? a.household.label} · ${numero(a.household.amount * 2)} ${a.measure}`, a.household.amount * 2);
  }
  if (a.portion) {
    mais(`½ porção · ${numero(a.portion.amount / 2)} ${a.measure}`, a.portion.amount / 2);
    mais(`1 porção · ${numero(a.portion.amount)} ${a.measure}`, a.portion.amount);
  }
  if (a.lastAmount) mais(`Como da última vez · ${numero(a.lastAmount)} ${a.measure}`, a.lastAmount);
  return lista;
}

export const previa = (per100: Totais, amount: number): Totais => ({
  calories: Math.round((per100.calories * amount) / 100),
  protein: umaCasa((per100.protein * amount) / 100),
  carbs: umaCasa((per100.carbs * amount) / 100),
  fat: umaCasa((per100.fat * amount) / 100),
});

/** Para editar a quantidade sem buscar o alimento: os valores por 100 saem do próprio retrato. */
export const per100DoRegistro = (r: Registro): Totais => ({
  calories: (r.calories * 100) / r.amount, protein: (r.macros.protein * 100) / r.amount,
  carbs: (r.macros.carbs * 100) / r.amount, fat: (r.macros.fat * 100) / r.amount,
});

/** "150,5" → 150.5; vazio, ≤ 0, > 2000 ou mais de 1 casa → null. */
export function lerQuantidade(texto: string): number | null {
  const limpo = texto.trim().replace(',', '.');
  if (!/^\d+(\.\d)?$/.test(limpo)) return null;
  const n = Number(limpo);
  return n > 0 && n <= 2000 ? n : null;
}

function somar(partes: Totais[]): Totais {
  const t = partes.reduce((a, p) => ({ calories: a.calories + p.calories, protein: a.protein + p.protein, carbs: a.carbs + p.carbs, fat: a.fat + p.fat }),
    { calories: 0, protein: 0, carbs: 0, fat: 0 });
  return { calories: t.calories, protein: umaCasa(t.protein), carbs: umaCasa(t.carbs), fat: umaCasa(t.fat) };
}

/** Otimismo do "+": registra os itens sugeridos com id provisório negativo; a resposta da API substitui. */
export function registrarSugestaoOtimista(dia: Dia, slot: Slot, itemIds: number[]): Dia {
  let provisorio = -Date.now();
  const meals = dia.meals.map((m) => {
    if (m.slot !== slot) return m;
    const novos: Registro[] = m.items
      .filter((i) => i.id !== null && itemIds.includes(i.id) && !i.registered)
      .map((i) => ({ id: provisorio--, foodId: i.foodId, customFoodId: null, suggestionItemId: i.id, name: i.name, amount: i.grams,
        measure: i.measure, amountText: i.amount, calories: i.calories, macros: i.macros, conflicts: [] }));
    const entries = [...m.entries, ...novos];
    const consumed = somar(entries.map((e) => ({ calories: e.calories, ...e.macros })));
    const { status, goalMet } = statusDaMeta(metaDaRefeicao(m), consumed);
    return { ...m, entries, consumed, status, goalMet, done: entries.length > 0,
      items: m.items.map((i) => (i.id !== null && itemIds.includes(i.id) ? { ...i, registered: true } : i)) };
  });
  const proxima = dia.isToday ? meals.find((m) => !m.done)?.slot : undefined;
  const consumed = somar(meals.map((m) => m.consumed));
  const planned = dia.totals.planned;
  return {
    ...dia,
    meals: meals.map((m) => ({ ...m, isNext: m.slot === proxima })),
    totals: { planned, consumed, remaining: {
      calories: Math.max(0, planned.calories - consumed.calories), protein: umaCasa(Math.max(0, planned.protein - consumed.protein)),
      carbs: umaCasa(Math.max(0, planned.carbs - consumed.carbs)), fat: umaCasa(Math.max(0, planned.fat - consumed.fat)) } },
  };
}
```

Remova `recalcularDia` de `regras.ts` (e seu teste em `regras.test.ts`), que era o otimismo do "marcar".

- [ ] **Step 4: Ver passar**

Run: `npx vitest run --project unit src/features/dia`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/features/dia
git commit -m "feat(registro): situação da meta, frase, atalhos e otimismo do + (RN48)"
```

---

### Task 3: Hooks de escrita e de busca

**Files:**
- Modify: `src/features/dia/hooks.ts`, `src/lib/chaves.ts` (`alimentos`, `recentes`)
- Test: `src/features/dia/hooks.integration.test.tsx` (acrescentar casos)

**Interfaces:**
- Consumes: API da Task 1; `registrarSugestaoOtimista` (Task 2).
- Produces: `useRegistrar(dataChave: string)` → mutation `{ slot: Slot; entries: NovaEntrada[] }` (otimista quando todas as entradas são `suggestionItemId`; reverte em erro; `ERRO_AO_MARCAR`/`diaVirou` como hoje); `useEditarRegistro(dataChave)` → `{ id, amount }`; `useRemoverRegistro(dataChave)` → `{ registro: Registro, slot: Slot }` com toast "{nome} removido" + "Desfazer" (re-registra com `suggestionItemId`+`amount` ou `foodId`/`customFoodId`+`amount`); `useRegistrando()` (há registro em voo); `useBuscaAlimentos(termo: string)` (debounce 250 ms, `enabled` com ≥ 2 letras, `queryKey: ['alimentos', termoDebounced]`, `placeholderData: keepPreviousData`); `useRecentes(ativo: boolean)`; `useCriarAlimentoProprio()`, `useEditarAlimentoProprio()`, `useApagarAlimentoProprio()` (invalidam `['alimentos']` e `['alimentos','recentes']`). `dataChave` é `'today'` ou a data ISO usada na URL; as escritas vão para `dia.date` lido do cache dessa chave.

- [ ] **Step 1: Testes que falham** (acrescente ao arquivo existente; reaproveite `renderHook`/`renderizar` como os casos atuais)

```tsx
it('"+" é otimista e a resposta do servidor substitui', async () => {
  const cliente = novoClienteDeTeste();
  cliente.setQueryData(CHAVES.dia('today'), camelizar<Dia>(diaApi()));
  server.use(http.post(url('/days/2026-09-28/meals/almoco/entries'), async () => {
    await delay(50);
    return HttpResponse.json({ data: diaApi({ feitas: ['almoco'] }) }, { status: 201 });
  }));
  const { result } = renderHook(() => useRegistrar('today'), { wrapper: comCliente(cliente) });

  act(() => result.current.mutate({ slot: 'almoco', entries: [{ suggestionItemId: 5020 }] }));
  await waitFor(() => expect(cliente.getQueryData<Dia>(CHAVES.dia('today'))!.meals[2].items[0].registered).toBe(true));
  await waitFor(() => expect(result.current.isSuccess).toBe(true));
  expect(cliente.getQueryData<Dia>(CHAVES.dia('today'))!.meals[2].entries).toHaveLength(4);
});

it('erro no "+" desfaz o otimismo e avisa (Review Focus 2)', async () => {
  const cliente = novoClienteDeTeste();
  cliente.setQueryData(CHAVES.dia('today'), camelizar<Dia>(diaApi()));
  server.use(http.post(url('/days/2026-09-28/meals/almoco/entries'), () => HttpResponse.error()));
  const { result } = renderHook(() => useRegistrar('today'), { wrapper: comCliente(cliente) });

  act(() => result.current.mutate({ slot: 'almoco', entries: [{ suggestionItemId: 5020 }] }));
  await waitFor(() => expect(result.current.isError).toBe(true));
  expect(cliente.getQueryData<Dia>(CHAVES.dia('today'))!.meals[2].items[0].registered).toBe(false);
  expect(await screen.findByText('Não foi possível salvar. Tente de novo.')).toBeInTheDocument();
});

it('busca espera 250 ms e só mostra o último termo (Review Focus 5)', async () => {
  const pedidos: string[] = [];
  server.use(http.get(url('/foods'), ({ request }) => {
    pedidos.push(new URL(request.url).searchParams.get('q')!);
    return HttpResponse.json({ data: [] });
  }));
  const { rerender } = renderHook(({ t }) => useBuscaAlimentos(t), { initialProps: { t: 'le' }, wrapper: comCliente(novoClienteDeTeste()) });
  rerender({ t: 'lei' });
  rerender({ t: 'leite' });
  await waitFor(() => expect(pedidos).toEqual(['leite']));
});
```

(Use os helpers que o arquivo já tem para `comCliente`/`novoClienteDeTeste`; se `comCliente` não existir, crie no topo do teste: `const comCliente = (c: QueryClient) => ({ children }: { children: React.ReactNode }) => <QueryClientProvider client={c}><Toaster>{children}</Toaster></QueryClientProvider>;`.)

- [ ] **Step 2: Ver falhar**

Run: `npx vitest run --project integration src/features/dia/hooks.integration.test.tsx`
Expected: FAIL — `useRegistrar`/`useBuscaAlimentos` não existem.

- [ ] **Step 3: Implementar**

Em `hooks.ts` (substitui o bloco de marcar; mantém `useDia`, `diaVirou`, trocas, desfazer, planos). As funções de troca/desfazer passam a usar `dataChave` também (recebem `'today'` como padrão, porque só valem hoje):

```ts
const REGISTRAR = ['registrar'];

/** A data do dia na tela (para escrever nela, nunca em `today` — RN23). */
const dataDe = (cliente: QueryClient, chave: string) => cliente.getQueryData<Dia>(CHAVES.dia(chave))?.date ?? chave;

/** Outros caches que mostram consumido: Dieta (por data), Evolução, contexto do Nutri. */
function depoisDeRegistrar(cliente: QueryClient, chave: string, dia: Dia) {
  cliente.setQueryData(CHAVES.dia(chave), dia);
  void cliente.invalidateQueries({ queryKey: CHAVES.dias, predicate: (q) => q.queryKey[1] !== chave });
  void cliente.invalidateQueries({ queryKey: CHAVES.progressos });
  void cliente.invalidateQueries({ queryKey: CHAVES.contextoNutri });
  void cliente.invalidateQueries({ queryKey: CHAVES.recentes });
}

/** RF32/RF33 — registrar; o "+" (só itens sugeridos) é otimista. */
export function useRegistrar(chave = 'today') {
  const cliente = useQueryClient();
  const avisar = useToast();
  return useMutation({
    mutationKey: REGISTRAR,
    mutationFn: ({ slot, entries }: { slot: Slot; entries: NovaEntrada[] }) => diaApi.registrar(dataDe(cliente, chave), slot, entries),
    onMutate: async ({ slot, entries }) => {
      const ids = entries.flatMap((e) => ('suggestionItemId' in e && e.amount === undefined ? [e.suggestionItemId] : []));
      if (ids.length !== entries.length) return { anterior: undefined };
      await cliente.cancelQueries({ queryKey: CHAVES.dia(chave) });
      const anterior = cliente.getQueryData<Dia>(CHAVES.dia(chave));
      if (anterior) cliente.setQueryData<Dia>(CHAVES.dia(chave), registrarSugestaoOtimista(anterior, slot, ids));
      return { anterior };
    },
    onError: (erro, _v, contexto) => {
      if (contexto?.anterior) cliente.setQueryData(CHAVES.dia(chave), contexto.anterior);
      if (!diaVirou(erro, cliente, avisar)) avisar({ texto: ERRO_AO_SALVAR });
    },
    onSuccess: (dia) => depoisDeRegistrar(cliente, chave, dia),
  });
}

export const useRegistrando = () => useIsMutating({ mutationKey: REGISTRAR }) > 0;

export function useEditarRegistro(chave = 'today') {
  const cliente = useQueryClient();
  const avisar = useToast();
  return useMutation({
    mutationFn: ({ id, amount }: { id: number; amount: number }) => diaApi.editarRegistro(dataDe(cliente, chave), id, amount),
    onSuccess: (dia) => depoisDeRegistrar(cliente, chave, dia),
    onError: (erro) => void (diaVirou(erro, cliente, avisar) || avisar({ texto: ERRO_AO_SALVAR })),
  });
}

/** RF34 — remove e oferece "Desfazer" (registra de novo, inclusive o vínculo com a sugestão). */
export function useRemoverRegistro(chave = 'today') {
  const cliente = useQueryClient();
  const avisar = useToast();
  const registrar = useRegistrar(chave);
  return useMutation({
    mutationFn: ({ registro }: { registro: Registro; slot: Slot }) => diaApi.removerRegistro(dataDe(cliente, chave), registro.id),
    onSuccess: (dia, { registro, slot }) => {
      depoisDeRegistrar(cliente, chave, dia);
      const devolver: NovaEntrada = registro.suggestionItemId !== null
        ? { suggestionItemId: registro.suggestionItemId, amount: registro.amount }
        : registro.foodId !== null ? { foodId: registro.foodId, amount: registro.amount } : { customFoodId: registro.customFoodId as number, amount: registro.amount };
      avisar({ texto: `${registro.name} removido`, acao: { rotulo: 'Desfazer', onClick: () => registrar.mutate({ slot, entries: [devolver] }) } });
    },
    onError: (erro) => void (diaVirou(erro, cliente, avisar) || avisar({ texto: ERRO_AO_SALVAR })),
  });
}

/** RF33 — busca com espera de 250 ms; respostas antigas não sobrescrevem (chave com o termo). */
export function useBuscaAlimentos(termo: string) {
  const [termoFirme, setTermoFirme] = useState(termo.trim());
  useEffect(() => {
    const t = setTimeout(() => setTermoFirme(termo.trim()), 250);
    return () => clearTimeout(t);
  }, [termo]);
  return useQuery({
    queryKey: [...CHAVES.alimentos, termoFirme],
    queryFn: ({ signal }) => alimentos.buscarAlimentos(termoFirme, signal),
    enabled: termoFirme.length >= 2,
    placeholderData: keepPreviousData,
  });
}

export const useRecentes = (ativo: boolean) =>
  useQuery({ queryKey: CHAVES.recentes, queryFn: alimentos.alimentosRecentes, enabled: ativo, staleTime: 60_000 });

function useAlimentoProprio<A>(fn: (a: A) => Promise<unknown>) {
  const cliente = useQueryClient();
  return useMutation({ mutationFn: fn, onSuccess: () => void cliente.invalidateQueries({ queryKey: CHAVES.alimentos }) });
}
export const useCriarAlimentoProprio = () => useAlimentoProprio(alimentos.criarAlimentoProprio);
export const useEditarAlimentoProprio = () => useAlimentoProprio(({ id, dados }: { id: number; dados: Partial<AlimentoProprioDados> }) => alimentos.editarAlimentoProprio(id, dados));
export const useApagarAlimentoProprio = () => useAlimentoProprio(alimentos.apagarAlimentoProprio);
```

Renomeie `ERRO_AO_MARCAR` para `ERRO_AO_SALVAR` (mesmo texto) e atualize os importadores. Em `src/lib/chaves.ts`: `alimentos: ['alimentos']`, `recentes: ['alimentos', 'recentes']` (o `invalidateQueries(['alimentos'])` cobre os dois). Imports novos: `useEffect`, `useState` de `react`, `keepPreviousData`, `* as alimentos from '@/lib/api/alimentos'`, tipos.

- [ ] **Step 4: Ver passar**

Run: `npx vitest run --project integration src/features/dia`
Expected: PASS nos hooks; telas ainda podem falhar (Tasks 6–7).

- [ ] **Step 5: Commit**

```bash
git add src/features/dia/hooks.ts src/features/dia/hooks.integration.test.tsx src/lib/chaves.ts
git commit -m "feat(registro): hooks de registrar, editar, remover com desfazer e busca com espera"
```

---

### Task 4: Componentes da tela — régua, registros, sugestão

**Files:**
- Create: `src/features/dia/components/MealGoal.tsx`, `EntryList.tsx`, `SuggestionList.tsx` + `.stories.tsx` de cada um
- Delete: `src/features/dia/components/FoodItemRow.tsx` e `FoodItemRow.stories.tsx` (substituídos por `SuggestionList`)

**Interfaces:**
- Produces:
  - `MealGoal({ meta: Totais; consumido: Totais; temRegistro: boolean })` — régua + frase + 3 barras (proteína, gordura, carboidrato) consumido × meta da refeição.
  - `EntryList({ registros: Registro[]; aoAbrir?: (r: Registro) => void; aoAdicionar?: () => void })` — sem `aoAbrir`/`aoAdicionar` = somente leitura.
  - `SuggestionList({ itens: ItemDoDia[]; aoRegistrar?: (i: ItemDoDia) => void; aoRegistrarTodos?: (itens: ItemDoDia[]) => void; aoTrocar?: (i: ItemDoDia) => void; ocupado?: boolean })`.

- [ ] **Step 1: Stories com `play` (falham por não existir o componente)**

`MealGoal.stories.tsx`:

```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, within } from 'storybook/test';
import { MealGoal } from './MealGoal';

const meta = { calories: 450, protein: 25, carbs: 60, fat: 12 };
const zero = { calories: 0, protein: 0, carbs: 0, fat: 0 };

const m = {
  title: 'Dia/MealGoal',
  component: MealGoal,
  args: { meta, consumido: zero, temRegistro: false },
  decorators: [(Story) => <div className="bg-papel p-5"><Story /></div>],
} satisfies Meta<typeof MealGoal>;
export default m;
type Story = StoryObj<typeof m>;

export const Vazia: Story = {
  play: async ({ canvasElement }) => {
    const t = within(canvasElement);
    await expect(t.getByRole('meter')).toHaveAttribute('aria-valuetext', 'Nada registrado ainda.');
    await expect(t.getByText('meta 450')).toBeInTheDocument();
  },
};
export const Abaixo: Story = {
  args: { consumido: { calories: 288, protein: 0, carbs: 72, fat: 0 }, temRegistro: true },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('meter')).toHaveAttribute('aria-valuenow', '288');
    await expect(within(canvasElement).getAllByText('Faltam 162 kcal e 25 g de proteína.').length).toBeGreaterThan(0);
  },
};
export const NaFaixa: Story = { args: { consumido: { calories: 430, protein: 24, carbs: 55, fat: 11 }, temRegistro: true } };
export const Acima: Story = {
  args: { consumido: { calories: 560, protein: 30, carbs: 70, fat: 11 }, temRegistro: true },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('meter')).toHaveAttribute('aria-valuetext', 'Meta batida. 110 kcal acima da sugestão.');
  },
};
export const MovimentoReduzido: Story = { ...NaFaixa, parameters: { prefersReducedMotion: 'reduce' } };
```

`EntryList.stories.tsx`:

```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { camelizar } from '@/lib/api/case';
import { diaApi, registroDaSugestao, refeicaoApi } from '@/mocks/fixtures/dia';
import type { Registro } from '../tipos';
import { EntryList } from './EntryList';

const registros = camelizar<Registro[]>(refeicaoApi('almoco', 2, false, false).items.slice(0, 2).map((i) => registroDaSugestao(i)));

const m = {
  title: 'Dia/EntryList',
  component: EntryList,
  args: { registros, aoAbrir: fn(), aoAdicionar: fn() },
  decorators: [(Story) => <div className="bg-papel p-5"><Story /></div>],
} satisfies Meta<typeof EntryList>;
export default m;
type Story = StoryObj<typeof m>;

export const ComItens: Story = {
  play: async ({ canvasElement, args }) => {
    const t = within(canvasElement);
    await userEvent.click(t.getByRole('button', { name: /Arroz branco cozido, 150 g/ }));
    await expect(args.aoAbrir).toHaveBeenCalledWith(registros[0]);
    await userEvent.click(t.getByRole('button', { name: 'Adicionar alimento' }));
    await expect(args.aoAdicionar).toHaveBeenCalled();
  },
};
export const Vazia: Story = {
  args: { registros: [] },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('Nada registrado ainda. Toque em + numa sugestão ou adicione o que você comeu.')).toBeInTheDocument();
  },
};
export const SomenteLeitura: Story = {
  args: { aoAbrir: undefined, aoAdicionar: undefined },
  play: async ({ canvasElement }) => {
    const t = within(canvasElement);
    await expect(t.queryByRole('button', { name: 'Adicionar alimento' })).toBeNull();
    await expect(t.getByText('Arroz branco cozido')).toBeInTheDocument();
  },
};
void diaApi;
```

`SuggestionList.stories.tsx`:

```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { camelizar } from '@/lib/api/case';
import { refeicaoApi } from '@/mocks/fixtures/dia';
import type { ItemDoDia } from '../tipos';
import { SuggestionList } from './SuggestionList';

const itens = camelizar<ItemDoDia[]>(refeicaoApi('almoco', 2, false, false).items);

const m = {
  title: 'Dia/SuggestionList',
  component: SuggestionList,
  args: { itens, aoRegistrar: fn(), aoRegistrarTodos: fn(), aoTrocar: fn() },
  decorators: [(Story) => <div className="bg-papel p-5"><Story /></div>],
} satisfies Meta<typeof SuggestionList>;
export default m;
type Story = StoryObj<typeof m>;

export const NenhumRegistrado: Story = {
  play: async ({ canvasElement, args }) => {
    const t = within(canvasElement);
    await userEvent.click(t.getByRole('button', { name: 'Registrar Arroz branco cozido, 150 g, mais ou menos 6 colheres de sopa' }));
    await expect(args.aoRegistrar).toHaveBeenCalledWith(itens[0]);
    await userEvent.click(t.getByRole('button', { name: 'Adicionar os 4' }));
    await expect(args.aoRegistrarTodos).toHaveBeenCalledWith(itens);
  },
};
export const UmRegistrado: Story = {
  args: { itens: [{ ...itens[0], registered: true }, ...itens.slice(1)] },
  play: async ({ canvasElement }) => {
    const t = within(canvasElement);
    await expect(t.getByRole('button', { name: 'Arroz branco cozido já registrado' })).toBeDisabled();
    await expect(t.queryByRole('button', { name: 'Trocar Arroz branco cozido' })).toBeNull();
    await expect(t.getByRole('button', { name: 'Adicionar os 3' })).toBeInTheDocument();
  },
};
export const FaltaUm: Story = {
  args: { itens: itens.map((i, n) => ({ ...i, registered: n > 0 })) },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).queryByRole('button', { name: /^Adicionar os/ })).toBeNull();
  },
};
export const Ontem: Story = {
  args: { aoTrocar: undefined },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).queryByRole('button', { name: /^Trocar/ })).toBeNull();
  },
};
export const SomenteLeitura: Story = {
  args: { aoRegistrar: undefined, aoRegistrarTodos: undefined, aoTrocar: undefined },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).queryByRole('button', { name: /^Registrar/ })).toBeNull();
  },
};
```

- [ ] **Step 2: Ver falhar**

Run: `npx vitest run --project storybook src/features/dia/components/MealGoal.stories.tsx src/features/dia/components/EntryList.stories.tsx src/features/dia/components/SuggestionList.stories.tsx`
Expected: FAIL — módulos não existem.

- [ ] **Step 3: Implementar**

`MealGoal.tsx`:

```tsx
"use client";

import { CountUp } from "@/components/ui/CountUp";
import { useMontado } from "@/lib/motion";
import { fraseDaMeta, statusDaMeta } from "../registro";
import type { Totais } from "../tipos";

/**
 * A régua da refeição — o elemento memorável da tela (spec 09 S13).
 * Faixa hachurada = 90–110% da meta; o preenchimento cresce com mola a cada registro.
 * Cor: gema abaixo, mata com a meta batida; o que passa de 110% aparece em mata-media, sem tom de erro (RN48).
 */
export function MealGoal({ meta, consumido, temRegistro }: { meta: Totais; consumido: Totais; temRegistro: boolean }) {
  const montado = useMontado(120);
  const frase = fraseDaMeta(meta, consumido, temRegistro);
  const { goalMet } = statusDaMeta(meta, consumido);
  // A régua vai até 130% da meta, para caber o excesso sem estourar a largura.
  const escala = Math.max(meta.calories * 1.3, 1);
  const pct = (n: number) => `${Math.min(100, (n / escala) * 100)}%`;
  const ate110 = Math.min(consumido.calories, meta.calories * 1.1);
  const excesso = Math.max(0, consumido.calories - meta.calories * 1.1);

  return (
    <section className="animate-escala rounded-[24px] bg-white px-[18px] pt-4 pb-[18px]">
      <div className="flex items-baseline justify-between gap-3">
        <p className="font-display text-[40px] leading-none font-bold tracking-[-0.035em]">
          <CountUp valor={consumido.calories} duracao={700} /> <span className="text-[17px] font-semibold tracking-normal text-fumo">kcal</span>
        </p>
        <span className="text-[13px] text-fumo">meta {meta.calories}</span>
      </div>

      <div
        role="meter"
        aria-label="Calorias registradas nesta refeição"
        aria-valuemin={0}
        aria-valuemax={meta.calories}
        aria-valuenow={consumido.calories}
        aria-valuetext={frase}
        className="relative mt-3.5 h-4 overflow-hidden rounded-full bg-fio"
      >
        {/* faixa de acerto: 90% a 110% da meta */}
        <span
          aria-hidden="true"
          className="absolute inset-y-0 bg-[repeating-linear-gradient(135deg,var(--color-linha)_0_4px,transparent_4px_8px)]"
          style={{ left: pct(meta.calories * 0.9), width: `calc(${pct(meta.calories * 1.1)} - ${pct(meta.calories * 0.9)})` }}
        />
        <span
          aria-hidden="true"
          className={`absolute inset-y-0 left-0 rounded-full transition-[width,background-color] duration-[600ms] ease-[cubic-bezier(.34,1.3,.64,1)] motion-reduce:transition-none ${goalMet ? "bg-mata" : "bg-gema"}`}
          style={{ width: montado ? pct(ate110) : "0%" }}
        />
        {excesso > 0 ? (
          <span
            aria-hidden="true"
            className="absolute inset-y-0 bg-mata-media transition-[width] duration-[600ms] motion-reduce:transition-none"
            style={{ left: pct(meta.calories * 1.1), width: montado ? `calc(${pct(consumido.calories)} - ${pct(meta.calories * 1.1)})` : "0%" }}
          />
        ) : null}
      </div>

      <p aria-live="polite" className="mt-2.5 text-[13.5px] font-medium text-tinta">{frase}</p>

      <div className="mt-3 border-t border-fio pt-3">
        <Barra rotulo="Proteína" valor={consumido.protein} meta={meta.protein} cor="bg-tinta" />
        <Barra rotulo="Gordura" valor={consumido.fat} meta={meta.fat} cor="bg-mata" />
        <Barra rotulo="Carboidrato" valor={consumido.carbs} meta={meta.carbs} cor="bg-gema" />
      </div>
    </section>
  );
}

function Barra({ rotulo, valor, meta, cor }: { rotulo: string; valor: number; meta: number; cor: string }) {
  const montado = useMontado(200);
  const proporcao = meta > 0 ? Math.min(1, valor / meta) : 0;
  return (
    <div className="flex h-7 items-center gap-3">
      <span className="w-[86px] shrink-0 text-[12.5px] text-fumo">{rotulo}</span>
      <span className="relative h-1.5 flex-1 overflow-hidden rounded-full bg-fio">
        <span className={`absolute inset-y-0 left-0 rounded-full transition-[width] duration-[600ms] motion-reduce:transition-none ${cor}`} style={{ width: montado ? `${proporcao * 100}%` : "0%" }} />
      </span>
      <span className="w-[86px] shrink-0 text-right text-[12.5px] font-semibold tabular-nums whitespace-nowrap">
        {Math.round(valor)} / {Math.round(meta)} g
      </span>
    </div>
  );
}
```

`EntryList.tsx`:

```tsx
import { IconeMais } from "@/components/icons";
import { Selo } from "@/components/ui/Selo";
import { kcal } from "@/lib/format";
import type { Registro } from "../tipos";

/** "O que você comeu" — cartão branco; cada linha abre a edição (RF34). Sem ações = somente leitura. */
export function EntryList({ registros, aoAbrir, aoAdicionar }: { registros: Registro[]; aoAbrir?: (r: Registro) => void; aoAdicionar?: () => void }) {
  return (
    <section className="mt-6">
      <h2 className="font-display text-[15px] font-semibold">O que você comeu</h2>
      {registros.length === 0 ? (
        <p className="mt-2.5 rounded-[20px] bg-white px-[18px] py-4 text-[13.5px] leading-snug text-fumo">
          Nada registrado ainda. Toque em + numa sugestão ou adicione o que você comeu.
        </p>
      ) : (
        <ul className="mt-2.5 list-none rounded-[20px] bg-white px-[18px]">
          {registros.map((r) => {
            const corpo = (
              <>
                <span className="flex items-baseline justify-between gap-3">
                  <span className="text-[15px] font-semibold">{r.name}</span>
                  <span className="shrink-0 text-[13px] font-semibold tabular-nums">{kcal(r.calories)}</span>
                </span>
                <span className="mt-0.5 block text-[12.5px] text-fumo">{r.amountText}</span>
                {r.conflicts.length > 0 ? <Selo tom="alerta" className="mt-1.5">{r.conflicts.join(", ")}</Selo> : null}
              </>
            );
            return (
              <li key={r.id} className="animate-entra border-b border-fio last:border-b-0">
                {aoAbrir ? (
                  <button type="button" onClick={() => aoAbrir(r)} aria-label={`${r.name}, ${r.amountText}, ${kcal(r.calories)}. Editar`}
                    className="block w-full py-3 text-left">{corpo}</button>
                ) : (
                  <div className="py-3">{corpo}</div>
                )}
              </li>
            );
          })}
        </ul>
      )}
      {aoAdicionar ? (
        <button type="button" onClick={aoAdicionar}
          className="mt-2.5 flex h-12 w-full items-center justify-center gap-2 rounded-full border-[1.5px] border-tinta text-sm font-semibold hover:bg-tinta/5">
          <IconeMais size={18} /> Adicionar alimento
        </button>
      ) : null}
    </section>
  );
}
```

(O nome acessível do botão da linha começa com "{nome}, {quantidade}", como a story espera: `/Arroz branco cozido, 150 g/`.)

`SuggestionList.tsx`:

```tsx
import { IconeCheck, IconeMais } from "@/components/icons";
import { Selo } from "@/components/ui/Selo";
import type { ItemDoDia } from "../tipos";

/**
 * "Sugestão para bater a meta" (o antigo "O que vai no prato") — tipograficamente secundária:
 * texto fumo sobre papel, sem cartão. "+" registra o item; ✓ = já registrado (RF32).
 */
export function SuggestionList({ itens, aoRegistrar, aoRegistrarTodos, aoTrocar, ocupado = false }: {
  itens: ItemDoDia[];
  aoRegistrar?: (i: ItemDoDia) => void;
  aoRegistrarTodos?: (itens: ItemDoDia[]) => void;
  aoTrocar?: (i: ItemDoDia) => void;
  ocupado?: boolean;
}) {
  const faltam = itens.filter((i) => !i.registered && i.id !== null);
  if (itens.length === 0) return null;

  return (
    <section className="mt-7">
      <h2 className="font-display text-[15px] font-semibold">Sugestão para bater a meta</h2>
      <p className="mt-0.5 text-[12.5px] text-fumo">Montamos com o que você tem em casa.</p>
      <ul className="mt-2 list-none">
        {itens.map((item) => (
          <li key={item.id ?? item.foodId} className={`flex items-center gap-2 py-2 ${item.registered ? "opacity-60" : ""}`}>
            <span className="min-w-0 flex-1">
              <span className="block text-[14px] font-medium text-tinta">{item.name}</span>
              <span className="block text-[12.5px] text-fumo">{item.amount} · {item.calories} kcal</span>
              {item.replacedFrom ? <Selo tom="gema" className="mt-1">No lugar de {item.replacedFrom.toLowerCase()}</Selo> : null}
            </span>
            {aoTrocar && !item.registered && item.id !== null ? (
              <button type="button" onClick={() => aoTrocar(item)} aria-label={`Trocar ${item.name}`}
                className="h-11 shrink-0 px-2 text-[13px] font-semibold text-mata hover:text-tinta">Trocar</button>
            ) : null}
            {item.registered ? (
              <button type="button" disabled aria-label={`${item.name} já registrado`} className="flex size-11 shrink-0 items-center justify-center">
                <span className="flex size-[30px] animate-pop items-center justify-center rounded-full bg-mata text-white"><IconeCheck size={15} strokeWidth={2.4} /></span>
              </button>
            ) : aoRegistrar && item.id !== null ? (
              <button type="button" disabled={ocupado} onClick={() => aoRegistrar(item)} aria-label={`Registrar ${item.name}, ${item.amount}`}
                className="group flex size-11 shrink-0 items-center justify-center disabled:opacity-50">
                <span className="flex size-[30px] items-center justify-center rounded-full border-[1.5px] border-tinta transition-[background-color,color,transform] duration-200 group-hover:bg-tinta group-hover:text-neve group-active:scale-90">
                  <IconeMais size={16} />
                </span>
              </button>
            ) : null}
          </li>
        ))}
      </ul>
      {aoRegistrarTodos && faltam.length >= 2 ? (
        <button type="button" disabled={ocupado} onClick={() => aoRegistrarTodos(faltam)}
          className="mt-1 h-10 rounded-full border border-linha bg-white px-4 text-[13px] font-semibold hover:border-pedra disabled:opacity-50">
          Adicionar os {faltam.length}
        </button>
      ) : null}
    </section>
  );
}
```

Confira os nomes de ícones em `src/components/icons.tsx` (`IconeMais`, `IconeCheck` existem); `Selo` aceita `className`.

- [ ] **Step 4: Ver passar (stories + axe)**

Run: `npx vitest run --project storybook src/features/dia/components`
Expected: PASS, sem violações de axe.

- [ ] **Step 5: Commit**

```bash
git add src/features/dia/components
git commit -m "feat(registro): régua da refeição, lista do que foi comido e sugestão com + (S13)"
```

---

### Task 5: Folha "Adicionar alimento" — busca, quantidade, alimento próprio (S13a)

**Files:**
- Create: `src/features/dia/components/FoodSearchStep.tsx`, `AmountStep.tsx`, `CustomFoodForm.tsx`, `AddFoodSheet.tsx` + stories dos três passos
- Test: `src/features/dia/components/AddFoodSheet.integration.test.tsx`

**Interfaces:**
- Consumes: hooks da Task 3; `atalhosDeQuantidade`, `previa`, `lerQuantidade`, `per100DoRegistro` (Task 2).
- Produces:
  - `FoodSearchStep({ termo; aoMudarTermo; resultados: AlimentoBusca[] | undefined; recentes: AlimentoBusca[] | undefined; carregando: boolean; aoEscolher(a); aoEditarProprio(a); aoCadastrar(); nutriHref: string })`.
  - `AmountStep({ nome: string; medida: Medida; per100: Totais; atalhos: {rotulo; amount}[]; conflitos: string[]; inicial?: number; modo: 'adicionar' | 'editar'; salvando: boolean; aoConfirmar(amount: number); aoRemover?(); aoVoltar?(); aoEditarAlimento?() })`.
  - `CustomFoodForm({ inicial?: Partial<AlimentoProprioDados> & { id?: number }; salvando: boolean; erros: Record<string, string>; aoSalvar(d: AlimentoProprioDados); aoApagar?(); aoVoltar() })`.
  - `AddFoodSheet({ aberta: boolean; modo: { tipo: 'novo' } | { tipo: 'editar'; registro: Registro }; slot: Slot; dataChave: string; aoFechar(): void })` — contêiner: liga os hooks, decide o passo, chama `useRegistrar`/`useEditarRegistro`/`useRemoverRegistro`.

- [ ] **Step 1: Teste de integração que falha**

```tsx
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { describe, expect, it, vi } from 'vitest';
import { alimentosApi, leiteComLactose } from '@/mocks/fixtures/alimentos';
import { diaApi } from '@/mocks/fixtures/dia';
import { erroDaApi, url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { renderizar } from '@/test/renderizar';
import { camelizar } from '@/lib/api/case';
import { CHAVES } from '@/lib/chaves';
import { novoClienteDeTeste } from '@/test/renderizar';
import { AddFoodSheet } from './AddFoodSheet';
import type { Dia } from '../tipos';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

function abrir(modo: Parameters<typeof AddFoodSheet>[0]['modo'] = { tipo: 'novo' }) {
  const cliente = novoClienteDeTeste();
  cliente.setQueryData(CHAVES.dia('today'), camelizar<Dia>(diaApi()));
  const aoFechar = vi.fn();
  renderizar(<AddFoodSheet aberta modo={modo} slot="cafe" dataChave="today" aoFechar={aoFechar} />, cliente);
  return { aoFechar };
}

describe('Adicionar alimento (S13a)', () => {
  it('recentes → busca → quantidade em ml → adicionar (CA33)', async () => {
    let corpo: unknown;
    server.use(http.post(url('/days/2026-09-28/meals/cafe/entries'), async ({ request }) => {
      corpo = await request.json();
      return HttpResponse.json({ data: diaApi() }, { status: 201 });
    }));
    const u = userEvent.setup();
    const { aoFechar } = abrir();
    const folha = await screen.findByRole('dialog', { name: 'Adicionar alimento' });

    expect(await within(folha).findByText('Você costuma comer')).toBeInTheDocument();
    await u.type(within(folha).getByRole('searchbox', { name: 'Buscar alimento' }), 'leite');
    await u.click(await within(folha).findByRole('option', { name: /Leite integral/ }));

    expect(within(folha).getByRole('heading', { name: 'Quanto você comeu?' })).toBeInTheDocument();
    await u.click(within(folha).getByRole('button', { name: '1 copo · 200 ml' }));
    expect(within(folha).getByText('122 kcal')).toBeInTheDocument();
    await u.click(within(folha).getByRole('button', { name: 'Adicionar' }));

    expect(corpo).toEqual({ entries: [{ food_id: 12, amount: 200 }] });
    expect(aoFechar).toHaveBeenCalled();
  });

  it('restrição aparece como aviso e não bloqueia (CA34)', async () => {
    server.use(http.get(url('/foods'), () => HttpResponse.json({ data: [leiteComLactose] })));
    const u = userEvent.setup();
    abrir();
    const folha = await screen.findByRole('dialog');
    await u.type(within(folha).getByRole('searchbox'), 'leite');
    expect(await within(folha).findByText('Intolerância a lactose')).toBeInTheDocument();
    await u.click(within(folha).getByRole('option', { name: /Leite integral/ }));
    expect(within(folha).getByText('Este alimento tem intolerância a lactose, que está nas suas restrições.')).toBeInTheDocument();
    await u.type(within(folha).getByRole('textbox', { name: 'Quantidade' }), '200');
    expect(within(folha).getByRole('button', { name: 'Adicionar' })).toBeEnabled();
  });

  it('sem resultado → cadastrar → quantidade (CA35)', async () => {
    server.use(
      http.get(url('/foods'), () => HttpResponse.json({ data: [] })),
      http.post(url('/custom-foods'), () => HttpResponse.json({ data: alimentosApi[2] }, { status: 201 })),
    );
    const u = userEvent.setup();
    abrir();
    const folha = await screen.findByRole('dialog');
    await u.type(within(folha).getByRole('searchbox'), 'barra de cereal');
    expect(await within(folha).findByText('Não achamos "barra de cereal".')).toBeInTheDocument();
    await u.click(within(folha).getByRole('button', { name: 'Cadastrar alimento' }));

    expect(within(folha).getByLabelText('Nome')).toHaveValue('barra de cereal');
    await u.type(within(folha).getByLabelText('Calorias'), '380');
    await u.type(within(folha).getByLabelText('Proteína'), '30');
    await u.type(within(folha).getByLabelText('Carboidrato'), '35');
    await u.type(within(folha).getByLabelText('Gordura'), '12');
    await u.click(within(folha).getByRole('button', { name: 'Salvar e continuar' }));

    expect(await within(folha).findByRole('heading', { name: 'Quanto você comeu?' })).toBeInTheDocument();
  });

  it('422 do cadastro aparece no campo (CA36)', async () => {
    server.use(
      http.get(url('/foods'), () => HttpResponse.json({ data: [] })),
      http.post(url('/custom-foods'), () => erroDaApi(422, 'VALIDATION_ERROR', 'Confira os campos destacados.', { 'per_100.calories': ['Os números não batem: confira as calorias.'] })),
    );
    const u = userEvent.setup();
    abrir();
    const folha = await screen.findByRole('dialog');
    await u.type(within(folha).getByRole('searchbox'), 'xx');
    await u.click(await within(folha).findByRole('button', { name: 'Cadastrar alimento' }));
    for (const [rotulo, v] of [['Calorias', '38'], ['Proteína', '30'], ['Carboidrato', '35'], ['Gordura', '12']] as const) {
      await u.type(within(folha).getByLabelText(rotulo), v);
    }
    await u.click(within(folha).getByRole('button', { name: 'Salvar e continuar' }));
    expect(await within(folha).findByText('Os números não batem: confira as calorias.')).toBeInTheDocument();
  });

  it('editar registro: salvar nova quantidade e remover (CA38)', async () => {
    const dia = diaApi({ feitas: ['cafe'] });
    const registro = camelizar<Dia>(dia).meals[0].entries[0];
    let patch: unknown;
    server.use(
      http.patch(url(`/days/2026-09-28/entries/${registro.id}`), async ({ request }) => { patch = await request.json(); return HttpResponse.json({ data: dia }); }),
    );
    const u = userEvent.setup();
    abrir({ tipo: 'editar', registro });
    const folha = await screen.findByRole('dialog', { name: 'Editar registro' });
    const campo = within(folha).getByRole('textbox', { name: 'Quantidade' });
    await u.clear(campo);
    await u.type(campo, '150,5');
    await u.click(within(folha).getByRole('button', { name: 'Salvar' }));
    expect(patch).toEqual({ amount: 150.5 });
  });
});
```

Se `erroDaApi` não aceitar o 4º argumento (`errors`), acrescente-o em `src/mocks/handlers/auth.ts` (parâmetro opcional que vai em `errors` do corpo).

- [ ] **Step 2: Ver falhar**

Run: `npx vitest run --project integration src/features/dia/components/AddFoodSheet.integration.test.tsx`
Expected: FAIL — componentes não existem.

- [ ] **Step 3: Implementar**

`AmountStep.tsx`:

```tsx
"use client";

import { useId, useState } from "react";
import { Aviso } from "@/components/ui/Aviso";
import { Button } from "@/components/ui/Button";
import { Chip } from "@/components/ui/Chip";
import { lerQuantidade, previa } from "../registro";
import type { Medida, Totais } from "../tipos";

const numero = (n: number) => (Number.isInteger(n) ? String(n) : String(n).replace(".", ","));

/** Passo "Quanto você comeu?" — número grande em g/ml, atalhos e prévia ao vivo (RF33/RF34). */
export function AmountStep({ nome, medida, per100, atalhos, conflitos, inicial, modo, salvando, aoConfirmar, aoRemover, aoVoltar, aoEditarAlimento }: {
  nome: string; medida: Medida; per100: Totais; atalhos: { rotulo: string; amount: number }[]; conflitos: string[];
  inicial?: number; modo: "adicionar" | "editar"; salvando: boolean;
  aoConfirmar: (amount: number) => void; aoRemover?: () => void; aoVoltar?: () => void; aoEditarAlimento?: () => void;
}) {
  const id = useId();
  const [texto, setTexto] = useState(inicial !== undefined ? numero(inicial) : "");
  const quantidade = lerQuantidade(texto);
  const p = previa(per100, quantidade ?? 0);
  const invalido = texto.trim() !== "" && quantidade === null;

  return (
    <div>
      <h3 className="font-display text-[22px] font-bold tracking-[-0.02em]">Quanto você comeu?</h3>
      <p className="mt-0.5 text-[13.5px] text-fumo">{nome}</p>
      {conflitos.length > 0 ? (
        <Aviso tom="alerta" className="mt-3">Este alimento tem {conflitos.join(", ").toLowerCase()}, que está nas suas restrições.</Aviso>
      ) : null}

      <label htmlFor={id} className="sr-only">Quantidade</label>
      <div className="mt-4 flex items-baseline gap-2 border-b-2 border-tinta pb-1 focus-within:border-mata">
        <input id={id} inputMode="decimal" autoComplete="off" value={texto} onChange={(e) => setTexto(e.target.value)}
          aria-invalid={invalido || undefined} aria-describedby={invalido ? `${id}-erro` : undefined}
          className="w-full bg-transparent font-display text-[44px] leading-none font-bold tracking-[-0.03em] outline-none" placeholder="0" />
        <span className="font-display text-xl font-semibold text-fumo">{medida}</span>
      </div>
      {invalido ? <p id={`${id}-erro`} className="mt-1.5 text-[12.5px] font-medium text-alerta">Use um número entre 0,1 e 2000, com até uma casa.</p> : null}

      {atalhos.length > 0 ? (
        <div className="mt-3 flex flex-wrap gap-2">
          {atalhos.map((a) => (
            <Chip key={a.amount} marcado={quantidade === a.amount} onClick={() => setTexto(numero(a.amount))}>{a.rotulo}</Chip>
          ))}
        </div>
      ) : null}

      <p className="mt-4 text-[13.5px] text-fumo" aria-live="polite">
        <span className="font-semibold text-tinta">{p.calories} kcal</span> · {numero(p.protein)} g de proteína · {numero(p.carbs)} g de carboidrato · {numero(p.fat)} g de gordura
      </p>

      <div className="mt-5 flex flex-col gap-2.5">
        <Button tamanho="grande" disabled={quantidade === null} carregando={salvando} onClick={() => quantidade !== null && aoConfirmar(quantidade)}>
          {modo === "adicionar" ? "Adicionar" : "Salvar"}
        </Button>
        {aoRemover ? <Button variante="destrutiva" tamanho="grande" onClick={aoRemover}>Remover</Button> : null}
        {aoEditarAlimento ? <Button variante="texto" onClick={aoEditarAlimento}>Editar alimento</Button> : null}
        {aoVoltar ? <Button variante="texto" onClick={aoVoltar}>Voltar para a busca</Button> : null}
      </div>
    </div>
  );
}
```

`FoodSearchStep.tsx`:

```tsx
"use client";

import Link from "next/link";
import { useId } from "react";
import { Selo } from "@/components/ui/Selo";
import { Skeleton } from "@/components/ui/Skeleton";
import type { AlimentoBusca } from "../tipos";

const porCem = (a: AlimentoBusca) => `${Math.round(a.per100.calories)} kcal em 100 ${a.measure}`;

/** Passo "Buscar" — campo com foco, recentes no vazio, selos de restrição e "Seu" (RF33). */
export function FoodSearchStep({ termo, aoMudarTermo, resultados, recentes, carregando, aoEscolher, aoEditarProprio, aoCadastrar, nutriHref }: {
  termo: string; aoMudarTermo: (t: string) => void; resultados: AlimentoBusca[] | undefined; recentes: AlimentoBusca[] | undefined;
  carregando: boolean; aoEscolher: (a: AlimentoBusca) => void; aoEditarProprio: (a: AlimentoBusca) => void; aoCadastrar: () => void; nutriHref: string;
}) {
  const id = useId();
  const buscando = termo.trim().length >= 2;
  const lista = buscando ? resultados : recentes;

  return (
    <div>
      <label htmlFor={id} className="sr-only">Buscar alimento</label>
      <input id={id} type="search" autoFocus value={termo} onChange={(e) => aoMudarTermo(e.target.value)} placeholder="Buscar alimento"
        className="h-12 w-full rounded-full border border-linha bg-papel px-5 text-[15px] outline-none focus:border-tinta" />

      {!buscando && lista && lista.length > 0 ? <h3 className="mt-4 text-[13px] font-semibold text-fumo">Você costuma comer</h3> : null}
      {carregando && buscando && !resultados ? (
        <div className="mt-3 space-y-2"><Skeleton className="h-12" /><Skeleton className="h-12" /><Skeleton className="h-12" /></div>
      ) : null}

      {lista && lista.length > 0 ? (
        <ul role="listbox" aria-label={buscando ? "Resultados" : "Você costuma comer"} className="mt-2 list-none">
          {lista.map((a) => (
            <li key={`${a.kind}-${a.id}`} className="flex items-center border-b border-fio last:border-b-0">
              <button type="button" role="option" aria-selected={false} onClick={() => aoEscolher(a)} className="min-w-0 flex-1 py-3 text-left">
                <span className="flex flex-wrap items-center gap-1.5">
                  <span className="text-[15px] font-semibold">{a.name}</span>
                  {a.kind === "custom" ? <Selo tom="mata">Seu</Selo> : null}
                  {a.conflicts.map((c) => <Selo key={c} tom="alerta">{c}</Selo>)}
                </span>
                <span className="block text-[12.5px] text-fumo">{porCem(a)}</span>
              </button>
              {a.kind === "custom" ? (
                <button type="button" onClick={() => aoEditarProprio(a)} className="h-11 px-2 text-[13px] font-semibold text-mata" aria-label={`Editar ${a.name}`}>Editar</button>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}

      {buscando && resultados && resultados.length === 0 ? (
        <div className="mt-5">
          <p className="text-[14px] font-medium">Não achamos &quot;{termo.trim()}&quot;.</p>
          <div className="mt-3 flex flex-col items-start gap-2">
            <button type="button" onClick={aoCadastrar} className="h-11 rounded-full bg-tinta px-5 text-sm font-semibold text-neve">Cadastrar alimento</button>
            <Link href={nutriHref} className="text-sm font-semibold text-mata">Perguntar ao Nutri o que mais se parece</Link>
          </div>
        </div>
      ) : null}

      {buscando && resultados && resultados.length > 0 ? (
        <button type="button" onClick={aoCadastrar} className="mt-3 text-[13px] font-semibold text-mata">Não está aqui? Cadastrar alimento</button>
      ) : null}
    </div>
  );
}
```

(O teste procura `role="option"` e `role="searchbox"`; `input type="search"` tem esse papel. Setas no `listbox`: acrescente um `onKeyDown` no `ul` que move o foco entre os `button[role=option]` com ArrowDown/ArrowUp — teste na story `FoodSearchStep.Teclado`.)

`CustomFoodForm.tsx`:

```tsx
"use client";

import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Field, Segmento } from "@/components/ui/Field";
import type { AlimentoProprioDados, Medida } from "../tipos";

const num = (t: string) => Number(t.trim().replace(",", "."));

/** RF35/RF37 — cadastrar ou editar alimento próprio, valores por 100 g/ml. */
export function CustomFoodForm({ inicial, salvando, erros, aoSalvar, aoApagar, aoVoltar }: {
  inicial?: Partial<AlimentoProprioDados> & { id?: number }; salvando: boolean; erros: Record<string, string>;
  aoSalvar: (d: AlimentoProprioDados) => void; aoApagar?: () => void; aoVoltar: () => void;
}) {
  const [nome, setNome] = useState(inicial?.name ?? "");
  const [medida, setMedida] = useState<Medida>(inicial?.measure ?? "g");
  const p = inicial?.per100;
  const [v, setV] = useState({ calories: p ? String(p.calories) : "", protein: p ? String(p.protein) : "", carbs: p ? String(p.carbs) : "", fat: p ? String(p.fat) : "" });
  const [confirmando, setConfirmando] = useState(false);
  const campo = (k: keyof typeof v, rotulo: string, sufixo: string) => (
    <Field id={`proprio-${k}`} label={rotulo} sufixo={sufixo} erro={erros[`per100.${k}`]} inputMode="decimal" value={v[k]} onChange={(e) => setV({ ...v, [k]: e.target.value })} />
  );

  return (
    <form noValidate onSubmit={(e) => { e.preventDefault(); aoSalvar({ name: nome, measure: medida, per100: { calories: num(v.calories), protein: num(v.protein), carbs: num(v.carbs), fat: num(v.fat) } }); }}>
      <h3 className="font-display text-[22px] font-bold tracking-[-0.02em]">{inicial?.id ? "Editar alimento" : "Cadastrar alimento"}</h3>
      <p className="mt-0.5 text-[13px] text-fumo">Copie da tabela nutricional da embalagem.</p>
      <div className="mt-4 flex flex-col gap-3.5">
        <Field id="proprio-nome" label="Nome" erro={erros.name} value={nome} onChange={(e) => setNome(e.target.value)} />
        <Segmento label="Medida" opcoes={[{ valor: "g", rotulo: "Sólido (g)" }, { valor: "ml", rotulo: "Líquido (ml)" }]} valor={medida} onChange={setMedida} erro={erros.measure} />
        <p className="text-[12.5px] font-semibold text-fumo">Em 100 {medida}</p>
        {campo("calories", "Calorias", "kcal")}
        {erros.per100 ? <p className="text-[12.5px] font-medium text-alerta">{erros.per100}</p> : null}
        {campo("protein", "Proteína", "g")}
        {campo("carbs", "Carboidrato", "g")}
        {campo("fat", "Gordura", "g")}
      </div>
      <div className="mt-5 flex flex-col gap-2.5">
        <Button type="submit" tamanho="grande" carregando={salvando}>{inicial?.id ? "Salvar" : "Salvar e continuar"}</Button>
        {aoApagar ? (
          confirmando ? (
            <div className="rounded-2xl bg-alerta-fraca p-4">
              <p className="text-[13.5px] text-alerta-texto">Apagar {nome}? O que você já registrou com ele continua no histórico.</p>
              <div className="mt-3 flex gap-2">
                <Button variante="destrutiva" tamanho="media" onClick={aoApagar}>Apagar</Button>
                <Button variante="texto" tamanho="media" onClick={() => setConfirmando(false)}>Cancelar</Button>
              </div>
            </div>
          ) : (
            <Button variante="texto" onClick={() => setConfirmando(true)}>Apagar alimento</Button>
          )
        ) : null}
        <Button variante="texto" onClick={aoVoltar}>Voltar</Button>
      </div>
    </form>
  );
}
```

Confira a API de `Field`/`Segmento` em `src/components/ui/Field.tsx` (props `id`, `label`, `sufixo`, `erro` e repasse de atributos do `input`); ajuste se `Field` não repassar `inputMode`/`value`/`onChange`.

`AddFoodSheet.tsx` (contêiner):

```tsx
"use client";

import { useState } from "react";
import { Sheet } from "@/components/ui/Sheet";
import { comoApiError } from "@/lib/api/errors";
import { useApagarAlimentoProprio, useBuscaAlimentos, useCriarAlimentoProprio, useEditarAlimentoProprio, useEditarRegistro, useRecentes, useRegistrar, useRemoverRegistro } from "../hooks";
import { atalhosDeQuantidade, per100DoRegistro } from "../registro";
import type { AlimentoBusca, AlimentoProprioDados, Registro, Slot } from "../tipos";
import { AmountStep } from "./AmountStep";
import { CustomFoodForm } from "./CustomFoodForm";
import { FoodSearchStep } from "./FoodSearchStep";

type Passo = { tipo: "buscar" } | { tipo: "quantidade"; alimento: AlimentoBusca } | { tipo: "proprio"; alimento?: AlimentoBusca };

/** S13a — buscar → quantidade → adicionar; cadastrar/editar alimento próprio; editar/remover registro. */
export function AddFoodSheet({ aberta, modo, slot, dataChave, aoFechar }: {
  aberta: boolean; modo: { tipo: "novo" } | { tipo: "editar"; registro: Registro }; slot: Slot; dataChave: string; aoFechar: () => void;
}) {
  const [passo, setPasso] = useState<Passo>({ tipo: "buscar" });
  const [termo, setTermo] = useState("");
  const [erros, setErros] = useState<Record<string, string>>({});
  const busca = useBuscaAlimentos(termo);
  const recentes = useRecentes(aberta && modo.tipo === "novo");
  const registrar = useRegistrar(dataChave);
  const editar = useEditarRegistro(dataChave);
  const remover = useRemoverRegistro(dataChave);
  const criar = useCriarAlimentoProprio();
  const editarProprio = useEditarAlimentoProprio();
  const apagarProprio = useApagarAlimentoProprio();

  function fechar() {
    setPasso({ tipo: "buscar" });
    setTermo("");
    setErros({});
    aoFechar();
  }

  function errosDe(e: unknown) {
    const erro = comoApiError(e);
    setErros(Object.fromEntries(Object.entries(erro.fields).map(([k, v]) => [k.replace("per_100", "per100"), v[0]])));
  }

  async function salvarProprio(d: AlimentoProprioDados, alimento?: AlimentoBusca) {
    try {
      const salvo = alimento
        ? ((await editarProprio.mutateAsync({ id: alimento.id, dados: d })) as AlimentoBusca)
        : ((await criar.mutateAsync(d)) as AlimentoBusca);
      setErros({});
      setPasso({ tipo: "quantidade", alimento: salvo });
    } catch (e) {
      errosDe(e);
    }
  }

  let titulo = "Adicionar alimento";
  let conteudo: React.ReactNode;
  if (modo.tipo === "editar") {
    const r = modo.registro;
    titulo = "Editar registro";
    conteudo = (
      <AmountStep nome={r.name} medida={r.measure} per100={per100DoRegistro(r)} atalhos={[]} conflitos={r.conflicts} inicial={r.amount} modo="editar"
        salvando={editar.isPending}
        aoConfirmar={(amount) => editar.mutate({ id: r.id, amount }, { onSuccess: fechar })}
        aoRemover={() => remover.mutate({ registro: r, slot }, { onSuccess: fechar })} />
    );
  } else if (passo.tipo === "quantidade") {
    const a = passo.alimento;
    conteudo = (
      <AmountStep nome={a.name} medida={a.measure} per100={a.per100} atalhos={atalhosDeQuantidade(a)} conflitos={a.conflicts} modo="adicionar"
        salvando={registrar.isPending}
        aoConfirmar={(amount) => registrar.mutate({ slot, entries: [a.kind === "custom" ? { customFoodId: a.id, amount } : { foodId: a.id, amount }] }, { onSuccess: fechar })}
        aoVoltar={() => setPasso({ tipo: "buscar" })}
        aoEditarAlimento={a.kind === "custom" ? () => setPasso({ tipo: "proprio", alimento: a }) : undefined} />
    );
  } else if (passo.tipo === "proprio") {
    const a = passo.alimento;
    conteudo = (
      <CustomFoodForm
        inicial={a ? { id: a.id, name: a.name, measure: a.measure, per100: a.per100 } : { name: termo.trim() }}
        salvando={criar.isPending || editarProprio.isPending} erros={erros}
        aoSalvar={(d) => void salvarProprio(d, a)}
        aoApagar={a ? () => apagarProprio.mutate(a.id, { onSuccess: () => setPasso({ tipo: "buscar" }) }) : undefined}
        aoVoltar={() => setPasso({ tipo: "buscar" })} />
    );
  } else {
    conteudo = (
      <FoodSearchStep termo={termo} aoMudarTermo={setTermo} resultados={busca.data} recentes={recentes.data} carregando={busca.isFetching}
        aoEscolher={(a) => setPasso({ tipo: "quantidade", alimento: a })}
        aoEditarProprio={(a) => setPasso({ tipo: "proprio", alimento: a })}
        aoCadastrar={() => setPasso({ tipo: "proprio" })}
        nutriHref={`/nutri?pergunta=${encodeURIComponent(`Comi ${termo.trim()} e não achei no app. O que mais se parece?`)}`} />
    );
  }

  return <Sheet aberta={aberta} aoFechar={fechar} titulo={titulo}>{conteudo}</Sheet>;
}
```

Confira em `src/lib/api/errors.ts` o nome do campo com os erros por campo (`fields` acima; o construtor recebe `erro.errors`). Ajuste o nome se for outro.

Stories (uma por passo) com `play` cobrindo: `AmountStep` — Adicionar (atalho preenche, prévia muda, "Adicionar" chama com o número), Ml, AvisoRestricao, Invalido ("0" desabilita e mostra a mensagem), Edicao (Salvar/Remover); `FoodSearchStep` — Recentes, Resultados (selos "Seu" e de restrição), SemResultado (botões), Teclado (ArrowDown move o foco); `CustomFoodForm` — Novo, ComErros (mensagem por campo), EditarComApagar (confirmação aparece; "Apagar" chama).

- [ ] **Step 4: Ver passar**

Run: `npx vitest run --project integration src/features/dia/components/AddFoodSheet.integration.test.tsx && npx vitest run --project storybook src/features/dia/components`
Expected: PASS, axe limpo.

- [ ] **Step 5: Commit**

```bash
git add src/features/dia/components src/mocks
git commit -m "feat(registro): folha Adicionar alimento com busca, quantidade e alimento próprio (S13a)"
```

---

### Task 6: Tela da refeição reescrita (S13) — hoje e ontem

**Files:**
- Modify: `src/features/dia/components/DetalheTela.tsx`, `src/app/(app)/dieta/[refeicao]/page.tsx`
- Test: `src/features/dia/components/DetalheTela.integration.test.tsx` (reescrito)

**Interfaces:**
- Consumes: Tasks 2–5.
- Produces: `DetalheTela({ slot: string; data?: string })` — `data` = ISO de ontem vinda de `?data=`; sem ela, `today`.

- [ ] **Step 1: Teste que falha** (substitui os casos de "Marcar como feita"; mantém os de troca/desfazer/409 ajustando os nomes dos botões)

```tsx
describe('Detalhe da refeição (S13, spec 09)', () => {
  it('"+" registra na hora, vira ✓ e a régua sobe (CA31, Review Focus 1)', async () => {
    let pedidos = 0;
    server.use(http.post(url('/days/2026-09-28/meals/almoco/entries'), async () => {
      pedidos++;
      await delay(100);
      const dia = diaApi({ registros: { almoco: [registroDaSugestao(refeicaoApi('almoco', 2, false, false).items[0])] } });
      return HttpResponse.json({ data: dia }, { status: 201 });
    }));
    const u = userEvent.setup();
    renderizar(<DetalheTela slot="almoco" />);

    const mais = await screen.findByRole('button', { name: 'Registrar Arroz branco cozido, 150 g, mais ou menos 6 colheres de sopa' });
    await u.click(mais);
    expect(await screen.findByRole('button', { name: 'Arroz branco cozido já registrado' })).toBeDisabled();
    expect(screen.getByRole('meter')).toHaveAttribute('aria-valuenow', '192');
    await waitFor(() => expect(pedidos).toBe(1));
  });

  it('"Adicionar os 4" manda todos numa requisição (CA32)', async () => {
    let corpo: unknown;
    server.use(http.post(url('/days/2026-09-28/meals/almoco/entries'), async ({ request }) => {
      corpo = await request.json();
      return HttpResponse.json({ data: diaApi({ feitas: ['almoco'] }) }, { status: 201 });
    }));
    const u = userEvent.setup();
    renderizar(<DetalheTela slot="almoco" />);
    await u.click(await screen.findByRole('button', { name: 'Adicionar os 4' }));
    expect(corpo).toEqual({ entries: [5020, 5021, 5022, 5023].map((id) => ({ suggestion_item_id: id })) });
  });

  it('não tem mais "Marcar como feita"', async () => {
    renderizar(<DetalheTela slot="almoco" />);
    await screen.findByRole('heading', { name: 'Almoço' });
    expect(screen.queryByRole('button', { name: /Marcar como feita|Desmarcar refeição/ })).toBeNull();
  });

  it('ontem: título, sem "Trocar", escreve na data de ontem (CA39)', async () => {
    let caminho = '';
    server.use(
      http.get(url('/days/2026-09-27'), () => HttpResponse.json({ data: diaApi({ data: '2026-09-27', hoje: false, editavel: true }) })),
      http.post(url('/days/2026-09-27/meals/jantar/entries'), ({ request }) => {
        caminho = new URL(request.url).pathname;
        return HttpResponse.json({ data: diaApi({ data: '2026-09-27', hoje: false, editavel: true, feitas: ['jantar'] }) }, { status: 201 });
      }),
    );
    const u = userEvent.setup();
    renderizar(<DetalheTela slot="jantar" data="2026-09-27" />);
    expect(await screen.findByText(/^Ontem às 20:30/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Trocar/ })).toBeNull();
    await u.click(screen.getByRole('button', { name: /^Registrar Patinho moído/ }));
    await waitFor(() => expect(caminho).toBe('/api/v1/days/2026-09-27/meals/jantar/entries'));
  });

  it('dia virou: DAY_NOT_EDITABLE avisa e recarrega (Review Focus 4)', async () => {
    server.use(http.post(url('/days/2026-09-28/meals/almoco/entries'), () => erroDaApi(409, 'DAY_NOT_EDITABLE', 'Esse dia não pode mais ser alterado.')));
    const u = userEvent.setup();
    renderizar(<DetalheTela slot="almoco" />);
    await u.click(await screen.findByRole('button', { name: /^Registrar Arroz/ }));
    expect(await screen.findByText('O dia virou. Atualizamos para hoje.')).toBeInTheDocument();
  });

  it('tocar num registro abre a edição', async () => {
    server.use(respondendoDia(diaApi({ feitas: ['almoco'] })));
    const u = userEvent.setup();
    renderizar(<DetalheTela slot="almoco" />);
    await u.click(await screen.findByRole('button', { name: /^Arroz branco cozido, 150 g/ }));
    expect(await screen.findByRole('dialog', { name: 'Editar registro' })).toBeInTheDocument();
  });
});
```

(Imports: `waitFor`, `delay` de `msw`, `registroDaSugestao`, `refeicaoApi`, `respondendoDia`.)

- [ ] **Step 2: Ver falhar**

Run: `npx vitest run --project integration src/features/dia/components/DetalheTela.integration.test.tsx`
Expected: FAIL.

- [ ] **Step 3: Implementar**

Em `page.tsx`:

```tsx
"use client";

import { useParams, useSearchParams } from "next/navigation";
import { DetalheTela } from "@/features/dia/components/DetalheTela";

export default function DetalheRefeicao() {
  const { refeicao } = useParams<{ refeicao: string }>();
  const data = useSearchParams().get("data") ?? undefined;
  return <DetalheTela slot={refeicao} data={data} />;
}
```

(Se o build reclamar de `useSearchParams` sem `Suspense`, o layout `(app)` já envolve em `Suspense` — confira; senão, envolva aqui.)

Em `DetalheTela.tsx`, mantenha os estados de carregando/erro/sem plano/refeição não encontrada (trocando `useDia()` por `useDia(chave)` com `const chave = data ?? "today"`), e troque o corpo de sucesso por:

```tsx
  const { editable, isToday } = dia.data;
  const meta = metaDaRefeicao(refeicao);
  const proteico = [...refeicao.items].sort((a, b) => b.macros.protein - a.macros.protein)[0];
  const quando = `${isToday ? "Hoje" : "Ontem"} às ${refeicao.time}${refeicao.note ? `, ${refeicao.note.toLowerCase()}` : ""}`;
  const registrarItens = (itens: ItemDoDia[]) =>
    registrar.mutate({ slot: refeicao.slot, entries: itens.map((i) => ({ suggestionItemId: i.id as number })) });

  return (
    <Screen>
      <TopBar voltarPara="/dieta" rotuloVoltar="Voltar para a dieta"
        direita={refeicao.goalMet ? <Selo tom="mata" icone={<IconeCheck size={12} strokeWidth={2.4} />}>Meta batida</Selo>
          : refeicao.isNext ? <Selo tom="gema" pulsante>Próxima refeição</Selo> : null} />
      <AvisoDeAlteracao alteracao={isToday ? dia.data.lastChange : null} />

      <main className="flex-1 px-5 pt-3 pb-4">
        <h1 className="animate-entra font-display text-[34px] leading-[1.05] font-bold tracking-[-0.03em]">{refeicao.name}</h1>
        <p className="mt-1.5 mb-4 animate-entra text-[13.5px] text-fumo" style={{ animationDelay: "80ms" }}>{quando}</p>

        <MealGoal meta={meta} consumido={refeicao.consumed} temRegistro={refeicao.entries.length > 0} />

        <EntryList registros={refeicao.entries}
          aoAbrir={editable ? (r) => setFolha({ tipo: "editar", registro: r }) : undefined}
          aoAdicionar={editable ? () => setFolha({ tipo: "novo" }) : undefined} />

        <SuggestionList itens={refeicao.items} ocupado={registrando}
          aoRegistrar={editable ? (i) => registrarItens([i]) : undefined}
          aoRegistrarTodos={editable ? registrarItens : undefined}
          aoTrocar={editable && isToday ? (i) => setAlvo(i) : undefined} />
      </main>

      {proteico && editable ? (
        <footer className="shrink-0 px-5 pt-2 pb-seguro-7">
          <NutriBar href={`/nutri?pergunta=${encodeURIComponent(`Não tenho ${proteico.name.toLowerCase()} em casa. O que uso no lugar?`)}`}
            texto={`Não tenho ${proteico.name.toLowerCase()} em casa`} />
        </footer>
      ) : null}

      <SubstitutionSheet …como antes… />
      <AddFoodSheet aberta={folha !== null} modo={folha ?? { tipo: "novo" }} slot={refeicao.slot} dataChave={chave} aoFechar={() => setFolha(null)} />
    </Screen>
  );
```

Estado: `const [folha, setFolha] = useState<{ tipo: "novo" } | { tipo: "editar"; registro: Registro } | null>(null);`, `const registrar = useRegistrar(chave); const registrando = useRegistrando();`. Troca e desfazer continuam com `useTrocarItem()`/`useDesfazer()` (só hoje). Remova os imports de `RailSimples`, `Button`, `CountUp`, `FoodItemRow`, `porcentagem`, `gramas` que ficarem sem uso. Atualize o comentário do componente: `/** S13 (spec 09) — o que eu comi × a sugestão: régua da meta, registros, sugestão com +, trocas (só hoje) e desfazer. Hoje ou ontem. */`.

- [ ] **Step 4: Ver passar**

Run: `npx vitest run --project integration src/features/dia`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/features/dia src/app
git commit -m "feat(registro): tela da refeição com meta, registros e sugestão; ontem editável (S13)"
```

---

### Task 7: Hoje e Dieta sem "marcar"; ontem tocável (S11, S12)

**Files:**
- Modify: `src/features/dia/components/DayRail.tsx` (+ stories), `MealRow.tsx` (+ stories), `DietaTela.tsx`, `HojeTela.tsx`
- Test: `HojeTela.integration.test.tsx`, `DietaTela.integration.test.tsx` (ajustes)

**Interfaces:**
- Produces: `DayRail({ refeicoes: RefeicaoDoDia[] })` (sem `aoAlternar`/`ocupado`); `MealRow({ refeicao; indice; clicavel; href: string; mostrarRegistro: boolean })`.

- [ ] **Step 1: Ajustar os testes primeiro**

- `DayRail.stories.tsx`: remova os `play` que clicam no marcador; acrescente `ProximaComRegistro`: a refeição da vez com `consumed.calories = 288` mostra "288 de 450 kcal" e o link "Registrar refeição" com `href="/dieta/{slot}"`; `TudoFeito` mostra ✓ em todas.
- `MealRow.stories.tsx`: `Ontem` (clicável, `href` com `?data=`), `ComRegistro` ("288 de 450 kcal"), `Futuro` (só a meta).
- `HojeTela.integration.test.tsx`: troque o caso de marcar/desmarcar por "não há botão de marcar; 'Registrar refeição' leva ao detalhe".
- `DietaTela.integration.test.tsx`: novo caso "ontem é tocável e abre `/dieta/{slot}?data=2026-09-27`" (resposta de `/days/2026-09-27` com `editable: true`) e "antes de ontem não é tocável".

Run: `npx vitest run --project integration src/features/dia && npx vitest run --project storybook src/features/dia/components/DayRail.stories.tsx src/features/dia/components/MealRow.stories.tsx`
Expected: FAIL nos casos novos.

- [ ] **Step 2: Implementar**

`DayRail`: remova `aoAlternar`, `ocupado` e o botão de check de `ProximaRefeicao`; no lugar dos números da refeição da vez:

```tsx
        <div className="mt-3 flex gap-4 border-t border-fio pt-2.5">
          <span className="text-[13px] font-semibold">
            {Math.round(refeicao.consumed.calories)} <span className="font-medium text-fumo">de {Math.round(refeicao.calories)} kcal</span>
          </span>
        </div>
        <Link href={`/dieta/${refeicao.slot}`} className="… mesmas classes do antigo 'Ver refeição' … mt-3 flex h-11 w-full …">
          <span className="relative">Registrar refeição</span>
        </Link>
```

Nas linhas compactas, mostre `kcal(refeicao.done ? refeicao.consumed.calories : refeicao.calories)`. Atualize o docblock: `Responde "o que eu preciso registrar hoje?"`.

`MealRow`: receba `href` e `mostrarRegistro`; o kcal à direita vira `mostrarRegistro ? \`${consumido} de ${meta} kcal\` : kcal(meta)`.

`DietaTela`: `const clicavel = dia.data.editable;` e `href = isToday ? \`/dieta/${slot}\` : \`/dieta/${slot}?data=${date}\``; `mostrarRegistro = editable || date < hoje`. Troque o rodapé pelo texto da spec: "Registre o que você comeu. A sugestão de cada refeição é um atalho para bater a meta." O `feitasAte` (linha verde) continua usando `done`.

`HojeTela`: `<DayRail refeicoes={meals} />` (sem marcar); remova imports mortos.

- [ ] **Step 3: Ver passar**

Run: `npx vitest run --project integration src/features && npx vitest run --project storybook src/features/dia && npm run lint && npm run typecheck`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add src/features/dia
git commit -m "feat(registro): Hoje e Dieta sem marcar como feita; ontem tocável (S11, S12)"
```

---

### Task 8: E2E e seeder E2E (E2E-04 reescrito, E2E-08, E2E-09)

**Files:**
- Modify: `e2e/dia.spec.ts`; no backend, `database/seeders/E2ESeeder.php` (contas `registro-{navegador}` e `proprio-{navegador}`, plano pronto)
- Create: `e2e/registro.spec.ts`

- [ ] **Step 1: Testes**

Em `e2e/dia.spec.ts`, substitua o E2E-04:

```ts
test('registrar pela sugestão e pela busca persiste ao recarregar (E2E-04, CA31, CA33)', async ({ page, browserName }) => {
  await entrar(page, conta('dia', browserName));
  await expect(page).toHaveURL(/\/hoje$/);
  await page.goto('/dieta/cafe');

  const mais = page.getByRole('button', { name: /^Registrar / }).first();
  const nome = (await mais.getAttribute('aria-label'))!.replace(/^Registrar /, '').split(',')[0];
  await mais.click();
  await expect(page.getByRole('button', { name: `${nome} já registrado` })).toBeVisible();

  await page.getByRole('button', { name: 'Adicionar alimento' }).click();
  const folha = page.getByRole('dialog', { name: 'Adicionar alimento' });
  await folha.getByRole('searchbox', { name: 'Buscar alimento' }).fill('leite');
  await folha.getByRole('option', { name: /Leite integral/ }).click();
  await folha.getByRole('textbox', { name: 'Quantidade' }).fill('200');
  await folha.getByRole('button', { name: 'Adicionar' }).click();

  await page.reload();
  await expect(page.getByRole('button', { name: `${nome} já registrado` })).toBeVisible();
  await expect(page.getByRole('button', { name: /^Leite integral, 200 ml/ })).toBeVisible();

  // limpa para a próxima execução
  for (const registro of await page.getByRole('button', { name: /\. Editar$/ }).all()) {
    await registro.click();
    await page.getByRole('dialog').getByRole('button', { name: 'Remover' }).click();
  }
});
```

E2E-05/E2E-06: troque o seletor da lista do prato (`getByRole('list').filter({ has: … 'Trocar' })`) por `page.getByRole('heading', { name: 'Sugestão para bater a meta' }).locator('..')`.

`e2e/registro.spec.ts`:

```ts
import { expect, test } from '@playwright/test';
import { conta, entrar } from './contas';

test('cadastrar alimento próprio e registrar; outra conta não vê (E2E-08, CA35)', async ({ page, browserName, browser }) => {
  const nome = `Barra caseira ${browserName} ${Date.now()}`;
  await entrar(page, conta('proprio', browserName));
  await page.goto('/dieta/lanche');
  await page.getByRole('button', { name: 'Adicionar alimento' }).click();
  const folha = page.getByRole('dialog');
  await folha.getByRole('searchbox').fill(nome);
  await folha.getByRole('button', { name: 'Cadastrar alimento' }).click();
  for (const [rotulo, v] of [['Calorias', '380'], ['Proteína', '30'], ['Carboidrato', '35'], ['Gordura', '12']]) {
    await folha.getByLabel(rotulo).fill(v);
  }
  await folha.getByRole('button', { name: 'Salvar e continuar' }).click();
  await folha.getByRole('textbox', { name: 'Quantidade' }).fill('40');
  await folha.getByRole('button', { name: 'Adicionar' }).click();
  await expect(page.getByRole('button', { name: new RegExp(`^${nome}, 40 g`) })).toContainText('152 kcal');

  const outra = await (await browser.newContext()).newPage();
  await entrar(outra, conta('dia', browserName));
  await outra.goto('/dieta/lanche');
  await outra.getByRole('button', { name: 'Adicionar alimento' }).click();
  await outra.getByRole('dialog').getByRole('searchbox').fill(nome);
  await expect(outra.getByRole('dialog').getByText(`Não achamos "${nome}".`)).toBeVisible();
});

test('registrar o jantar de ontem pela Dieta (E2E-09, CA39)', async ({ page, browserName }) => {
  await entrar(page, conta('registro', browserName));
  await page.goto('/dieta');
  const dias = page.getByRole('tab');
  const hoje = await dias.evaluateAll((els) => els.findIndex((e) => e.getAttribute('aria-selected') === 'true'));
  test.skip(hoje === 0, 'Segunda-feira: ontem está na semana anterior, fora da faixa.');
  await dias.nth(hoje - 1).click();
  await page.getByRole('link', { name: /Jantar/ }).click();
  await expect(page).toHaveURL(/\/dieta\/jantar\?data=\d{4}-\d{2}-\d{2}$/);
  await expect(page.getByText(/^Ontem às/)).toBeVisible();
  await page.getByRole('button', { name: /^Registrar / }).first().click();
  await page.reload();
  await expect(page.getByRole('button', { name: /já registrado$/ }).first()).toBeVisible();
});
```

No backend (`database/seeders/E2ESeeder.php`), crie as contas `proprio-{chromium,webkit}@e2e.pratoforte.test` e `registro-{chromium,webkit}@e2e.pratoforte.test` como as contas `dia-*` existentes (onboarding concluído + plano pronto). Acrescente as duas a `e2e/contas.ts` no padrão de `conta(tipo, navegador)`.

- [ ] **Step 2: Rodar o E2E (com o backend do 11A + 11B semeado)**

No backend: recrie os contêineres com `AI_FAKE_FAIL_PLAN_FOR=falha-chromium@e2e.pratoforte.test,falha-webkit@e2e.pratoforte.test` exportado e rode `migrate:fresh --seeder=E2ESeeder`. No front, com a porta 3000 livre (sem `next dev` rodando): `npx playwright test`.
Expected: todos verdes em Chromium e WebKit (E2E-09 pode ser `skipped` numa segunda-feira).

- [ ] **Step 3: Verificação geral e screenshots**

Run: `npm run lint && npm run typecheck && npm test && npm run build`
Expected: verde. Tire screenshots (390 px) do Detalhe: sem registro, abaixo da meta, meta batida, acima da meta, folha na busca, folha na quantidade, cadastro — e confira contra o desenho da spec 09 S13 (régua é o destaque; sugestão secundária; nada colado nas bordas).

- [ ] **Step 4: DoD da spec 09**

Marque `[x]` em: telas; componentes com stories; E2E. Rode `git -C ../backend add specs/09-registro-alimentar/spec.md`.

- [ ] **Step 5: Commit**

```bash
git add e2e src
git commit -m "test(e2e): registro pela sugestão e pela busca, alimento próprio e ontem (E2E-04, 08, 09)"
git -C ../backend add database/seeders/E2ESeeder.php specs/09-registro-alimentar/spec.md
git -C ../backend commit -m "test(e2e): contas para registro e alimento próprio; DoD da spec 09"
```
