# Plano 06B — Evolução: telas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ligar a Evolução (`/evolucao`) e o Registrar peso (`/evolucao/peso`) à API do Plano 06A — gráfico com meta e previsão, constância, médias reais, stepper de peso com mensagem por objetivo — e tirar o protótipo (`usePlan`) dessas telas.

**Architecture:** `src/features/progresso`: tipos, chamadas (`lib/api/progresso.ts`), regras puras (mensagem da diferença, régua, textos da previsão e da variação, histórico), hooks (TanStack Query + período lembrado com `useSyncExternalStore`), componentes apresentacionais com stories extraídos do protótipo (`WeightChart`, `EvolucaoVazia`, `AdherenceGrid`, `MediasDoPeriodo`, `WeightStepper`, `WeightDeltaMessage`, `HistoricoPesagens`) e dois contêineres (`EvolucaoTela`, `RegistrarPesoTela`). Salvar invalida progresso, pesagens e perfil (CA08: Hoje e Perfil mostram o peso novo).

**Tech Stack:** Next 16.3.5, React 19.2, TanStack Query 5, Storybook 10.6, Vitest 4.1, MSW 2, Playwright 1.63 (repo front); backend só para o E2E (contas `peso-*` do 06A).

**Spec:** `specs/05-evolucao/spec.md` (§2 RF23–RF25, §3, §4 S15/S16, §7, §8, §9), `specs/00-fundacao/regras-de-negocio.md` (RN34–RN37), `specs/08-design-system/spec.md`. Contrato: Plano 06A (`GET/POST /weigh-ins`, `GET /progress?period=`).

**Onde rodar:** front em `/home/alvez/atividade-extensionista/frontend` (`docker compose run --rm web …`), branch `plano-06b-evolucao-telas` saindo de `plano-05b-nutri-telas`. E2E com o backend no ar na branch `plano-06a-evolucao-api` (`docker compose up -d` e `migrate:fresh --seeder=E2ESeeder`).

## Decisões deste plano (rulings sobre a spec)

1. **Unidades imperiais (RN39) ficam para o Plano 07**, que cria `lib/units.ts` e a preferência; aqui tudo em kg. A story `Imperial` do `WeightStepper` entra lá.
2. **Comparação sempre "com a última pesagem"** (a tabela da spec mistura "semana passada" e "última pesagem"); o texto "igual" continua o da spec: "Mesmo peso da semana passada. Uma semana estável é normal." quando a última pesagem tem ≥ 5 dias; "Mesmo peso da última pesagem." quando é mais recente.
3. **Texto da previsão:** com `forecast` ⇒ "No ritmo das últimas semanas, você chega na meta por volta do {label}."; com meta e menos de 3 pesagens ou menos de 14 dias ⇒ "Registre mais uma pesagem para estimar quando você chega na meta." (CA01); com meta e sem previsão ⇒ "Com as pesagens de agora, a linha ainda não aponta para a meta."; sem meta ⇒ nada.
4. **Variação:** "+1,6 kg em 5 semanas" (`span_weeks` da API); 1 semana ⇒ "em 1 semana"; 0 semanas com uma pesagem ⇒ "primeira pesagem"; 0 semanas com mais de uma ⇒ "nesta semana".
5. **Rótulos de data do gráfico:** no máximo 6, espalhados (período "Tudo" pode ter dezenas de pontos).
6. **Período lembrado** em `localStorage` (`pf:periodo-evolucao`) com `useSyncExternalStore` (servidor: `6w`), leituras em `try/catch`.
7. **Digitar o peso** (🟡): tocar no número abre um campo `inputMode="decimal"`; aceita vírgula; ao sair/Enter, arredonda a 0,1 e limita a 30–250.
8. **Médias sem meta de plano** (`target_*` nulo): a barra usa a própria média como meta (cheia) e o texto "sem meta" some — não acontece com plano ativo.

## Global Constraints

- Textos exatos: "Sua evolução", "Período", "6 semanas", "3 meses", "Tudo", "Constância", "últimos 28 dias", "{n} dias com todas as refeições feitas." (`{n}` em negrito), "Sua sequência atual é de {n} dias." (só se > 1), "Dia completo", "Parte das refeições", "Hoje", "Média por dia neste período", "Proteína", "Calorias", "Marque suas refeições para ver suas médias aqui.", "Registrar peso da semana", "Sua linha começa na primeira pesagem", "Registrar meu peso", "Não foi possível carregar sua evolução", "Quanto a balança marcou?", "Suas pesagens", "início", "Salvar peso de hoje", "Salvando", "Pese-se de manhã, antes de comer, sempre na mesma balança.", "Você já registrou hoje. Salvar vai atualizar o valor.", "Diminuir 100 gramas", "Aumentar 100 gramas".
- Mensagens por objetivo exatamente como a tabela do §4 S16 da spec (com a Decisão 2).
- Gráfico `role="img"` com `aria-label` descritivo; cada quadrado da constância com rótulo "21 de setembro: dia completo" / "…: parte das refeições" / "…: nenhuma refeição feita" / "…: hoje".
- Nenhum valor fixo do mock nas telas (médias, "fim de janeiro", "em {n} semanas").
- Animações só transform/opacity/stroke; `prefers-reduced-motion` respeitado (o CSS global já zera; o traço do gráfico aparece desenhado).
- D11: telas com `frontend-design`, identidade do mock.

## Review Focus

1. **Duplo toque em "Salvar peso de hoje"** → uma requisição só (botão `carregando`) (Task 5).
2. **Salvar e voltar**: a Evolução, o card de Hoje e o Perfil mostram o peso novo sem recarregar (Task 5, invalida `progresso`, `pesagens`, `perfil`; E2E-09 confere Hoje).
3. **Digitar "58,65", "abc", "20" ou apagar tudo** → valor fica 58,7 / o anterior / 30,0 / o anterior (Task 3, `lerPesoDigitado`).
4. **Período "Tudo" com 60 pesagens** → no máximo 6 rótulos de data, gráfico não estoura (Task 2).
5. **`localStorage` bloqueado (aba anônima)** → a tela abre em "6 semanas" sem erro (Task 1, `usePeriodo`).

---

### Task 1: Tipos, chamadas, regras, hooks e MSW da Evolução

**Files (repo front):**
- Create: `src/features/progresso/{tipos,regras,hooks}.ts`, `src/lib/api/progresso.ts`, `src/mocks/fixtures/progresso.ts`, `src/mocks/handlers/progresso.ts`
- Modify: `src/lib/chaves.ts`, `src/mocks/handlers/index.ts`
- Test: `src/features/progresso/regras.test.ts`, `src/features/progresso/hooks.integration.test.tsx`

**Interfaces:**
- Consumes: `api`, `CHAVES`, `Goal` (`@/lib/types`), `perfilApi` (fixture).
- Produces:
  - Tipos: `Periodo` (`'6w' | '3m' | 'all'`), `StatusDia`, `Pesagem`, `PesoDoPeriodo`, `Constancia`, `Medias`, `Progresso`.
  - API: `getProgresso(periodo)`, `getPesagens()`, `registrarPeso(weightKg)`.
  - Regras: `mensagemDaDiferenca(objetivo: Goal, diferencaKg: number, diasDesdeUltima: number): string`, `ajustarPeso(valor, delta): number`, `lerPesoDigitado(texto, anterior): number`, `posicaoNaRegua(atual, base): number`, `textoDaPrevisao(peso: PesoDoPeriodo): string | null`, `textoDaVariacao(peso: PesoDoPeriodo): string`, `rotuloDoDia(dia): string`, `historico(pesagens): { date; weightKg; deltaG: number | null }[]`, `rotulosDeData(pontos, max = 6): { indice; date }[]`, `hojeLocal(agora?): string`, `diasEntre(de, ate): number`.
  - Hooks: `useProgresso(periodo)`, `usePesagens()`, `useRegistrarPeso()`, `usePeriodo(): [Periodo, (p: Periodo) => void]`.
  - `CHAVES.progressos` (`['progresso']`), `CHAVES.progresso(p)`, `CHAVES.pesagens`.
  - MSW: `progressoApi(parcial?)`, `pesagensApi`, handlers padrão de `/progress` e `/weigh-ins`; `PADRAO_28_DIAS`.

- [ ] **Step 1: Branch e testes que devem falhar**

```bash
cd /home/alvez/atividade-extensionista/frontend
git switch plano-05b-nutri-telas && git switch -c plano-06b-evolucao-telas
```

`src/features/progresso/regras.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import {
  ajustarPeso,
  diasEntre,
  historico,
  hojeLocal,
  lerPesoDigitado,
  mensagemDaDiferenca,
  posicaoNaRegua,
  rotuloDoDia,
  rotulosDeData,
  textoDaPrevisao,
  textoDaVariacao,
} from './regras';
import type { PesoDoPeriodo } from './tipos';

const peso = (parcial: Partial<PesoDoPeriodo> = {}): PesoDoPeriodo => ({
  startKg: 56.8,
  currentKg: 58.4,
  goalKg: 62,
  goalSource: 'user',
  changeKg: 1.6,
  spanWeeks: 5,
  points: [
    { date: '2026-08-11', weightKg: 56.8 },
    { date: '2026-08-25', weightKg: 57.5 },
    { date: '2026-09-15', weightKg: 58.4 },
  ],
  forecast: { date: '2026-12-05', label: 'início de dezembro' },
  ...parcial,
});

describe('mensagemDaDiferenca (tabela do §4 S16)', () => {
  it.each([
    ['ganhar-massa', 0.2, 'São 200 g a mais que na última pesagem. Dentro do esperado para quem está ganhando massa.'],
    ['ganhar-massa', -0.3, 'São 300 g a menos que na última pesagem. Vale conferir se a semana teve menos refeições no plano.'],
    ['perder-gordura', 0.4, 'São 400 g a mais que na última pesagem. Oscilação de uma semana é normal. Vale olhar a constância.'],
    ['perder-gordura', -0.4, 'São 400 g a menos que na última pesagem. Dentro do esperado para quem está perdendo gordura.'],
    ['manter-peso', 0.5, 'São 500 g a mais que na última pesagem. Dentro do esperado para quem quer manter.'],
    ['manter-peso', -0.6, 'São 600 g a menos que na última pesagem. Vale olhar a constância desta semana.'],
    ['mais-disposicao', 0.3, 'São 300 g a mais que na última pesagem.'],
  ] as const)('%s, %d kg', (objetivo, diferenca, texto) => {
    expect(mensagemDaDiferenca(objetivo, diferenca, 7)).toBe(texto);
  });

  it('igual: "semana passada" com 5+ dias, "última pesagem" antes disso', () => {
    expect(mensagemDaDiferenca('ganhar-massa', 0, 7)).toBe('Mesmo peso da semana passada. Uma semana estável é normal.');
    expect(mensagemDaDiferenca('perder-gordura', 0.04, 2)).toBe('Mesmo peso da última pesagem.');
  });
});

describe('peso no stepper', () => {
  it('ajusta de 100 em 100 g, sem passar de 30–250', () => {
    expect(ajustarPeso(58.4, 0.1)).toBe(58.5);
    expect(ajustarPeso(58.4, -0.1)).toBe(58.3);
    expect(ajustarPeso(30, -0.1)).toBe(30);
    expect(ajustarPeso(250, 0.1)).toBe(250);
  });

  it.each([
    ['58,65', 58.7],
    ['58.6', 58.6],
    ['abc', 59],
    ['', 59],
    ['20', 30],
    ['300', 250],
  ])('digitou "%s" → %d', (texto, esperado) => expect(lerPesoDigitado(texto, 59)).toBe(esperado));

  it('régua: ±1 kg em volta da base, marcador preso nas pontas', () => {
    expect(posicaoNaRegua(58.4, 58.4)).toBe(50);
    expect(posicaoNaRegua(58.9, 58.4)).toBe(75);
    expect(posicaoNaRegua(62, 58.4)).toBe(96);
    expect(posicaoNaRegua(50, 58.4)).toBe(4);
  });
});

describe('textos da Evolução', () => {
  it('previsão, falta pesagem, sem previsão e sem meta', () => {
    expect(textoDaPrevisao(peso())).toBe('No ritmo das últimas semanas, você chega na meta por volta do início de dezembro.');
    expect(textoDaPrevisao(peso({ forecast: null, points: [{ date: '2026-09-28', weightKg: 58.4 }] }))).toBe(
      'Registre mais uma pesagem para estimar quando você chega na meta.',
    );
    expect(textoDaPrevisao(peso({ forecast: null }))).toBe('Com as pesagens de agora, a linha ainda não aponta para a meta.');
    expect(textoDaPrevisao(peso({ goalKg: null, forecast: null }))).toBeNull();
  });

  it('variação usa as semanas reais da API', () => {
    expect(textoDaVariacao(peso())).toBe('+1,6 kg em 5 semanas');
    expect(textoDaVariacao(peso({ changeKg: -0.8, spanWeeks: 1 }))).toBe('−0,8 kg em 1 semana');
    expect(textoDaVariacao(peso({ changeKg: 0, spanWeeks: 0, points: [{ date: '2026-09-28', weightKg: 58.4 }] }))).toBe('primeira pesagem');
    expect(textoDaVariacao(peso({ changeKg: 0.3, spanWeeks: 0 }))).toBe('+0,3 kg nesta semana');
  });

  it('rótulo acessível de cada dia da constância', () => {
    expect(rotuloDoDia({ date: '2026-09-21', status: 'completo' })).toBe('21 de setembro: dia completo');
    expect(rotuloDoDia({ date: '2026-09-22', status: 'parcial' })).toBe('22 de setembro: parte das refeições');
    expect(rotuloDoDia({ date: '2026-09-23', status: 'vazio' })).toBe('23 de setembro: nenhuma refeição feita');
    expect(rotuloDoDia({ date: '2026-09-24', status: 'hoje' })).toBe('24 de setembro: hoje');
  });

  it('no máximo 6 rótulos de data, sempre com o primeiro e o último', () => {
    const pontos = Array.from({ length: 60 }, (_, i) => ({ date: `2026-01-${String((i % 28) + 1).padStart(2, '0')}`, weightKg: 60 }));
    const rotulos = rotulosDeData(pontos);
    expect(rotulos).toHaveLength(6);
    expect(rotulos[0].indice).toBe(0);
    expect(rotulos.at(-1)!.indice).toBe(59);
    expect(rotulosDeData(pontos.slice(0, 3)).map((r) => r.indice)).toEqual([0, 1, 2]);
  });
});

describe('histórico e datas', () => {
  it('últimas 4, mais recente primeiro, delta em gramas e "início" na primeira de todas', () => {
    const lista = historico([
      { id: 1, date: '2026-09-01', weightKg: 57.6 },
      { id: 2, date: '2026-09-08', weightKg: 58.0 },
      { id: 3, date: '2026-09-15', weightKg: 58.4 },
    ]);
    expect(lista).toEqual([
      { date: '2026-09-15', weightKg: 58.4, deltaG: 400 },
      { date: '2026-09-08', weightKg: 58.0, deltaG: 400 },
      { date: '2026-09-01', weightKg: 57.6, deltaG: null },
    ]);
  });

  it('hoje no fuso do aparelho e dias entre datas', () => {
    expect(hojeLocal(new Date(2026, 8, 30, 23, 30))).toBe('2026-09-30');
    expect(diasEntre('2026-09-23', '2026-09-30')).toBe(7);
  });
});
```

`src/features/progresso/hooks.integration.test.tsx`:
```tsx
import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CHAVES } from '@/lib/chaves';
import { url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { novoClienteDeTeste } from '@/test/renderizar';
import { usePeriodo, useProgresso, useRegistrarPeso } from './hooks';

function comCliente(cliente = novoClienteDeTeste()) {
  return { cliente, wrapper: ({ children }: { children: React.ReactNode }) => <QueryClientProvider client={cliente}>{children}</QueryClientProvider> };
}

afterEach(() => {
  vi.restoreAllMocks();
  try {
    localStorage.clear();
  } catch {
    /* sem storage */
  }
});

describe('hooks da Evolução', () => {
  it('useProgresso pede o período e devolve em camelCase', async () => {
    let pedido = '';
    server.use(
      http.get(url('/progress'), ({ request }) => {
        pedido = new URL(request.url).searchParams.get('period') ?? '';
        return HttpResponse.json({ data: { period: '3m', weight: { start_kg: 1, current_kg: 2, goal_kg: null, goal_source: null, change_kg: 1, span_weeks: 1, points: [], forecast: null }, adherence: { days: [], complete_days: 0, streak: 0 }, averages: { days_counted: 0, protein: { avg_g: null, target_g: 115 }, calories: { avg_kcal: null, target_kcal: 2250 }, insight: null } } });
      }),
    );
    const { wrapper } = comCliente();
    const { result } = renderHook(() => useProgresso('3m'), { wrapper });

    await waitFor(() => expect(result.current.data).toBeDefined());
    expect(pedido).toBe('3m');
    expect(result.current.data!.averages.protein.targetG).toBe(115);
  });

  it('registrar peso invalida progresso, pesagens e perfil', async () => {
    server.use(http.post(url('/weigh-ins'), () => HttpResponse.json({ data: { id: 9, date: '2026-09-30', weight_kg: 58.6 }, meta: { replaced: false } }, { status: 201 })));
    const { cliente, wrapper } = comCliente();
    const invalidar = vi.spyOn(cliente, 'invalidateQueries');
    const { result } = renderHook(() => useRegistrarPeso(), { wrapper });

    await act(() => result.current.mutateAsync(58.6));

    const chaves = invalidar.mock.calls.map(([filtro]) => JSON.stringify(filtro?.queryKey));
    expect(chaves).toEqual(expect.arrayContaining([JSON.stringify(CHAVES.progressos), JSON.stringify(CHAVES.pesagens), JSON.stringify(CHAVES.perfil)]));
  });

  it('usePeriodo lembra a escolha e começa em 6w', () => {
    const { result, unmount } = renderHook(() => usePeriodo());
    expect(result.current[0]).toBe('6w');
    act(() => result.current[1]('all'));
    expect(result.current[0]).toBe('all');
    unmount();

    expect(renderHook(() => usePeriodo()).result.current[0]).toBe('all');
  });

  it('usePeriodo sem localStorage (aba anônima) não quebra', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('SecurityError');
    });
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('SecurityError');
    });
    const { result } = renderHook(() => usePeriodo());

    expect(result.current[0]).toBe('6w');
    act(() => result.current[1]('3m'));
    expect(result.current[0]).toBe('3m');
  });
});
```

Run: `docker compose run --rm web npx vitest run --project unit --project integration src/features/progresso`
Expected: FAIL — módulos inexistentes.

- [ ] **Step 2: Implementar**

`src/features/progresso/tipos.ts`:
```ts
export type Periodo = '6w' | '3m' | 'all';
export type StatusDia = 'completo' | 'parcial' | 'vazio' | 'hoje';

/** `GET /weigh-ins` (camelCase). */
export interface Pesagem {
  id: number;
  date: string;
  weightKg: number;
}

export interface PesoDoPeriodo {
  startKg: number | null;
  currentKg: number | null;
  goalKg: number | null;
  goalSource: string | null;
  changeKg: number | null;
  spanWeeks: number | null;
  points: { date: string; weightKg: number }[];
  forecast: { date: string; label: string } | null;
}

export interface Constancia {
  days: { date: string; status: StatusDia }[];
  completeDays: number;
  streak: number;
}

export interface Medias {
  daysCounted: number;
  protein: { avgG: number | null; targetG: number | null };
  calories: { avgKcal: number | null; targetKcal: number | null };
  insight: string | null;
}

/** `GET /progress?period=` (spec 05 §5). */
export interface Progresso {
  period: Periodo;
  weight: PesoDoPeriodo;
  adherence: Constancia;
  averages: Medias;
}
```

`src/lib/api/progresso.ts`:
```ts
import type { Periodo, Pesagem, Progresso } from '@/features/progresso/tipos';
import { api } from './client';

type Dados<T> = { data: T };

/** GET /progress?period=6w|3m|all */
export const getProgresso = (periodo: Periodo) => api<Dados<Progresso>>(`/progress?period=${periodo}`).then((r) => r.data);

/** GET /weigh-ins — ordem crescente de data. */
export const getPesagens = () => api<Dados<Pesagem[]>>('/weigh-ins').then((r) => r.data);

/** POST /weigh-ins — a de hoje; na mesma data, substitui (RN34). */
export const registrarPeso = (weightKg: number) =>
  api<{ data: Pesagem; meta: { replaced: boolean } }>('/weigh-ins', { method: 'POST', body: { weightKg } });
```

`src/lib/chaves.ts` — acrescentar:
```ts
  progressos: ['progresso'],
  progresso: (periodo: string) => ['progresso', periodo] as const,
  pesagens: ['pesagens'],
```

`src/features/progresso/regras.ts`:
```ts
import type { Goal } from '@/lib/types';
import type { PesoDoPeriodo, StatusDia } from './tipos';

const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
// `+ 1e-9`: 58,65 × 10 em ponto flutuante dá 586,4999…; a pessoa digitou 58,65 e espera 58,7.
const umaCasa = (n: number) => Math.round(n * 10 + 1e-9) / 10;
const kg = (n: number) => n.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const limitar = (n: number) => Math.min(250, Math.max(30, n));

/** Mensagem de comparação com a última pesagem, por objetivo (spec 05 §4 S16). */
export function mensagemDaDiferenca(objetivo: Goal, diferencaKg: number, diasDesdeUltima: number): string {
  const g = Math.round(Math.abs(diferencaKg) * 1000 / 100) * 100;
  if (g === 0) {
    return diasDesdeUltima >= 5 ? 'Mesmo peso da semana passada. Uma semana estável é normal.' : 'Mesmo peso da última pesagem.';
  }
  const subiu = diferencaKg > 0;
  const numero = `São ${g} g ${subiu ? 'a mais' : 'a menos'} que na última pesagem.`;
  const complemento: Record<Goal, string | null> = {
    'ganhar-massa': subiu ? 'Dentro do esperado para quem está ganhando massa.' : 'Vale conferir se a semana teve menos refeições no plano.',
    'perder-gordura': subiu ? 'Oscilação de uma semana é normal. Vale olhar a constância.' : 'Dentro do esperado para quem está perdendo gordura.',
    'manter-peso': g <= 500 ? 'Dentro do esperado para quem quer manter.' : 'Vale olhar a constância desta semana.',
    'mais-disposicao': null,
  };
  return complemento[objetivo] ? `${numero} ${complemento[objetivo]}` : numero;
}

/** ± 100 g, com uma casa, dentro de 30–250 kg (RN34). */
export const ajustarPeso = (valor: number, delta: number) => limitar(umaCasa(valor + delta));

/** O que a pessoa digitou no número: aceita vírgula; inválido volta ao anterior. */
export function lerPesoDigitado(texto: string, anterior: number): number {
  const n = Number(texto.trim().replace(',', '.'));
  if (texto.trim() === '' || !Number.isFinite(n)) return anterior;
  return limitar(umaCasa(n));
}

/** Régua de ±1 kg em volta da base; o marcador fica entre 4% e 96%. */
export const posicaoNaRegua = (atual: number, base: number) =>
  Math.min(96, Math.max(4, Math.round((50 + ((atual - base) / 2) * 100) * 10) / 10));

export const diasEntre = (de: string, ate: string) =>
  Math.round((Date.parse(`${ate}T12:00:00`) - Date.parse(`${de}T12:00:00`)) / 86_400_000);

/** Data de hoje no fuso do aparelho, `YYYY-MM-DD`. */
export function hojeLocal(agora = new Date()): string {
  return `${agora.getFullYear()}-${String(agora.getMonth() + 1).padStart(2, '0')}-${String(agora.getDate()).padStart(2, '0')}`;
}

/** RN35 na tela: previsão, pedido de mais pesagens ou aviso neutro; nada sem meta. */
export function textoDaPrevisao(peso: PesoDoPeriodo): string | null {
  if (peso.goalKg === null) return null;
  if (peso.forecast) return `No ritmo das últimas semanas, você chega na meta por volta do ${peso.forecast.label}.`;
  const pontos = peso.points;
  if (pontos.length < 3 || diasEntre(pontos[0].date, pontos[pontos.length - 1].date) < 14) {
    return 'Registre mais uma pesagem para estimar quando você chega na meta.';
  }
  return 'Com as pesagens de agora, a linha ainda não aponta para a meta.';
}

/** "+1,6 kg em 5 semanas" — semanas reais entre a primeira e a última pesagem do período. */
export function textoDaVariacao(peso: PesoDoPeriodo): string {
  const semanas = peso.spanWeeks ?? 0;
  if (peso.points.length <= 1) return 'primeira pesagem';
  const variacao = peso.changeKg ?? 0;
  const sinal = variacao > 0 ? '+' : variacao < 0 ? '−' : '';
  const quando = semanas === 0 ? 'nesta semana' : `em ${semanas} ${semanas === 1 ? 'semana' : 'semanas'}`;
  return `${sinal}${kg(Math.abs(variacao))} kg ${quando}`;
}

const DESCRICAO: Record<StatusDia, string> = {
  completo: 'dia completo',
  parcial: 'parte das refeições',
  vazio: 'nenhuma refeição feita',
  hoje: 'hoje',
};

export function rotuloDoDia(dia: { date: string; status: StatusDia }): string {
  const [, mes, d] = dia.date.split('-').map(Number);
  return `${d} de ${MESES[mes - 1]}: ${DESCRICAO[dia.status]}`;
}

/** Até `max` rótulos de data, espalhados, sempre com o primeiro e o último ponto. */
export function rotulosDeData(pontos: { date: string }[], max = 6): { indice: number; date: string }[] {
  if (pontos.length <= max) return pontos.map((p, indice) => ({ indice, date: p.date }));
  const passo = (pontos.length - 1) / (max - 1);
  return Array.from({ length: max }, (_, i) => {
    const indice = Math.round(i * passo);
    return { indice, date: pontos[indice].date };
  });
}

/** "Suas pesagens": as 4 últimas, mais recente primeiro; a primeira de todas diz "início". */
export function historico(pesagens: { date: string; weightKg: number }[]): { date: string; weightKg: number; deltaG: number | null }[] {
  return pesagens
    .map((p, i) => ({ date: p.date, weightKg: p.weightKg, deltaG: i === 0 ? null : Math.round((p.weightKg - pesagens[i - 1].weightKg) * 1000) }))
    .reverse()
    .slice(0, 4);
}
```

`src/features/progresso/hooks.ts`:
```ts
'use client';

import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useSyncExternalStore } from 'react';
import * as progresso from '@/lib/api/progresso';
import { CHAVES } from '@/lib/chaves';
import type { Periodo } from './tipos';

/** Trocar de período mantém o gráfico anterior na tela até o novo chegar. */
export const useProgresso = (periodo: Periodo) =>
  useQuery({ queryKey: CHAVES.progresso(periodo), queryFn: () => progresso.getProgresso(periodo), placeholderData: keepPreviousData });

export const usePesagens = () => useQuery({ queryKey: CHAVES.pesagens, queryFn: progresso.getPesagens });

/** CA08: salvar muda a Evolução, o card de Hoje e o Perfil. */
export function useRegistrarPeso() {
  const cliente = useQueryClient();
  return useMutation({
    mutationFn: progresso.registrarPeso,
    onSuccess: () => {
      void cliente.invalidateQueries({ queryKey: CHAVES.progressos });
      void cliente.invalidateQueries({ queryKey: CHAVES.pesagens });
      void cliente.invalidateQueries({ queryKey: CHAVES.perfil });
    },
  });
}

const CHAVE = 'pf:periodo-evolucao';
const PERIODOS: Periodo[] = ['6w', '3m', 'all'];
const ouvintes = new Set<() => void>();
let naMemoria: Periodo = '6w'; // só quando o navegador não deixa usar o localStorage

function ler(): Periodo {
  try {
    const salvo = localStorage.getItem(CHAVE);
    return PERIODOS.includes(salvo as Periodo) ? (salvo as Periodo) : '6w';
  } catch {
    return naMemoria;
  }
}

function gravar(periodo: Periodo) {
  try {
    localStorage.setItem(CHAVE, periodo);
  } catch {
    naMemoria = periodo; // aba anônima ou storage bloqueado: fica só na memória
  }
  ouvintes.forEach((avisar) => avisar());
}

function assinar(avisar: () => void) {
  ouvintes.add(avisar);
  return () => ouvintes.delete(avisar);
}

/** Período da Evolução, lembrado entre visitas (🟡 spec 05 §4 S15). */
export function usePeriodo(): [Periodo, (p: Periodo) => void] {
  return [useSyncExternalStore(assinar, ler, () => '6w' as Periodo), gravar];
}
```

`src/mocks/fixtures/progresso.ts`:
```ts
/** A evolução da Camila como a API devolve (snake_case), ancorada em 2026-09-15 (as 6 pesagens do mock). */

export const PADRAO_28_DIAS = [
  'completo', 'completo', 'parcial', 'completo', 'completo', 'vazio', 'completo',
  'completo', 'parcial', 'completo', 'completo', 'completo', 'completo', 'vazio',
  'completo', 'completo', 'completo', 'parcial', 'completo', 'completo', 'completo',
  'completo', 'completo', 'vazio', 'completo', 'completo', 'completo', 'hoje',
] as const;

const PONTOS = [
  ['2026-08-11', 56.8], ['2026-08-18', 57.0], ['2026-08-25', 57.5], ['2026-09-01', 57.6], ['2026-09-08', 58.0], ['2026-09-15', 58.4],
] as const;

export const pesagensApi = PONTOS.map(([date, weight_kg], i) => ({ id: i + 1, date, weight_kg }));

type Parcial = { pontos?: readonly (readonly [string, number])[]; meta?: number | null; previsao?: boolean; semMedias?: boolean };

export function progressoApi(parcial: Parcial = {}) {
  const pontos = parcial.pontos ?? PONTOS;
  const meta = parcial.meta === undefined ? 62 : parcial.meta;
  const primeiro = pontos[0];
  const ultimo = pontos.at(-1);
  return {
    period: '6w',
    weight: {
      start_kg: primeiro?.[1] ?? null,
      current_kg: ultimo?.[1] ?? null,
      goal_kg: meta,
      goal_source: meta === null ? null : 'user',
      change_kg: ultimo ? Math.round((ultimo[1] - primeiro![1]) * 10) / 10 : null,
      span_weeks: ultimo ? Math.round((Date.parse(ultimo[0]) - Date.parse(primeiro![0])) / (7 * 86_400_000)) : null,
      points: pontos.map(([date, weight_kg]) => ({ date, weight_kg })),
      forecast: (parcial.previsao ?? true) && meta !== null ? { date: '2026-12-05', label: 'início de dezembro' } : null,
    },
    adherence: {
      days: PADRAO_28_DIAS.map((status, i) => ({ date: new Date(Date.UTC(2026, 7, 19 + i)).toISOString().slice(0, 10), status })),
      complete_days: 21,
      streak: 3,
    },
    averages: parcial.semMedias
      ? { days_counted: 0, protein: { avg_g: null, target_g: 115 }, calories: { avg_kcal: null, target_kcal: 2250 }, insight: null }
      : {
          days_counted: 26,
          protein: { avg_g: 112, target_g: 115 },
          calories: { avg_kcal: 1870, target_kcal: 2250 },
          insight: 'Você fica um pouco abaixo da meta de proteína nos dias sem treino.',
        },
  };
}
```

`src/mocks/handlers/progresso.ts`:
```ts
import { http, HttpResponse } from 'msw';
import { pesagensApi, progressoApi } from '../fixtures/progresso';
import { url } from './auth';

/** Padrão: a evolução da Camila e as 6 pesagens. */
export const handlersProgresso = [
  http.get(url('/progress'), () => HttpResponse.json({ data: progressoApi() })),
  http.get(url('/weigh-ins'), () => HttpResponse.json({ data: pesagensApi })),
];

export const respondendoProgresso = (corpo: ReturnType<typeof progressoApi>) =>
  http.get(url('/progress'), () => HttpResponse.json({ data: corpo }));
```

`src/mocks/handlers/index.ts` — acrescentar `handlersProgresso` (import de `./progresso`).

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project unit --project integration src/features/progresso && npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(evolucao): tipos, chamadas, regras, hooks e MSW da Evolução

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `WeightChart`, `EvolucaoVazia`, `AdherenceGrid` e `MediasDoPeriodo`

**Files (repo front):**
- Create: `src/features/progresso/components/{WeightChart,EvolucaoVazia,AdherenceGrid,MediasDoPeriodo}.tsx` e as quatro stories

**Interfaces:**
- Consumes: tipos e regras da Task 1; `CountUp`, `Rail`, `ButtonLink`, `peso`, `diaCurto`, `cascata`, `Reveal`.
- Produces: `WeightChart({ peso: PesoDoPeriodo })` (≥ 1 ponto), `EvolucaoVazia()`, `AdherenceGrid({ constancia: Constancia })`, `MediasDoPeriodo({ medias: Medias })`.

- [ ] **Step 1: Stories que devem falhar**

`src/features/progresso/components/WeightChart.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, within } from 'storybook/test';
import { camelizar } from '@/lib/api/case';
import { progressoApi } from '@/mocks/fixtures/progresso';
import type { Progresso } from '../tipos';
import { WeightChart } from './WeightChart';

const de = (parcial: Parameters<typeof progressoApi>[0] = {}) => camelizar<Progresso>(progressoApi(parcial)).weight;

const meta = { title: 'Evolução/WeightChart', component: WeightChart, args: { peso: de() } } satisfies Meta<typeof WeightChart>;
export default meta;
type Story = StoryObj<typeof meta>;

export const SeisPesagens: Story = {
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByRole('img', { name: 'Peso de 56,8 kg para 58,4 kg, com meta de 62,0 kg' })).toBeInTheDocument();
    await expect(tela.getByText('+1,6 kg em 5 semanas')).toBeInTheDocument();
    await expect(tela.getByText('meta 62,0 kg')).toBeInTheDocument();
    await expect(tela.getByText('No ritmo das últimas semanas, você chega na meta por volta do início de dezembro.')).toBeInTheDocument();
  },
};

export const UmaPesagem: Story = {
  args: { peso: de({ pontos: [['2026-09-28', 58.4]], previsao: false }) },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByText('primeira pesagem')).toBeInTheDocument();
    await expect(tela.getByText('Registre mais uma pesagem para estimar quando você chega na meta.')).toBeInTheDocument();
  },
};

export const SemMeta: Story = {
  args: { peso: de({ meta: null }) },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByRole('img', { name: 'Peso de 56,8 kg para 58,4 kg' })).toBeInTheDocument();
    await expect(tela.queryByText(/^meta /)).toBeNull();
    await expect(tela.queryByText(/chega na meta/)).toBeNull();
  },
};

export const Perda: Story = {
  args: { peso: de({ pontos: [['2026-08-11', 73.2], ['2026-08-25', 72.5], ['2026-09-15', 71.6]], meta: 68 }) },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('−1,6 kg em 5 semanas')).toBeInTheDocument();
  },
};

export const MuitosPontos: Story = {
  args: {
    peso: de({ pontos: Array.from({ length: 40 }, (_, i) => [new Date(Date.UTC(2026, 0, 1 + i * 7)).toISOString().slice(0, 10), 60 + i * 0.1] as const) }),
  },
  play: async ({ canvasElement }) => {
    await expect(canvasElement.querySelectorAll('[data-rotulo-data]')).toHaveLength(6);
  },
};
```

`src/features/progresso/components/EvolucaoVazia.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, within } from 'storybook/test';
import { EvolucaoVazia } from './EvolucaoVazia';

const meta = { title: 'Evolução/EvolucaoVazia', component: EvolucaoVazia } satisfies Meta<typeof EvolucaoVazia>;
export default meta;
type Story = StoryObj<typeof meta>;

export const ComBotao: Story = {
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByRole('heading', { name: 'Sua linha começa na primeira pesagem' })).toBeInTheDocument();
    await expect(tela.getByRole('link', { name: 'Registrar meu peso' })).toHaveAttribute('href', '/evolucao/peso');
  },
};
```

`src/features/progresso/components/AdherenceGrid.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, within } from 'storybook/test';
import { camelizar } from '@/lib/api/case';
import { progressoApi } from '@/mocks/fixtures/progresso';
import type { Constancia, Progresso, StatusDia } from '../tipos';
import { AdherenceGrid } from './AdherenceGrid';

const padrao = camelizar<Progresso>(progressoApi()).adherence;

const meta = { title: 'Evolução/AdherenceGrid', component: AdherenceGrid, args: { constancia: padrao } } satisfies Meta<typeof AdherenceGrid>;
export default meta;
type Story = StoryObj<typeof meta>;

export const PadraoDoMock: Story = {
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getAllByRole('img')).toHaveLength(28);
    await expect(tela.getByRole('img', { name: '15 de setembro: hoje' })).toBeInTheDocument();
    await expect(tela.getByText('21 dias')).toBeInTheDocument();
    await expect(tela.getByText(/Sua sequência atual é de 3 dias\./)).toBeInTheDocument();
  },
};

export const TodoVazio: Story = {
  args: {
    constancia: { days: padrao.days.map((d, i) => ({ ...d, status: (i === 27 ? 'hoje' : 'vazio') as StatusDia })), completeDays: 0, streak: 0 } satisfies Constancia,
  },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByText('0 dias')).toBeInTheDocument();
    await expect(tela.queryByText(/sequência/)).toBeNull();
  },
};

export const SequenciaLonga: Story = {
  args: { constancia: { days: padrao.days.map((d, i) => ({ ...d, status: (i === 27 ? 'hoje' : 'completo') as StatusDia })), completeDays: 27, streak: 27 } },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText(/Sua sequência atual é de 27 dias\./)).toBeInTheDocument();
  },
};
```

`src/features/progresso/components/MediasDoPeriodo.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, within } from 'storybook/test';
import { camelizar } from '@/lib/api/case';
import { progressoApi } from '@/mocks/fixtures/progresso';
import type { Progresso } from '../tipos';
import { MediasDoPeriodo } from './MediasDoPeriodo';

const meta = {
  title: 'Evolução/MediasDoPeriodo',
  component: MediasDoPeriodo,
  args: { medias: camelizar<Progresso>(progressoApi()).averages },
} satisfies Meta<typeof MediasDoPeriodo>;
export default meta;
type Story = StoryObj<typeof meta>;

export const ComObservacao: Story = {
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByRole('heading', { name: 'Média por dia neste período' })).toBeInTheDocument();
    await expect(tela.getByText('Você fica um pouco abaixo da meta de proteína nos dias sem treino.')).toBeInTheDocument();
  },
};

export const Vazio: Story = {
  args: { medias: camelizar<Progresso>(progressoApi({ semMedias: true })).averages },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('Marque suas refeições para ver suas médias aqui.')).toBeInTheDocument();
  },
};
```

Run: `docker compose run --rm web npx vitest run --project storybook src/features/progresso`
Expected: FAIL — componentes inexistentes.

- [ ] **Step 2: Implementar (marcação do protótipo `src/app/(app)/evolucao/page.tsx`)**

`src/features/progresso/components/WeightChart.tsx`:
```tsx
import { CountUp } from "@/components/ui/CountUp";
import { diaCurto, peso as kg } from "@/lib/format";
import { cascata } from "@/lib/motion";
import { rotulosDeData, textoDaPrevisao, textoDaVariacao } from "../regras";
import type { PesoDoPeriodo } from "../tipos";

/** Peso no período: número atual, variação, linha com meta tracejada e previsão (RF24, RN35). */
export function WeightChart({ peso }: { peso: PesoDoPeriodo }) {
  const pontos = peso.points;
  const atual = pontos[pontos.length - 1].weightKg;
  const meta = peso.goalKg;
  const valores = pontos.map((p) => p.weightKg);
  const baixo = Math.min(...valores, meta ?? Infinity) - 0.6;
  const alto = Math.max(...valores, meta ?? -Infinity) + 0.4;
  const y = (v: number) => 108 - ((v - baixo) / (alto - baixo || 1)) * 94;
  const x = (i: number) => (pontos.length === 1 ? 150 : 10 + (i * 280) / (pontos.length - 1));
  const coords = pontos.map((p, i) => ({ px: x(i), py: Number(y(p.weightKg).toFixed(1)) }));
  const linha = coords.map((c) => `${c.px},${c.py}`).join(" ");
  const area = `${linha} ${x(pontos.length - 1)},108 10,108`;
  const comprimento = coords.reduce((soma, c, i) => (i === 0 ? 0 : soma + Math.hypot(c.px - coords[i - 1].px, c.py - coords[i - 1].py)), 0);
  const variacao = peso.changeKg ?? 0;
  const previsao = textoDaPrevisao(peso);
  const rotulo = `Peso de ${kg(pontos[0].weightKg)} para ${kg(atual)}${meta !== null ? `, com meta de ${kg(meta)}` : ""}`;

  return (
    <section className="animate-escala rounded-[20px] bg-white px-[18px] pt-4 pb-3.5" style={{ animationDelay: "120ms" }}>
      <div className="flex items-baseline justify-between gap-3">
        <p className="font-display text-[32px] font-bold tracking-[-0.03em]">
          <CountUp valor={atual} casas={1} duracao={1100} />{" "}
          <span className="text-base font-semibold tracking-normal text-fumo">kg</span>
        </p>
        <span
          className={`inline-flex h-[26px] shrink-0 animate-pop items-center rounded-full px-2.5 text-[12.5px] font-semibold ${
            variacao === 0 ? "bg-papel text-fumo" : "bg-mata-fraca text-mata-texto"
          }`}
          style={{ animationDelay: "700ms" }}
        >
          {textoDaVariacao(peso)}
        </span>
      </div>

      <svg viewBox="0 0 300 132" width="100%" height={132} role="img" aria-label={rotulo} className="mt-3 block overflow-visible">
        {meta !== null ? (
          <>
            <line x1="0" y1={y(meta)} x2="300" y2={y(meta)} stroke="var(--color-pedra)" strokeWidth="1" strokeDasharray="4 4" />
            <text x="0" y={y(meta) - 5} fontSize="10" fill="var(--color-fumo)" fontFamily="var(--font-sans)">
              meta {kg(meta)}
            </text>
          </>
        ) : null}
        {pontos.length > 1 ? (
          <>
            <polygon points={area} fill="#edefe9" className="animate-fade" style={{ animationDelay: "900ms", animationDuration: "600ms" }} />
            <polyline
              points={linha}
              fill="none"
              stroke="var(--color-tinta)"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
              className="desenha"
              style={{ "--tamanho-traco": comprimento } as React.CSSProperties}
            />
          </>
        ) : null}
        {coords.slice(0, -1).map((c, i) => (
          <circle
            key={pontos[i].date}
            cx={c.px}
            cy={c.py}
            r="2.6"
            fill="var(--color-pedra)"
            className="animate-pop"
            style={{ animationDelay: `${240 + Math.min(i, 12) * 90}ms`, transformOrigin: `${c.px}px ${c.py}px` }}
          />
        ))}
        {(() => {
          const c = coords[coords.length - 1];
          const origem = { transformOrigin: `${c.px}px ${c.py}px` };
          return (
            <>
              <circle cx={c.px} cy={c.py} r="9" fill="var(--color-gema)" opacity="0.22" className="animate-halo" style={origem} />
              <circle cx={c.px} cy={c.py} r="6" fill="#ffffff" className="animate-pop" style={{ ...origem, animationDelay: "1150ms" }} />
              <circle cx={c.px} cy={c.py} r="4.5" fill="var(--color-gema)" className="animate-pop" style={{ ...origem, animationDelay: "1200ms" }} />
            </>
          );
        })()}
        <line x1="0" y1="120" x2="300" y2="120" stroke="var(--color-fio)" strokeWidth="1" />
      </svg>

      <div className="relative mt-1.5 h-4 text-[10.5px] text-fumo">
        {rotulosDeData(pontos).map(({ indice, date }, i, todos) => (
          <span
            key={date + indice}
            data-rotulo-data
            className="absolute top-0 animate-entra whitespace-nowrap"
            style={{
              ...cascata(i, 80, 400),
              left: `${(x(indice) / 300) * 100}%`,
              transform: i === 0 ? "none" : i === todos.length - 1 ? "translateX(-100%)" : "translateX(-50%)",
            }}
          >
            {diaCurto(date)}
          </span>
        ))}
      </div>

      {previsao ? (
        <p className="mt-3 animate-entra border-t border-fio pt-3 text-[13px] leading-normal text-fumo" style={{ animationDelay: "1000ms" }}>
          {previsao}
        </p>
      ) : null}
    </section>
  );
}
```

`src/features/progresso/components/EvolucaoVazia.tsx`:
```tsx
import { ButtonLink } from "@/components/ui/Button";

/** Sem pesagens no período (spec 05 §4 S15). */
export function EvolucaoVazia() {
  return (
    <section className="rounded-[20px] bg-white p-[18px]">
      <svg viewBox="0 0 300 120" width="100%" height={120} role="img" aria-label="Gráfico ainda sem pesagens registradas" className="block">
        <line x1="0" y1="14" x2="300" y2="14" stroke="var(--color-linha)" strokeWidth="1" strokeDasharray="4 4" />
        <text x="0" y="10" fontSize="10" fill="var(--color-musgo)">
          sua meta
        </text>
        <line x1="10" y1="96" x2="290" y2="96" stroke="var(--color-linha)" strokeWidth="2" strokeDasharray="5 6" strokeLinecap="round" />
        <circle cx="10" cy="96" r="5.5" fill="#ffffff" stroke="var(--color-pedra)" strokeWidth="2" className="animate-respira" />
      </svg>
      <h2 className="mt-4 font-display text-xl leading-tight font-bold tracking-[-0.02em]">Sua linha começa na primeira pesagem</h2>
      <p className="mt-2 text-sm leading-normal text-fumo">
        Registre o peso hoje e repita uma vez por semana. Em um mês já dá para ver para onde a linha está indo.
      </p>
      <ButtonLink href="/evolucao/peso" className="mt-4">
        Registrar meu peso
      </ButtonLink>
    </section>
  );
}
```

`src/features/progresso/components/AdherenceGrid.tsx`:
```tsx
import { cascata } from "@/lib/motion";
import { rotuloDoDia } from "../regras";
import type { Constancia, StatusDia } from "../tipos";

const CORES: Record<StatusDia, string> = { completo: "bg-mata", parcial: "bg-mata-media", vazio: "bg-linha", hoje: "bg-gema" };

/** Constância dos últimos 28 dias (RF25, RN36). */
export function AdherenceGrid({ constancia }: { constancia: Constancia }) {
  return (
    <section className="rounded-[20px] bg-white px-[18px] py-4">
      <div className="flex items-baseline justify-between">
        <h2 className="font-display text-[15px] font-semibold">Constância</h2>
        <span className="text-[12.5px] text-fumo">últimos 28 dias</span>
      </div>
      <p className="mt-2 text-[13.5px] leading-snug">
        <b className="font-semibold">{constancia.completeDays} dias</b> com todas as refeições feitas.
        {constancia.streak > 1 ? ` Sua sequência atual é de ${constancia.streak} dias.` : ""}
      </p>
      <div className="mt-3 grid grid-cols-7 gap-1.5">
        {constancia.days.map((dia, i) => (
          <span
            key={dia.date}
            role="img"
            aria-label={rotuloDoDia(dia)}
            title={rotuloDoDia(dia)}
            style={cascata(i, 16, 120)}
            className={`aspect-square rounded-lg ${CORES[dia.status]} ${dia.status === "hoje" ? "animate-respira" : "animate-pop"}`}
          />
        ))}
      </div>
      <div className="mt-3 flex flex-wrap gap-3.5" aria-hidden="true">
        {[
          { cor: "bg-mata", rotulo: "Dia completo" },
          { cor: "bg-mata-media", rotulo: "Parte das refeições" },
          { cor: "bg-gema", rotulo: "Hoje" },
        ].map((l) => (
          <span key={l.rotulo} className="flex items-center gap-1.5 text-[11.5px] text-fumo">
            <span className={`size-2.5 rounded-[3px] ${l.cor}`} />
            {l.rotulo}
          </span>
        ))}
      </div>
    </section>
  );
}
```

`src/features/progresso/components/MediasDoPeriodo.tsx`:
```tsx
import { Rail } from "@/components/ui/Rail";
import type { Medias } from "../tipos";

/** "Média por dia neste período" (RF25, RN37). */
export function MediasDoPeriodo({ medias }: { medias: Medias }) {
  const { protein, calories } = medias;
  return (
    <section>
      <h2 className="mb-2.5 font-display text-[15px] font-semibold">Média por dia neste período</h2>
      {medias.daysCounted === 0 || protein.avgG === null || calories.avgKcal === null ? (
        <p className="rounded-[20px] bg-white px-[18px] py-4 text-[13.5px] leading-snug text-fumo">Marque suas refeições para ver suas médias aqui.</p>
      ) : (
        <>
          <Rail rotulo="Proteína" valor={protein.avgG} meta={protein.targetG ?? protein.avgG} atraso={200} />
          <Rail rotulo="Calorias" valor={calories.avgKcal} meta={calories.targetKcal ?? calories.avgKcal} unidade="kcal" cor="bg-gema" atraso={280} />
          {medias.insight ? <p className="mt-2 text-[12.5px] leading-snug text-fumo">{medias.insight}</p> : null}
        </>
      )}
    </section>
  );
}
```

- [ ] **Step 3: Rodar, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project storybook src/features/progresso && npm run lint && npm run typecheck"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(evolucao): gráfico de peso, estado vazio, constância e médias

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `WeightStepper`, `WeightDeltaMessage` e `HistoricoPesagens`

**Files (repo front):**
- Create: `src/features/progresso/components/{WeightStepper,WeightDeltaMessage,HistoricoPesagens}.tsx` e as três stories

**Interfaces:**
- Consumes: regras da Task 1; `IconeMais`, `IconeMenos`, `CountUp`, `dataPorExtenso`, `peso`, `cascata`.
- Produces: `WeightStepper({ valor, base, aoMudar })` (botões "Diminuir/Aumentar 100 gramas"; número vira campo "Peso em quilos" ao tocar), `WeightDeltaMessage({ objetivo, diferencaKg, diasDesdeUltima })`, `HistoricoPesagens({ pesagens: Pesagem[] })`.

- [ ] **Step 1: Stories que devem falhar**

`src/features/progresso/components/WeightStepper.stories.tsx`:
```tsx
import { useState } from 'react';
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { WeightStepper } from './WeightStepper';

const meta = {
  title: 'Evolução/WeightStepper',
  component: WeightStepper,
  args: { valor: 58.4, base: 58.4, aoMudar: fn() },
  render: function Controlado(args) {
    const [valor, setValor] = useState(args.valor);
    return <WeightStepper {...args} valor={valor} aoMudar={(v) => { setValor(v); args.aoMudar(v); }} />;
  },
} satisfies Meta<typeof WeightStepper>;
export default meta;
type Story = StoryObj<typeof meta>;

export const Inicial: Story = {
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('button', { name: 'Digitar o peso: 58,4 kg' })).toBeInTheDocument();
  },
};

export const Ajustando: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await userEvent.click(tela.getByRole('button', { name: 'Aumentar 100 gramas' }));
    await userEvent.click(tela.getByRole('button', { name: 'Aumentar 100 gramas' }));
    await userEvent.click(tela.getByRole('button', { name: 'Diminuir 100 gramas' }));
    await expect(args.aoMudar).toHaveBeenLastCalledWith(58.5);
  },
};

export const ForaDaRegua: Story = {
  args: { valor: 61, base: 58.4 },
  play: async ({ canvasElement }) => {
    await expect(canvasElement.querySelector('[data-marcador]')).toHaveStyle({ left: '96%' });
  },
};

export const Digitando: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await userEvent.click(tela.getByRole('button', { name: 'Digitar o peso: 58,4 kg' }));
    const campo = tela.getByRole('textbox', { name: 'Peso em quilos' });
    await expect(campo).toHaveFocus();
    await userEvent.clear(campo);
    await userEvent.type(campo, '59,25{Enter}');
    await expect(args.aoMudar).toHaveBeenLastCalledWith(59.3);
    await expect(tela.getByRole('button', { name: 'Digitar o peso: 59,3 kg' })).toBeInTheDocument();
  },
};
```

`src/features/progresso/components/WeightDeltaMessage.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, within } from 'storybook/test';
import { WeightDeltaMessage } from './WeightDeltaMessage';

const meta = {
  title: 'Evolução/WeightDeltaMessage',
  component: WeightDeltaMessage,
  args: { objetivo: 'ganhar-massa', diferencaKg: 0.2, diasDesdeUltima: 7 },
} satisfies Meta<typeof WeightDeltaMessage>;
export default meta;
type Story = StoryObj<typeof meta>;

export const GanharSubiu: Story = {
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('São 200 g a mais que na última pesagem. Dentro do esperado para quem está ganhando massa.')).toBeInTheDocument();
  },
};
export const GanharDesceu: Story = { args: { diferencaKg: -0.3 } };
export const PerderSubiu: Story = { args: { objetivo: 'perder-gordura', diferencaKg: 0.4 } };
export const PerderDesceu: Story = { args: { objetivo: 'perder-gordura', diferencaKg: -0.4 } };
export const ManterPouco: Story = { args: { objetivo: 'manter-peso', diferencaKg: 0.3 } };
export const ManterMuito: Story = { args: { objetivo: 'manter-peso', diferencaKg: -0.8 } };
export const Disposicao: Story = { args: { objetivo: 'mais-disposicao', diferencaKg: 0.3 } };
export const Igual: Story = {
  args: { diferencaKg: 0 },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('Mesmo peso da semana passada. Uma semana estável é normal.')).toBeInTheDocument();
  },
};
```

`src/features/progresso/components/HistoricoPesagens.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, within } from 'storybook/test';
import { camelizar } from '@/lib/api/case';
import { pesagensApi } from '@/mocks/fixtures/progresso';
import type { Pesagem } from '../tipos';
import { HistoricoPesagens } from './HistoricoPesagens';

const meta = { title: 'Evolução/HistoricoPesagens', component: HistoricoPesagens, args: { pesagens: camelizar<Pesagem[]>(pesagensApi) } } satisfies Meta<typeof HistoricoPesagens>;
export default meta;
type Story = StoryObj<typeof meta>;

export const QuatroUltimas: Story = {
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    const itens = tela.getAllByRole('listitem');
    await expect(itens).toHaveLength(4);
    await expect(itens[0]).toHaveTextContent('15 de setembro58,4 kg+400 g');
  },
};

export const SoAPrimeira: Story = {
  args: { pesagens: [{ id: 1, date: '2026-09-28', weightKg: 58.4 }] },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('início')).toBeInTheDocument();
  },
};

export const Vazio: Story = {
  args: { pesagens: [] },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).queryByRole('list')).toBeNull();
  },
};
```

Run: `docker compose run --rm web npx vitest run --project storybook src/features/progresso`
Expected: FAIL — componentes inexistentes.

- [ ] **Step 2: Implementar (marcação do protótipo `src/app/(app)/evolucao/peso/page.tsx`)**

`src/features/progresso/components/WeightStepper.tsx`:
```tsx
"use client";

import { useState } from "react";
import { IconeMais, IconeMenos } from "@/components/icons";
import { CountUp } from "@/components/ui/CountUp";
import { peso } from "@/lib/format";
import { cascata } from "@/lib/motion";
import { ajustarPeso, lerPesoDigitado, posicaoNaRegua } from "../regras";

const botao =
  "flex size-[52px] shrink-0 items-center justify-center rounded-full border-[1.5px] border-linha bg-white transition-[border-color,background-color,transform] duration-250 hover:border-tinta hover:bg-papel active:scale-90";

/** −/+ de 100 g, número que vira campo ao tocar e régua de ±1 kg em volta da última pesagem (RF23). */
export function WeightStepper({ valor, base, aoMudar }: { valor: number; base: number; aoMudar: (v: number) => void }) {
  const [digitando, setDigitando] = useState(false);
  const [texto, setTexto] = useState("");
  const umaCasa = (n: number) => n.toFixed(1).replace(".", ",");

  function confirmar() {
    aoMudar(lerPesoDigitado(texto, valor));
    setDigitando(false);
  }

  return (
    <>
      <div className="flex items-center justify-center gap-5">
        <button type="button" aria-label="Diminuir 100 gramas" onClick={() => aoMudar(ajustarPeso(valor, -0.1))} className={botao}>
          <IconeMenos size={20} />
        </button>
        {digitando ? (
          <input
            autoFocus
            inputMode="decimal"
            aria-label="Peso em quilos"
            value={texto}
            onChange={(e) => setTexto(e.target.value)}
            onBlur={confirmar}
            onKeyDown={(e) => {
              if (e.key === "Enter") confirmar();
              if (e.key === "Escape") setDigitando(false);
            }}
            className="w-[150px] rounded-2xl border border-tinta bg-white text-center font-display text-[48px] leading-none font-bold tracking-[-0.04em] tabular-nums focus:outline-none"
          />
        ) : (
          <button
            type="button"
            aria-label={`Digitar o peso: ${peso(valor)}`}
            onClick={() => {
              setTexto(umaCasa(valor));
              setDigitando(true);
            }}
            className="rounded-2xl px-1 font-display text-[58px] leading-none font-bold tracking-[-0.04em] tabular-nums"
          >
            <span aria-live="polite">
              <CountUp valor={valor} casas={1} duracao={420} />
            </span>{" "}
            <span className="text-xl font-semibold tracking-normal text-fumo">kg</span>
          </button>
        )}
        <button type="button" aria-label="Aumentar 100 gramas" onClick={() => aoMudar(ajustarPeso(valor, 0.1))} className={botao}>
          <IconeMais size={20} />
        </button>
      </div>

      <div className="relative mt-[18px] h-[38px]" aria-hidden="true">
        <div className="absolute inset-x-0 bottom-3 flex items-end justify-between">
          {Array.from({ length: 11 }, (_, i) => (
            <span key={i} className={`block w-px origin-bottom animate-entra ${i % 2 ? "h-4 bg-salvia" : "h-2.5 bg-linha"}`} style={cascata(i, 28, 260)} />
          ))}
        </div>
        <span
          data-marcador
          className="absolute bottom-1.5 block h-7 w-[3px] -translate-x-1/2 rounded-sm bg-gema transition-[left] duration-400 ease-[cubic-bezier(.34,1.56,.64,1)]"
          style={{ left: `${posicaoNaRegua(valor, base)}%` }}
        />
        <div className="absolute inset-x-0 bottom-0 flex justify-between text-[10.5px] text-fumo">
          <span>{umaCasa(base - 1)}</span>
          <span>{umaCasa(base)}</span>
          <span>{umaCasa(base + 1)}</span>
        </div>
      </div>
    </>
  );
}
```

`src/features/progresso/components/WeightDeltaMessage.tsx`:
```tsx
import type { Goal } from "@/lib/types";
import { mensagemDaDiferenca } from "../regras";

/** Comparação com a última pesagem, com a leitura certa para cada objetivo. */
export function WeightDeltaMessage({ objetivo, diferencaKg, diasDesdeUltima }: { objetivo: Goal; diferencaKg: number; diasDesdeUltima: number }) {
  return <p className="mt-3.5 border-t border-fio pt-3.5 text-[13px] leading-normal text-fumo">{mensagemDaDiferenca(objetivo, diferencaKg, diasDesdeUltima)}</p>;
}
```

`src/features/progresso/components/HistoricoPesagens.tsx`:
```tsx
import { dataPorExtenso, peso } from "@/lib/format";
import { cascata } from "@/lib/motion";
import { historico } from "../regras";
import type { Pesagem } from "../tipos";

/** "Suas pesagens": as 4 últimas com a diferença em gramas. */
export function HistoricoPesagens({ pesagens }: { pesagens: Pesagem[] }) {
  const lista = historico(pesagens);
  if (lista.length === 0) return null;
  return (
    <ul className="mt-2.5 list-none rounded-[20px] bg-white px-[18px]">
      {lista.map((p, i) => (
        <li
          key={p.date}
          style={cascata(i, 60, 360)}
          className={`flex animate-entra-lado-esq items-center gap-3 py-[13px] ${i < lista.length - 1 ? "border-b border-fio" : ""}`}
        >
          <span className="flex-1 text-sm">{dataPorExtenso(p.date).split(", ")[1]}</span>
          <span className="w-16 text-right text-sm font-semibold tabular-nums">{peso(p.weightKg)}</span>
          <span className={`w-14 text-right text-[12.5px] font-semibold ${p.deltaG !== null && p.deltaG >= 0 ? "text-mata" : "text-fumo"}`}>
            {p.deltaG === null ? "início" : `${p.deltaG >= 0 ? "+" : "−"}${Math.abs(p.deltaG)} g`}
          </span>
        </li>
      ))}
    </ul>
  );
}
```

- [ ] **Step 3: Rodar, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project storybook src/features/progresso && npm run lint && npm run typecheck"`
Expected: PASS e OK. (Se o `autoFocus` cair na regra `jsx-a11y/no-autofocus`, troque por `ref` + `useEffect(() => ref.current?.focus(), [digitando])` e registre a ruling.)

```bash
git add -A && git commit -m "feat(evolucao): stepper de peso, mensagem por objetivo e histórico

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Tela Evolução (`/evolucao`)

**Files (repo front):**
- Create: `src/features/progresso/components/EvolucaoTela.tsx`, `src/features/progresso/components/EvolucaoTela.integration.test.tsx`
- Modify: `src/app/(app)/evolucao/page.tsx` (vira só o contêiner)

**Interfaces:**
- Consumes: Tasks 1–2; `Segmento` (`@/components/ui/Field`), `ErrorState`, `Skeleton`, `Screen`, `BottomNav`, `ButtonLink`, `Reveal`.
- Produces: `EvolucaoTela` — S15 inteira.

- [ ] **Step 1: Teste que deve falhar**

`src/features/progresso/components/EvolucaoTela.integration.test.tsx`:
```tsx
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { progressoApi } from '@/mocks/fixtures/progresso';
import { erroDaApi, url } from '@/mocks/handlers/auth';
import { respondendoProgresso } from '@/mocks/handlers/progresso';
import { server } from '@/mocks/server';
import { redefinirNavegacao } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { EvolucaoTela } from './EvolucaoTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => redefinirNavegacao());
afterEach(() => localStorage.clear());

describe('Evolução (S15)', () => {
  it('mostra gráfico, constância e médias da API', async () => {
    renderizar(<EvolucaoTela />);

    expect(await screen.findByRole('img', { name: 'Peso de 56,8 kg para 58,4 kg, com meta de 62,0 kg' })).toBeInTheDocument();
    expect(screen.getByText('21 dias')).toBeInTheDocument();
    expect(screen.getByText('Você fica um pouco abaixo da meta de proteína nos dias sem treino.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Registrar peso da semana' })).toHaveAttribute('href', '/evolucao/peso');
  });

  it('trocar o período refaz a consulta e fica lembrado', async () => {
    const pedidos: string[] = [];
    server.use(
      http.get(url('/progress'), ({ request }) => {
        pedidos.push(new URL(request.url).searchParams.get('period') ?? '');
        return HttpResponse.json({ data: progressoApi() });
      }),
    );
    const usuario = userEvent.setup();

    renderizar(<EvolucaoTela />);
    await screen.findByRole('img', { name: /^Peso de/ });
    await usuario.click(within(screen.getByRole('radiogroup', { name: 'Período' })).getByRole('radio', { name: 'Tudo' }));

    await vi.waitFor(() => expect(pedidos).toEqual(['6w', 'all']));
    expect(localStorage.getItem('pf:periodo-evolucao')).toBe('all');
  });

  it('sem pesagens: estado vazio com "Registrar meu peso"', async () => {
    server.use(respondendoProgresso(progressoApi({ pontos: [] })));

    renderizar(<EvolucaoTela />);

    expect(await screen.findByRole('heading', { name: 'Sua linha começa na primeira pesagem' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Registrar meu peso' })).toHaveAttribute('href', '/evolucao/peso');
  });

  it('nenhum dia com refeição feita: médias vazias (CA06)', async () => {
    server.use(respondendoProgresso(progressoApi({ semMedias: true })));

    renderizar(<EvolucaoTela />);

    expect(await screen.findByText('Marque suas refeições para ver suas médias aqui.')).toBeInTheDocument();
  });

  it('"mais disposição": sem linha de meta nem previsão (CA07)', async () => {
    server.use(respondendoProgresso(progressoApi({ meta: null })));

    renderizar(<EvolucaoTela />);

    expect(await screen.findByRole('img', { name: 'Peso de 56,8 kg para 58,4 kg' })).toBeInTheDocument();
    expect(screen.queryByText(/chega na meta/)).toBeNull();
  });

  it('erro: ErrorState e "Tentar de novo" volta a buscar', async () => {
    let tentativas = 0;
    server.use(
      http.get(url('/progress'), () => {
        tentativas++;
        return tentativas === 1 ? erroDaApi(500, 'SERVER_ERROR', 'x') : HttpResponse.json({ data: progressoApi() });
      }),
    );

    renderizar(<EvolucaoTela />);
    expect(await screen.findByText('Não foi possível carregar sua evolução')).toBeInTheDocument();
    await userEvent.setup().click(screen.getByRole('button', { name: 'Tentar de novo' }));

    expect(await screen.findByRole('img', { name: /^Peso de/ })).toBeInTheDocument();
  });
});
```

Run: `docker compose run --rm web npx vitest run --project integration src/features/progresso/components/EvolucaoTela.integration.test.tsx`
Expected: FAIL — `EvolucaoTela` não existe.

- [ ] **Step 2: Implementar**

`src/features/progresso/components/EvolucaoTela.tsx`:
```tsx
"use client";

import { BottomNav } from "@/components/app/BottomNav";
import { ErrorState } from "@/components/app/ErrorState";
import { Screen } from "@/components/app/Screen";
import { ButtonLink } from "@/components/ui/Button";
import { Segmento } from "@/components/ui/Field";
import { Reveal } from "@/components/ui/Reveal";
import { Skeleton } from "@/components/ui/Skeleton";
import { usePeriodo, useProgresso } from "../hooks";
import type { Periodo } from "../tipos";
import { AdherenceGrid } from "./AdherenceGrid";
import { EvolucaoVazia } from "./EvolucaoVazia";
import { MediasDoPeriodo } from "./MediasDoPeriodo";
import { WeightChart } from "./WeightChart";

const PERIODOS: { valor: Periodo; rotulo: string }[] = [
  { valor: "6w", rotulo: "6 semanas" },
  { valor: "3m", rotulo: "3 meses" },
  { valor: "all", rotulo: "Tudo" },
];

/** S15 — peso, constância e médias (RF24, RF25). */
export function EvolucaoTela() {
  const [periodo, setPeriodo] = usePeriodo();
  const progresso = useProgresso(periodo);
  const dados = progresso.data;

  return (
    <Screen>
      <header className="shrink-0 px-5 pt-5 pb-3 area-segura-cima">
        <h1 className="animate-entra font-display text-[26px] font-bold tracking-[-0.025em]">Sua evolução</h1>
        <div className="mt-3">
          <Segmento label="Período" opcoes={PERIODOS} valor={periodo} onChange={setPeriodo} />
        </div>
      </header>

      <main className={`flex-1 px-5 pt-1 transition-opacity duration-300 ${progresso.isPlaceholderData ? "opacity-60" : ""}`}>
        {progresso.isError && !dados ? (
          <ErrorState
            titulo="Não foi possível carregar sua evolução"
            descricao="Suas pesagens estão salvas. Foi a conexão que falhou."
            aoTentarDeNovo={() => void progresso.refetch()}
          />
        ) : !dados ? (
          <div role="status" aria-label="Carregando sua evolução">
            <Skeleton className="h-[260px] rounded-3xl" />
            <Skeleton className="mt-4 h-[240px] rounded-3xl" />
            <Skeleton className="mt-4 h-6 w-1/2" />
            <Skeleton className="mt-3 h-10" />
          </div>
        ) : (
          <>
            {dados.weight.points.length === 0 ? <EvolucaoVazia /> : <WeightChart key={periodo} peso={dados.weight} />}
            <Reveal className="mt-3.5">
              <AdherenceGrid constancia={dados.adherence} />
            </Reveal>
            <Reveal className="mt-3.5" atraso={80}>
              <MediasDoPeriodo medias={dados.averages} />
            </Reveal>
          </>
        )}
      </main>

      <div className="shrink-0 px-5 pt-3.5 pb-2.5">
        <ButtonLink href="/evolucao/peso">Registrar peso da semana</ButtonLink>
      </div>

      <BottomNav />
    </Screen>
  );
}
```
(Confira se o `Segmento` renderiza `role="radiogroup"` com o `aria-label`/`aria-labelledby` do `label`; se o nome acessível vier diferente, ajuste a consulta do teste e registre a ruling. O `Skeleton` sem `className` de altura herda o padrão.)

`src/app/(app)/evolucao/page.tsx` (substituir inteiro):
```tsx
import { EvolucaoTela } from "@/features/progresso/components/EvolucaoTela";

export default function Evolucao() {
  return <EvolucaoTela />;
}
```

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project integration src/features/progresso && npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(evolucao): tela de evolução ligada à API (RF24, RF25)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Registrar peso (`/evolucao/peso`)

**Files (repo front):**
- Create: `src/features/progresso/components/RegistrarPesoTela.tsx`, `src/features/progresso/components/RegistrarPesoTela.integration.test.tsx`
- Modify: `src/app/(app)/evolucao/peso/page.tsx` (vira só o contêiner)

**Interfaces:**
- Consumes: Tasks 1 e 3; `usePerfil`; `TopBar`, `Screen`, `Button`, `Skeleton`, `useToast`, `comoApiError`, `dataPorExtenso`.
- Produces: `RegistrarPesoTela` — S16 inteira.

- [ ] **Step 1: Teste que deve falhar**

`src/features/progresso/components/RegistrarPesoTela.integration.test.tsx`:
```tsx
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CHAVES } from '@/lib/chaves';
import { erroDaApi, url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { redefinirNavegacao, roteador } from '@/test/next-navigation';
import { novoClienteDeTeste, renderizar } from '@/test/renderizar';
import { hojeLocal } from '../regras';
import { RegistrarPesoTela } from './RegistrarPesoTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => redefinirNavegacao());

const salvando = (registro: { corpo?: unknown; vezes: number }, atraso = 0) =>
  http.post(url('/weigh-ins'), async ({ request }) => {
    registro.vezes++;
    registro.corpo = await request.json();
    await new Promise((r) => setTimeout(r, atraso));
    return HttpResponse.json({ data: { id: 9, date: hojeLocal(), weight_kg: 58.6 }, meta: { replaced: false } }, { status: 201 });
  });

describe('Registrar peso (S16)', () => {
  it('começa na última pesagem, ajusta, compara e salva voltando para a Evolução (RF23)', async () => {
    const registro = { vezes: 0 } as { corpo?: unknown; vezes: number };
    server.use(salvando(registro));
    const cliente = novoClienteDeTeste();
    const invalidar = vi.spyOn(cliente, 'invalidateQueries');
    const usuario = userEvent.setup();

    renderizar(<RegistrarPesoTela />, cliente);
    expect(await screen.findByRole('button', { name: 'Digitar o peso: 58,4 kg' })).toBeInTheDocument();
    await usuario.click(screen.getByRole('button', { name: 'Aumentar 100 gramas' }));
    await usuario.click(screen.getByRole('button', { name: 'Aumentar 100 gramas' }));

    expect(screen.getByText('São 200 g a mais que na última pesagem. Dentro do esperado para quem está ganhando massa.')).toBeInTheDocument();
    expect(screen.getAllByRole('listitem')).toHaveLength(4);
    await usuario.click(screen.getByRole('button', { name: 'Salvar peso de hoje' }));

    await waitFor(() => expect(roteador.push).toHaveBeenCalledWith('/evolucao'));
    expect(registro.corpo).toEqual({ weight_kg: 58.6 });
    expect(invalidar.mock.calls.map(([f]) => JSON.stringify(f?.queryKey))).toContain(JSON.stringify(CHAVES.perfil));
  });

  it('duplo toque em salvar: uma requisição só', async () => {
    const registro = { vezes: 0 } as { corpo?: unknown; vezes: number };
    server.use(salvando(registro, 150));
    const usuario = userEvent.setup();

    renderizar(<RegistrarPesoTela />);
    await usuario.dblClick(await screen.findByRole('button', { name: 'Salvar peso de hoje' }));

    await waitFor(() => expect(roteador.push).toHaveBeenCalled());
    expect(registro.vezes).toBe(1);
  });

  it('já pesou hoje: avisa que vai atualizar', async () => {
    server.use(http.get(url('/weigh-ins'), () => HttpResponse.json({ data: [{ id: 1, date: hojeLocal(), weight_kg: 58.4 }] })));

    renderizar(<RegistrarPesoTela />);

    expect(await screen.findByText('Você já registrou hoje. Salvar vai atualizar o valor.')).toBeInTheDocument();
  });

  it('sem pesagens: começa no peso inicial do perfil e sem histórico', async () => {
    server.use(http.get(url('/weigh-ins'), () => HttpResponse.json({ data: [] })));

    renderizar(<RegistrarPesoTela />);

    expect(await screen.findByRole('button', { name: 'Digitar o peso: 56,8 kg' })).toBeInTheDocument();
    expect(screen.queryByRole('list')).toBeNull();
  });

  it('erro ao salvar: fica na tela, avisa e o botão volta', async () => {
    server.use(http.post(url('/weigh-ins'), () => erroDaApi(500, 'SERVER_ERROR', 'Algo deu errado do nosso lado. Tente de novo.')));
    const usuario = userEvent.setup();

    renderizar(<RegistrarPesoTela />);
    await usuario.click(await screen.findByRole('button', { name: 'Salvar peso de hoje' }));

    expect(await screen.findByText('Algo deu errado do nosso lado. Tente de novo.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Salvar peso de hoje' })).toBeEnabled();
    expect(roteador.push).not.toHaveBeenCalled();
  });
});
```

Run: `docker compose run --rm web npx vitest run --project integration src/features/progresso/components/RegistrarPesoTela.integration.test.tsx`
Expected: FAIL — `RegistrarPesoTela` não existe.

- [ ] **Step 2: Implementar**

`src/features/progresso/components/RegistrarPesoTela.tsx`:
```tsx
"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Screen } from "@/components/app/Screen";
import { TopBar } from "@/components/app/TopBar";
import { Button } from "@/components/ui/Button";
import { Skeleton } from "@/components/ui/Skeleton";
import { useToast } from "@/components/ui/Toaster";
import { usePerfil } from "@/features/perfil/hooks";
import { comoApiError } from "@/lib/api/errors";
import { dataPorExtenso } from "@/lib/format";
import { usePesagens, useRegistrarPeso } from "../hooks";
import { diasEntre, hojeLocal } from "../regras";
import { HistoricoPesagens } from "./HistoricoPesagens";
import { WeightDeltaMessage } from "./WeightDeltaMessage";
import { WeightStepper } from "./WeightStepper";

/** S16 — registrar o peso de hoje (RF23, RN34). */
export function RegistrarPesoTela() {
  const router = useRouter();
  const avisar = useToast();
  const perfil = usePerfil();
  const pesagens = usePesagens();
  const registrar = useRegistrarPeso();
  const [escolhido, setEscolhido] = useState<number | null>(null);
  const hoje = hojeLocal();

  if (!perfil.data || !pesagens.data) {
    return (
      <Screen>
        <TopBar voltarPara="/evolucao" rotuloVoltar="Voltar para a evolução" />
        <main className="flex-1 px-5 pt-3" role="status" aria-label="Carregando suas pesagens">
          <Skeleton className="h-12 w-3/4" />
          <Skeleton className="mt-6 h-[220px] rounded-3xl" />
        </main>
      </Screen>
    );
  }

  const lista = pesagens.data;
  const ultima = lista[lista.length - 1];
  const base = ultima?.weightKg ?? perfil.data.startWeightKg;
  const valor = escolhido ?? base;
  const jaPesouHoje = ultima?.date === hoje;
  const anterior = jaPesouHoje ? lista[lista.length - 2] : ultima;

  function salvar() {
    registrar.mutate(valor, {
      onSuccess: () => router.push("/evolucao"),
      onError: (e) => avisar({ texto: comoApiError(e).message }),
    });
  }

  return (
    <Screen>
      <TopBar voltarPara="/evolucao" rotuloVoltar="Voltar para a evolução" />

      <main className="flex-1 px-5 pt-2">
        <h1 className="animate-entra font-display text-[30px] leading-tight font-bold tracking-[-0.03em]">Quanto a balança marcou?</h1>
        <p className="mt-2 animate-entra text-sm leading-normal text-fumo" style={{ animationDelay: "80ms" }}>
          {dataPorExtenso(hoje)}.
        </p>
        {jaPesouHoje ? (
          <p className="mt-3 animate-entra rounded-2xl bg-gema-fraca px-4 py-3 text-[13.5px] leading-snug text-gema-texto" style={{ animationDelay: "120ms" }}>
            Você já registrou hoje. Salvar vai atualizar o valor.
          </p>
        ) : null}

        <section className="mt-[22px] animate-escala rounded-[20px] bg-white px-[18px] pt-[22px] pb-[18px]" style={{ animationDelay: "140ms" }}>
          <WeightStepper valor={valor} base={base} aoMudar={setEscolhido} />
          {anterior ? (
            <WeightDeltaMessage objetivo={perfil.data.goal} diferencaKg={valor - anterior.weightKg} diasDesdeUltima={diasEntre(anterior.date, hoje)} />
          ) : null}
        </section>

        {lista.length > 0 ? (
          <>
            <h2 className="mt-[22px] animate-entra font-display text-[15px] font-semibold" style={{ animationDelay: "300ms" }}>
              Suas pesagens
            </h2>
            <HistoricoPesagens pesagens={lista} />
          </>
        ) : null}
      </main>

      <footer className="flex shrink-0 animate-entra flex-col gap-2.5 px-5 pt-3.5 pb-7 area-segura-baixo" style={{ animationDelay: "520ms" }}>
        <Button onClick={salvar} carregando={registrar.isPending} rotuloCarregando="Salvando">
          Salvar peso de hoje
        </Button>
        <p className="text-center text-[12.5px] text-fumo">Pese-se de manhã, antes de comer, sempre na mesma balança.</p>
      </footer>
    </Screen>
  );
}
```
(A primeira comparação pede o `diasEntre(anterior.date, hoje)` — com a pesagem de hoje já registrada, compara com a anterior a ela.)

`src/app/(app)/evolucao/peso/page.tsx` (substituir inteiro):
```tsx
import { RegistrarPesoTela } from "@/features/progresso/components/RegistrarPesoTela";

export default function RegistrarPeso() {
  return <RegistrarPesoTela />;
}
```

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project integration src/features/progresso && npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(evolucao): registrar o peso de hoje ligado à API (RF23)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Tirar pesagens e constância do protótipo

**Files (repo front):**
- Modify: `src/lib/plan-store.tsx` (sai `weighIns`, `adherence`, `registrarPeso`), `src/lib/mock-api.ts` (sai `getWeighIns`, `getAdherence`, `saveWeighIn`), `src/mocks/fixtures/mock-data.ts` (sai `mockWeighIns`, `mockAdherence`), `src/lib/types.ts` (tipos sem uso)

**Interfaces:**
- Produces: `usePlan()` só com `carregando`, `erro`, `profile`, `recarregar` (Configurações, até o Plano 07).

- [ ] **Step 1: Conferir quem ainda usa**

```bash
cd /home/alvez/atividade-extensionista/frontend
for s in weighIns adherence registrarPeso getWeighIns getAdherence saveWeighIn mockWeighIns mockAdherence WeighIn DayAdherence AdherenceStatus; do
  echo "== $s"; grep -rnw "$s" src --include=*.ts --include=*.tsx | grep -v "^src/lib/mock-api.ts\|^src/lib/plan-store.tsx\|^src/lib/types.ts\|^src/mocks/fixtures/mock-data.ts" | head -3
done
```
Expected: nenhum uso fora desses arquivos.

- [ ] **Step 2: Apagar e conferir**

Tire os itens sem uso (e os `import` que sobrarem). O `PlanProvider` passa a carregar só `api.getProfile()`.

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm run build"`
Expected: tudo verde.

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "refactor(evolucao): tira pesagens e constância do protótipo

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: E2E-09 — registrar peso e ver no gráfico

**Files (repo front):**
- Create: `e2e/evolucao.spec.ts`
- Modify: `e2e/contas.ts` (tipo `'peso'` em `conta`)

**Interfaces:**
- Consumes: contas `peso-{b}` (06A Task 5: pesagens 58,4 / 58,7 / 59,0 em hoje − 21/14/7).

- [ ] **Step 1: Subir o backend do 06A e semear**

```bash
cd /home/alvez/atividade-extensionista/backend
git switch plano-06a-evolucao-api
export AI_FAKE_FAIL_PLAN_FOR=falha-chromium@e2e.pratoforte.test,falha-webkit@e2e.pratoforte.test
docker compose up -d && docker compose exec api php artisan migrate:fresh --seeder=E2ESeeder --force
```
Expected: sem erro.

- [ ] **Step 2: Escrever o E2E**

`e2e/contas.ts` — no tipo de `conta`, acrescentar `'peso'`.

`e2e/evolucao.spec.ts`:
```ts
import { expect, test } from '@playwright/test';
import { conta, entrar } from './contas';

test('registrar o peso de hoje e ver no gráfico, no Hoje e na previsão (E2E-09, CA08)', async ({ page, browserName }) => {
  await entrar(page, conta('peso', browserName));
  await expect(page).toHaveURL(/\/hoje$/);

  await page.goto('/evolucao');
  await expect(page.getByRole('img', { name: 'Peso de 58,4 kg para 59,0 kg, com meta de 62,0 kg' })).toBeVisible();

  await page.getByRole('link', { name: 'Registrar peso da semana' }).click();
  await expect(page.getByRole('button', { name: 'Digitar o peso: 59,0 kg' })).toBeVisible();
  for (let i = 0; i < 3; i++) await page.getByRole('button', { name: 'Aumentar 100 gramas' }).click();
  await expect(page.getByText('São 300 g a mais que na última pesagem. Dentro do esperado para quem está ganhando massa.')).toBeVisible();
  await page.getByRole('button', { name: 'Salvar peso de hoje' }).click();

  await expect(page).toHaveURL(/\/evolucao$/);
  await expect(page.getByRole('img', { name: 'Peso de 58,4 kg para 59,3 kg, com meta de 62,0 kg' })).toBeVisible();
  await expect(page.getByText(/^No ritmo das últimas semanas, você chega na meta por volta d/)).toBeVisible();

  await page.goto('/hoje');
  await expect(page.getByText('59,3 kg de 62,0 kg')).toBeVisible();
});
```

- [ ] **Step 3: Rodar**

Run: `docker compose run --rm web npm run e2e`
Expected: PASS em chromium e webkit (os anteriores + 1 novo em cada).

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "test(e2e): registrar peso e ver no gráfico (E2E-09)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Acabamento visual (D11) com `frontend-design`

**Files (repo front):**
- Modify: `src/features/progresso/components/*.tsx` (só visual)

- [ ] **Step 1: Refinar com o skill `frontend-design`**

Invocar `frontend-design` com: "Refinar a Evolução e o Registrar peso do Prato Forte: o ponto novo do gráfico chega com um momento de movimento que mostre o que mudou depois de salvar; a troca de período anima a linha em vez de piscar; a régua do stepper responde a cada toque; sem mudar props, textos, roles, aria nem ids; só tokens do globals.css e animações transform/opacity/stroke; reduced-motion respeitado."

- [ ] **Step 2: Suíte, build e E2E**

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm run build"` e, com o banco recém-semeado, `docker compose run --rm web npm run e2e`.
Expected: tudo verde.

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "style(evolucao): acabamento da evolução e do registrar peso (D11)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
