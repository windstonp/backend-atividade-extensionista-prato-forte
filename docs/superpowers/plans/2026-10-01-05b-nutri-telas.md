# Plano 05B — Nutri: telas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ligar o Nutri à API do Plano 05A: a tela nova de Conversas (`/nutri`) e o chat (`/nutri/{id}`) com contexto, perguntas prontas, respostas com cartões e ações, chips de continuação dinâmicos, falha/offline e a memória entre conversas — tirando o `askNutri` do mock.

**Architecture:** `src/features/nutri`: tipos, chamadas (`lib/api/nutri.ts`), regras puras (juntar páginas, pergunta de "outra opção", data relativa), hooks (TanStack Query; mensagens em `useInfiniteQuery`, pendentes locais no chat), componentes apresentacionais com stories (extraídos do protótipo `src/app/(app)/nutri/page.tsx`) e dois contêineres (`ConversasTela`, `ChatTela`). Aplicar uma ação atualiza o cache `['dia', 'today']` com o dia devolvido e reaproveita o `AvisoDeAlteracao` do 04B para o "Desfazer".

**Tech Stack:** Next 16.3.5, React 19.2, TanStack Query 5, Storybook 10.6, Vitest 4.1, MSW 2, Playwright 1.63 (repo front); repo backend só para o seeder já feito no 05A.

**Spec:** `specs/04-nutri/spec.md` (§2 RF19–RF22, §3, §4 N05 e S14, §5, §8, §9), `specs/00-fundacao/regras-de-negocio.md` (RN28, RN31, RN32, RN45), `specs/08-design-system/spec.md`. Contrato: Plano 05A (`GET/POST /conversations`, `GET/DELETE /conversations/{id}`, `GET/POST /conversations/{id}/messages`, `POST /messages/{id}/actions/{i}`, `GET /nutri/context`, `GET /nutri/suggestions`).

**Onde rodar:** front em `/home/alvez/atividade-extensionista/frontend` (`docker compose run --rm web …`); E2E com o backend no ar (`docker compose up -d` no repo backend, branch `plano-05a-nutri-api`). Branch do front `plano-05b-nutri-telas` saindo de `plano-04b-telas`.

## Decisões deste plano (rulings sobre a spec)

1. **Data da lista:** "ontem, 6 mensagens" (vírgula), não "ontem · 6 mensagens" do wireframe — mesma informação, sem o separador de meio-ponto.
2. **Carregar mais:** conversas carregam mais ao chegar ao fim da lista (sentinela com `IntersectionObserver`, com botão "Ver conversas mais antigas" de reserva); mensagens antigas por botão "Ver mensagens anteriores" no topo (mantém a posição).
3. **Offline:** `navigator.onLine` + eventos `online`/`offline`; offline antes de enviar ⇒ pergunta "Não enviada" sem chamar a API; 503 ⇒ igual; 429 ⇒ aviso com o tempo (`retry_after` ou 60 s).
4. **Toast "Desfazer" depois de aplicar:** o `AvisoDeAlteracao` do 04B, lendo `useDia()` (o cache recebe o dia da resposta da ação).
5. **Avaliação 👍/👎** fica para o Plano 08 (spec 07); os botões não aparecem agora.

## Global Constraints

- Nenhum `dangerouslySetInnerHTML`; resposta da IA é texto.
- Textos exatos: "Conversas com o Nutri", "Continue de onde parou ou comece um assunto novo. O Nutri lembra do que vocês já conversaram.", "Nova conversa", "Recentes", "Apagar", "Apagar esta conversa?", "O Nutri também esquece o que foi dito nela.", "Cancelar", "Sua pergunta: “{pergunta}”. Escolha onde perguntar.", "No que posso ajudar, {nome}?", "Pergunte como se estivesse falando com a nutricionista da academia.", "O que estou olhando agora", "Perguntas que cabem agora", "O Nutri está montando a resposta", "Não enviada", "Tentar de novo", "Sua pergunta não saiu daqui", "Sem conexão", "Olhando seu {refeição} de hoje", "Conhece seu plano e suas restrições", "Escreva sua pergunta", "Enviar pergunta", "Conversa não encontrada", "Ver conversas", "Muitas perguntas seguidas. Tente de novo em {n} segundos.", "Ver mensagens anteriores", "Ver conversas mais antigas".
- `?pergunta=` chega pré-preenchida no campo, até 200 caracteres, **sem enviar**.
- Pergunta 1–1.000 caracteres; contador acima de 900; enviar desabilitado vazio ou durante envio.
- Chips: os `follow_up_suggestions` da última resposta do Nutri; somem durante o envio e depois de uma falha.
- `aria-live` no indicador e nas mensagens novas; D11 com `frontend-design`, identidade do mock.

## Review Focus

1. **Duplo toque em "Substituir"** → uma requisição só (botão `carregando`/desabilitado); se a API responder 409, as ações somem e aparece o aviso da API (Task 5).
2. **Pergunta enviada e a pessoa sai da tela antes da resposta; volta** → a conversa mostra as duas mensagens (vêm do servidor), sem pendente fantasma (Task 5).
3. **Conversa apagada em outra aba e aberta aqui** → "Conversa não encontrada" com "Ver conversas", nunca tela quebrada (Task 5).
4. **`?pergunta=` com 5.000 caracteres ou HTML** → corta em 200 e mostra como texto (Task 5, `perguntaDaUrl`).
5. **Lista com conversa sem título (só a vazia) ou prévia nula** → a linha não quebra; vazias nem aparecem (Tasks 3–4).

---

### Task 1: Tipos, chamadas, regras, hooks e MSW do Nutri

**Files (repo front):**
- Create: `src/features/nutri/{tipos,regras,hooks}.ts`, `src/lib/api/nutri.ts`, `src/mocks/fixtures/nutri.ts`, `src/mocks/handlers/nutri.ts`
- Modify: `src/mocks/handlers/index.ts`, `src/lib/chaves.ts`
- Test: `src/features/nutri/regras.test.ts`, `src/features/nutri/hooks.integration.test.tsx`

**Interfaces:**
- Consumes: `api`, `ApiError`, `CHAVES` (Planos 02–04B), `Dia` (04B).
- Produces:
  - Tipos: `Conversa`, `MensagemUsuario`, `MensagemNutri`, `Mensagem`, `CartaoTroca`, `CartaoRefeicao`, `AcaoNutri`, `LinhaContexto`, `SugestaoPergunta`, `Pendente` (`{ id: string; content: string; status: 'enviando' | 'falhou' }`).
  - API: `getConversas(cursor?)`, `novaConversa()`, `getConversa(id)`, `apagarConversa(id)`, `getMensagens(id, cursor?)`, `perguntar(id, content)`, `resolverAcao(mensagemId, indice)`, `getContexto()`, `getSugestoes()`.
  - Regras: `juntarPaginas(paginas): Mensagem[]` (ordem crescente, sem repetidos), `perguntaDaAcao(acao, mensagem): string | null`, `quando(iso, agora): string` ("hoje", "ontem", dia da semana até 6 dias, senão "dd/mm"), `perguntaDaUrl(valor): string` (até 200, trim), `ultimasSugestoes(mensagens): string[]`.
  - Hooks: `useConversas()` (infinite), `useNovaConversa()`, `useApagarConversa()` (otimista), `useConversa(id)`, `useMensagens(id)` (infinite), `usePerguntar(id)`, `useResolverAcao(conversaId)`, `useContexto()`, `useSugestoes()`.
  - `CHAVES.conversas`, `CHAVES.conversa(id)`, `CHAVES.mensagens(id)`, `CHAVES.contextoNutri`, `CHAVES.sugestoesNutri`.
  - MSW: `conversaApi`, `mensagemUsuarioApi`, `respostaTrocaApi`, `respostaRefeicaoApi`, `contextoApi`, `sugestoesApi`; handlers padrão (lista vazia, contexto, sugestões).

- [ ] **Step 1: Branch e testes que devem falhar**

```bash
cd /home/alvez/atividade-extensionista/frontend
git switch plano-04b-telas && git switch -c plano-05b-nutri-telas
```

`src/features/nutri/regras.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { camelizar } from '@/lib/api/case';
import { mensagemUsuarioApi, respostaRefeicaoApi, respostaTrocaApi } from '@/mocks/fixtures/nutri';
import { juntarPaginas, perguntaDaAcao, perguntaDaUrl, quando, ultimasSugestoes } from './regras';
import type { Mensagem, MensagemNutri } from './tipos';

const m = <T,>(v: unknown) => camelizar<T>(v);

describe('juntarPaginas', () => {
  it('páginas vêm mais recentes primeiro; o chat mostra em ordem e sem repetir', () => {
    const p1 = [m<Mensagem>(respostaTrocaApi(4)), m<Mensagem>(mensagemUsuarioApi(3))];
    const p2 = [m<Mensagem>(mensagemUsuarioApi(3)), m<Mensagem>(respostaTrocaApi(2)), m<Mensagem>(mensagemUsuarioApi(1))];

    expect(juntarPaginas([p1, p2]).map((x) => x.id)).toEqual([1, 2, 3, 4]);
  });
});

describe('perguntaDaAcao', () => {
  it('"outra opção" vira pergunta; o resto não', () => {
    const troca = m<MensagemNutri>(respostaTrocaApi(2));
    const refeicao = m<MensagemNutri>(respostaRefeicaoApi(3));
    expect(perguntaDaAcao(troca.actions[1], troca)).toBe('Quero ver outras opções');
    expect(perguntaDaAcao(refeicao.actions[1], refeicao)).toBe('Monte outra opção de jantar');
    expect(perguntaDaAcao(troca.actions[0], troca)).toBeNull();
  });
});

describe('quando', () => {
  const agora = new Date('2026-10-01T15:00:00-03:00');
  it.each([
    ['2026-10-01T09:00:00-03:00', 'hoje'],
    ['2026-09-30T22:00:00-03:00', 'ontem'],
    ['2026-09-28T10:00:00-03:00', 'segunda'],
    ['2026-09-20T10:00:00-03:00', '20/09'],
  ])('%s → %s', (iso, esperado) => expect(quando(iso, agora)).toBe(esperado));
});

describe('perguntaDaUrl e ultimasSugestoes', () => {
  it('corta em 200 e tira espaços', () => {
    expect(perguntaDaUrl(`  ${'a'.repeat(5000)}  `)).toHaveLength(200);
    expect(perguntaDaUrl(null)).toBe('');
    expect(perguntaDaUrl('<b>oi</b>')).toBe('<b>oi</b>'); // vira texto no campo, nunca HTML
  });

  it('chips são os da última resposta do Nutri', () => {
    const lista = juntarPaginas([[m<Mensagem>(respostaTrocaApi(2)), m<Mensagem>(mensagemUsuarioApi(1))]]);
    expect(ultimasSugestoes(lista)).toEqual(['E no jantar, o que como?', 'Por que a batata segura mais a fome?']);
    expect(ultimasSugestoes([])).toEqual([]);
  });
});
```

`src/features/nutri/hooks.integration.test.tsx`:
```tsx
import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { Toaster } from '@/components/ui/Toaster';
import { conversaApi } from '@/mocks/fixtures/nutri';
import { url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { novoClienteDeTeste } from '@/test/renderizar';
import { useApagarConversa, useConversas, useNovaConversa } from './hooks';

function comCliente() {
  const cliente = novoClienteDeTeste();
  const wrapper = ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={cliente}>
      <Toaster>{children}</Toaster>
    </QueryClientProvider>
  );
  return { wrapper };
}

describe('hooks do Nutri', () => {
  it('lista as conversas e segue o cursor', async () => {
    server.use(
      http.get(url('/conversations'), ({ request }) => {
        const cursor = new URL(request.url).searchParams.get('cursor');
        return HttpResponse.json(
          cursor
            ? { data: [conversaApi(1)], meta: { next_cursor: null, per_page: 15 } }
            : { data: [conversaApi(3), conversaApi(2)], meta: { next_cursor: 'abc', per_page: 15 } },
        );
      }),
    );
    const { result } = renderHook(() => useConversas(), comCliente());

    await waitFor(() => expect(result.current.data?.pages).toHaveLength(1));
    await act(() => result.current.fetchNextPage());

    expect(result.current.data!.pages.flatMap((p) => p.data).map((c) => c.id)).toEqual([3, 2, 1]);
    expect(result.current.hasNextPage).toBe(false);
  });

  it('nova conversa devolve o id (201 ou 200)', async () => {
    server.use(http.post(url('/conversations'), () => HttpResponse.json({ data: conversaApi(9, { vazia: true }) }, { status: 200 })));
    const { result } = renderHook(() => useNovaConversa(), comCliente());

    let id = 0;
    await act(async () => {
      id = (await result.current.mutateAsync()).id;
    });

    expect(id).toBe(9);
  });

  it('apagar tira da lista na hora e volta se falhar', async () => {
    server.use(
      http.get(url('/conversations'), () => HttpResponse.json({ data: [conversaApi(3), conversaApi(2)], meta: { next_cursor: null, per_page: 15 } })),
      http.delete(url('/conversations/3'), () => HttpResponse.error()),
    );
    const { wrapper } = comCliente();
    const { result } = renderHook(() => ({ lista: useConversas(), apagar: useApagarConversa() }), { wrapper });
    await waitFor(() => expect(result.current.lista.data).toBeDefined());

    act(() => result.current.apagar.mutate(3));
    await waitFor(() => expect(result.current.lista.data!.pages[0].data.map((c) => c.id)).toEqual([2]));
    await waitFor(() => expect(result.current.apagar.isError).toBe(true));
    await waitFor(() => expect(result.current.lista.data!.pages[0].data.map((c) => c.id)).toEqual([3, 2]));
  });
});
```

Run: `docker compose run --rm web npx vitest run --project unit --project integration src/features/nutri`
Expected: FAIL — módulos inexistentes.

- [ ] **Step 2: Implementar**

`src/features/nutri/tipos.ts`:
```ts
import type { Macros } from '@/lib/types';
import type { Slot } from '@/features/dia/tipos';

/** `GET /conversations` (spec 04 §5), já em camelCase. */
export interface Conversa {
  id: number;
  title: string | null;
  preview: string | null;
  messageCount: number;
  lastMessageAt: string | null;
}

export interface MensagemUsuario {
  id: number;
  role: 'user';
  content: string;
  createdAt: string;
}

export interface CartaoTroca {
  type: 'swap';
  slot: Slot;
  from: { foodId: number; name: string; amount: string; calories: number };
  to: { foodId: number; grams: number; name: string; amount: string; calories: number };
  carbsBefore: number;
  carbsAfter: number;
  calorieDelta: number;
}

export interface CartaoRefeicao {
  type: 'meal';
  slot: Slot;
  title: string;
  time: string;
  calories: number;
  macros: Macros;
  items: { foodId: number; grams: number; name: string; amount: string; calories: number }[];
  warning: string | null;
}

export interface AcaoNutri {
  index: number;
  kind: 'substituir' | 'aplicar-refeicao' | 'outra-opcao' | 'ver-refeicao' | 'dispensar';
  label: string;
  slot?: Slot;
}

export interface MensagemNutri {
  id: number;
  role: 'assistant';
  content: string;
  createdAt: string;
  followUp: string | null;
  followUpSuggestions: string[];
  card: CartaoTroca | CartaoRefeicao | null;
  actions: AcaoNutri[];
  actionsAvailable: boolean;
  rating: null;
}

export type Mensagem = MensagemUsuario | MensagemNutri;

/** Pergunta que ainda não virou mensagem do servidor. */
export interface Pendente {
  id: string;
  content: string;
  status: 'enviando' | 'falhou';
}

export interface LinhaContexto {
  text: string;
  tone: 'gema' | 'alerta' | 'mata';
}

export interface SugestaoPergunta {
  id: string;
  question: string;
}

export interface Pagina<T> {
  data: T[];
  meta: { nextCursor: string | null; perPage: number };
}
```

`src/lib/api/nutri.ts`:
```ts
import type { Dia } from '@/features/dia/tipos';
import type { Conversa, LinhaContexto, Mensagem, MensagemNutri, MensagemUsuario, Pagina, SugestaoPergunta } from '@/features/nutri/tipos';
import { api } from './client';

type Dados<T> = { data: T };
const comCursor = (caminho: string, cursor?: string | null) => (cursor ? `${caminho}?cursor=${encodeURIComponent(cursor)}` : caminho);

/** GET /conversations — 15 por página, mais recentes primeiro. */
export const getConversas = (cursor?: string | null) => api<Pagina<Conversa>>(comCursor('/conversations', cursor));

/** POST /conversations — reaproveita a vazia (200) ou cria (201). */
export const novaConversa = () => api<Dados<Conversa>>('/conversations', { method: 'POST' }).then((r) => r.data);

export const getConversa = (id: number) => api<Dados<Conversa>>(`/conversations/${id}`).then((r) => r.data);

export const apagarConversa = (id: number) => api<void>(`/conversations/${id}`, { method: 'DELETE' });

/** GET /conversations/{id}/messages — 30 por página, mais recentes primeiro. */
export const getMensagens = (id: number, cursor?: string | null) => api<Pagina<Mensagem>>(comCursor(`/conversations/${id}/messages`, cursor));

/** POST /conversations/{id}/messages — as duas mensagens gravadas. */
export const perguntar = (id: number, content: string) =>
  api<Dados<{ userMessage: MensagemUsuario; assistantMessage: MensagemNutri }>>(`/conversations/${id}/messages`, {
    method: 'POST',
    body: { content },
  }).then((r) => r.data);

/** POST /messages/{id}/actions/{i} — aplicar ou dispensar. */
export const resolverAcao = (mensagemId: number, indice: number) =>
  api<Dados<{ message: { id: number }; confirmation?: MensagemNutri; day?: Dia }>>(`/messages/${mensagemId}/actions/${indice}`, {
    method: 'POST',
  }).then((r) => r.data);

export const getContexto = () => api<Dados<{ lines: LinhaContexto[] }>>('/nutri/context').then((r) => r.data.lines);

export const getSugestoes = () => api<Dados<SugestaoPergunta[]>>('/nutri/suggestions').then((r) => r.data);
```

`src/lib/chaves.ts` — acrescentar ao objeto:
```ts
  conversas: ['conversas'],
  conversa: (id: number) => ['conversa', id] as const,
  mensagens: (id: number) => ['mensagens', id] as const,
  contextoNutri: ['nutri', 'contexto'],
  sugestoesNutri: ['nutri', 'sugestoes'],
```

`src/features/nutri/regras.ts`:
```ts
import type { AcaoNutri, Mensagem, MensagemNutri } from './tipos';

/** Páginas chegam mais recentes primeiro; o chat mostra da mais antiga para a mais nova, sem repetir. */
export function juntarPaginas(paginas: Mensagem[][]): Mensagem[] {
  const porId = new Map<number, Mensagem>();
  for (const pagina of paginas) for (const m of pagina) porId.set(m.id, porId.get(m.id) ?? m);
  return [...porId.values()].sort((a, b) => a.id - b.id);
}

/** "Ver outras opções"/"Gerar outra opção" viram uma pergunta nova (RF21). */
export function perguntaDaAcao(acao: AcaoNutri, mensagem: MensagemNutri): string | null {
  if (acao.kind !== 'outra-opcao') return null;
  return mensagem.card?.type === 'meal' ? `Monte outra opção de ${mensagem.card.title.toLowerCase()}` : 'Quero ver outras opções';
}

const DIAS = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
const diaLocal = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();

/** "hoje", "ontem", o dia da semana até 6 dias, depois "dd/mm". */
export function quando(iso: string, agora = new Date()): string {
  const data = new Date(iso);
  const dias = Math.round((diaLocal(agora) - diaLocal(data)) / 86_400_000);
  if (dias <= 0) return 'hoje';
  if (dias === 1) return 'ontem';
  if (dias < 7) return DIAS[data.getDay()];
  return `${String(data.getDate()).padStart(2, '0')}/${String(data.getMonth() + 1).padStart(2, '0')}`;
}

/** `?pergunta=` vira texto do campo: até 200 caracteres. */
export const perguntaDaUrl = (valor: string | null) => (valor ?? '').trim().slice(0, 200);

/** RN45 — os chips são sempre os da última resposta do Nutri. */
export function ultimasSugestoes(mensagens: Mensagem[]): string[] {
  for (let i = mensagens.length - 1; i >= 0; i--) {
    const m = mensagens[i];
    if (m.role === 'assistant') return m.followUpSuggestions ?? [];
  }
  return [];
}
```

`src/features/nutri/hooks.ts`:
```ts
'use client';

import { type InfiniteData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Dia } from '@/features/dia/tipos';
import * as nutri from '@/lib/api/nutri';
import { CHAVES } from '@/lib/chaves';
import type { Conversa, Mensagem, MensagemNutri, Pagina } from './tipos';

export const useConversas = () =>
  useInfiniteQuery({
    queryKey: CHAVES.conversas,
    queryFn: ({ pageParam }) => nutri.getConversas(pageParam),
    initialPageParam: null as string | null,
    getNextPageParam: (ultima) => ultima.meta.nextCursor,
  });

export function useNovaConversa() {
  const cliente = useQueryClient();
  return useMutation({
    mutationFn: nutri.novaConversa,
    onSuccess: () => void cliente.invalidateQueries({ queryKey: CHAVES.conversas }),
  });
}

/** Some da lista na hora; volta se a API falhar. */
export function useApagarConversa() {
  const cliente = useQueryClient();
  return useMutation({
    mutationFn: nutri.apagarConversa,
    onMutate: async (id: number) => {
      await cliente.cancelQueries({ queryKey: CHAVES.conversas });
      const antes = cliente.getQueryData<InfiniteData<Pagina<Conversa>>>(CHAVES.conversas);
      if (antes) {
        cliente.setQueryData<InfiniteData<Pagina<Conversa>>>(CHAVES.conversas, {
          ...antes,
          pages: antes.pages.map((p) => ({ ...p, data: p.data.filter((c) => c.id !== id) })),
        });
      }
      return { antes };
    },
    onError: (_e, _id, contexto) => {
      if (contexto?.antes) cliente.setQueryData(CHAVES.conversas, contexto.antes);
    },
  });
}

export const useConversa = (id: number) => useQuery({ queryKey: CHAVES.conversa(id), queryFn: () => nutri.getConversa(id), retry: false });

export const useMensagens = (id: number) =>
  useInfiniteQuery({
    queryKey: CHAVES.mensagens(id),
    queryFn: ({ pageParam }) => nutri.getMensagens(id, pageParam),
    initialPageParam: null as string | null,
    getNextPageParam: (ultima) => ultima.meta.nextCursor,
    retry: false,
  });

/** Põe mensagens novas no começo da primeira página (a mais recente). */
function acrescentar(cliente: ReturnType<typeof useQueryClient>, conversaId: number, novas: Mensagem[]) {
  cliente.setQueryData<InfiniteData<Pagina<Mensagem>>>(CHAVES.mensagens(conversaId), (atual) => {
    if (!atual) return atual;
    const [primeira, ...resto] = atual.pages;
    return { ...atual, pages: [{ ...primeira, data: [...[...novas].reverse(), ...primeira.data] }, ...resto] };
  });
}

export function usePerguntar(conversaId: number) {
  const cliente = useQueryClient();
  return useMutation({
    mutationFn: (content: string) => nutri.perguntar(conversaId, content),
    onSuccess: ({ userMessage, assistantMessage }) => {
      acrescentar(cliente, conversaId, [userMessage, assistantMessage]);
      void cliente.invalidateQueries({ queryKey: CHAVES.conversas });
    },
  });
}

/** Aplicar/dispensar: as ações da mensagem somem; a confirmação entra; o dia de hoje vem atualizado. */
export function useResolverAcao(conversaId: number) {
  const cliente = useQueryClient();
  return useMutation({
    mutationFn: ({ mensagem, indice }: { mensagem: MensagemNutri; indice: number }) => nutri.resolverAcao(mensagem.id, indice),
    onSuccess: ({ confirmation, day }, { mensagem }) => {
      cliente.setQueryData<InfiniteData<Pagina<Mensagem>>>(CHAVES.mensagens(conversaId), (atual) =>
        atual && {
          ...atual,
          pages: atual.pages.map((p) => ({
            ...p,
            data: p.data.map((m) => (m.id === mensagem.id && m.role === 'assistant' ? { ...m, actions: [], actionsAvailable: false } : m)),
          })),
        },
      );
      if (confirmation) acrescentar(cliente, conversaId, [confirmation]);
      if (day) cliente.setQueryData<Dia>(CHAVES.dia('today'), day);
    },
    onError: () => void cliente.invalidateQueries({ queryKey: CHAVES.mensagens(conversaId) }),
  });
}

export const useContexto = () => useQuery({ queryKey: CHAVES.contextoNutri, queryFn: nutri.getContexto });
export const useSugestoes = () => useQuery({ queryKey: CHAVES.sugestoesNutri, queryFn: nutri.getSugestoes });
```

`src/mocks/fixtures/nutri.ts`:
```ts
/** Conversas e mensagens como a API devolve (snake_case). */

export function conversaApi(id: number, opcoes: { vazia?: boolean; titulo?: string } = {}) {
  return opcoes.vazia
    ? { id, title: null, preview: null, message_count: 0, last_message_at: null }
    : {
        id,
        title: opcoes.titulo ?? 'Posso trocar o arroz por batata?',
        preview: 'Pode. No seu almoço os 150 g de arroz entram com 42 g de carboidrato…',
        message_count: 6,
        last_message_at: '2026-09-30T12:10:00-03:00',
      };
}

export const mensagemUsuarioApi = (id: number, content = 'Posso trocar o arroz por batata?') => ({
  id,
  role: 'user',
  content,
  created_at: '2026-10-01T11:02:00-03:00',
});

export function respostaTrocaApi(id: number, opcoes: { acoes?: boolean } = {}) {
  const acoes = opcoes.acoes ?? true;
  return {
    id,
    role: 'assistant',
    content: 'Pode. No seu almoço os 150 g de arroz entram com 42 g de carboidrato.',
    created_at: '2026-10-01T11:02:07-03:00',
    follow_up: 'A batata-doce tem mais fibra e segura a fome até o treino.',
    follow_up_suggestions: ['E no jantar, o que como?', 'Por que a batata segura mais a fome?'],
    card: {
      type: 'swap',
      slot: 'almoco',
      from: { food_id: 28, name: 'Arroz branco cozido', amount: '150 g, mais ou menos 6 colheres de sopa', calories: 192 },
      to: { food_id: 30, grams: 230, name: 'Batata-doce cozida', amount: '230 g, mais ou menos 1,5 unidades médias', calories: 177 },
      carbs_before: 42.2,
      carbs_after: 42.3,
      calorie_delta: -15,
    },
    actions: acoes
      ? [
          { index: 0, kind: 'substituir', label: 'Substituir no almoço de hoje', slot: 'almoco' },
          { index: 1, kind: 'outra-opcao', label: 'Ver outras opções' },
          { index: 2, kind: 'dispensar', label: 'Agora não' },
        ]
      : [],
    actions_available: acoes,
    rating: null,
  };
}

export function respostaRefeicaoApi(id: number) {
  return {
    id,
    role: 'assistant',
    content: 'Montei um jantar leve com o que costuma ter em casa.',
    created_at: '2026-10-01T11:05:00-03:00',
    follow_up: null,
    follow_up_suggestions: ['E se eu treinar à noite?'],
    card: {
      type: 'meal',
      slot: 'jantar',
      title: 'Jantar',
      time: '20:30',
      calories: 375,
      macros: { protein: 24.1, carbs: 31.2, fat: 14.6 },
      items: [
        { food_id: 1, grams: 100, name: 'Ovos cozidos', amount: '100 g, mais ou menos 2 unidades', calories: 146 },
        { food_id: 50, grams: 80, name: 'Brócolis no vapor', amount: '80 g, mais ou menos 4 ramos', calories: 20 },
        { food_id: 30, grams: 100, name: 'Batata-doce cozida', amount: '100 g', calories: 77 },
      ],
      warning: 'Fica 8 g de proteína abaixo do jantar original.',
    },
    actions: [
      { index: 0, kind: 'aplicar-refeicao', label: 'Aplicar no jantar de hoje', slot: 'jantar' },
      { index: 1, kind: 'outra-opcao', label: 'Gerar outra opção' },
      { index: 2, kind: 'dispensar', label: 'Agora não' },
    ],
    actions_available: true,
    rating: null,
  };
}

export const contextoApi = [
  { text: 'Seu almoço das 12:30, com arroz branco cozido, feijão carioca, frango grelhado e salada', tone: 'gema' },
  { text: '1.250 kcal e 85 g de proteína ainda no plano de hoje', tone: 'gema' },
  { text: 'Sua alergia a amendoim e castanhas', tone: 'alerta' },
  { text: 'Seu objetivo de ganhar massa magra, com meta de 62 kg', tone: 'mata' },
];

export const sugestoesApi = [
  { id: 'trocar-carbo', question: 'Posso trocar o arroz branco cozido por outra coisa?' },
  { id: 'sem-proteina', question: 'Não tenho frango grelhado em casa. O que uso no lugar?' },
  { id: 'pre-treino', question: 'O que comer antes do treino das 19:00?' },
];
```

`src/mocks/handlers/nutri.ts`:
```ts
import { http, HttpResponse } from 'msw';
import { contextoApi, sugestoesApi } from '../fixtures/nutri';
import { url } from './auth';

/** Padrão: sem conversas; contexto e sugestões da Camila. */
export const handlersNutri = [
  http.get(url('/conversations'), () => HttpResponse.json({ data: [], meta: { next_cursor: null, per_page: 15 } })),
  http.get(url('/nutri/context'), () => HttpResponse.json({ data: { lines: contextoApi } })),
  http.get(url('/nutri/suggestions'), () => HttpResponse.json({ data: sugestoesApi })),
];

/** `GET /conversations/{id}/messages` com estas mensagens (mais recentes primeiro). */
export const respondendoMensagens = (id: number, mensagens: unknown[], proximo: string | null = null) =>
  http.get(url(`/conversations/${id}/messages`), () => HttpResponse.json({ data: mensagens, meta: { next_cursor: proximo, per_page: 30 } }));
```

`src/mocks/handlers/index.ts` — acrescentar `handlersNutri` (import de `./nutri`) ao array.

- [ ] **Step 3: Rodar e ver passar; suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project unit --project integration src/features/nutri && npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(nutri): tipos, chamadas, regras, hooks e MSW do Nutri

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Componentes das mensagens — `ChatBubble`, `SwapCard`, `MealSuggestionCard`, `NutriActions`, `ThinkingIndicator`, `OfflineNotice`

**Files (repo front):**
- Create: `src/features/nutri/components/{ChatBubble,SwapCard,MealSuggestionCard,NutriActions,ThinkingIndicator,OfflineNotice}.tsx` e stories `{ChatBubble,SwapCard,MealSuggestionCard,NutriActions,ThinkingIndicator}.stories.tsx`

**Interfaces:**
- Consumes: tipos da Task 1; `MarcaNutri`, ícones; `kcal`, `gramas`, `cascata`.
- Produces:
  - `PerguntaBubble({ texto, status?, aoReenviar? })` e `RespostaBubble({ mensagem, aplicando?, aoAgir })` em `ChatBubble.tsx` (`aoAgir(acao)`; a resposta inclui cartão, aviso, segundo parágrafo e ações).
  - `SwapCard({ cartao })` — leitura para leitor de tela "De arroz branco cozido para batata-doce cozida".
  - `MealSuggestionCard({ cartao })`.
  - `NutriActions({ acoes, aplicando, aoAgir })` — `ver-refeicao` é link para `/dieta/{slot}`; `substituir`/`aplicar-refeicao` com `carregando` quando `aplicando`; lista vazia não renderiza.
  - `ThinkingIndicator()` — `role="status"`, "O Nutri está montando a resposta".
  - `OfflineNotice()`.

- [ ] **Step 1: Stories que devem falhar**

`src/features/nutri/components/ChatBubble.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { camelizar } from '@/lib/api/case';
import { respostaRefeicaoApi, respostaTrocaApi } from '@/mocks/fixtures/nutri';
import type { MensagemNutri } from '../tipos';
import { PerguntaBubble, RespostaBubble } from './ChatBubble';

const meta = { title: 'Nutri/ChatBubble', component: PerguntaBubble, args: { texto: 'Posso trocar o arroz por batata?' } } satisfies Meta<typeof PerguntaBubble>;
export default meta;
type Story = StoryObj<typeof meta>;

export const Usuario: Story = {
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('Posso trocar o arroz por batata?')).toBeInTheDocument();
  },
};

export const UsuarioEnviando: Story = {
  args: { status: 'enviando' },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).queryByText('Não enviada')).toBeNull();
  },
};

export const UsuarioFalhou: Story = {
  args: { status: 'falhou', aoReenviar: fn() },
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await expect(tela.getByText('Não enviada')).toBeInTheDocument();
    await userEvent.click(tela.getByRole('button', { name: 'Tentar de novo' }));
    await expect(args.aoReenviar).toHaveBeenCalled();
  },
};

export const Assistente: Story = {
  render: () => <RespostaBubble mensagem={camelizar<MensagemNutri>(respostaTrocaApi(2, { acoes: false }))} aoAgir={fn()} />,
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByText(/No seu almoço os 150 g de arroz/)).toBeInTheDocument();
    await expect(tela.queryByRole('button')).toBeNull();
  },
};

export const AssistenteComFollowUp: Story = {
  render: () => <RespostaBubble mensagem={camelizar<MensagemNutri>(respostaTrocaApi(2))} aoAgir={fn()} />,
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('A batata-doce tem mais fibra e segura a fome até o treino.')).toBeInTheDocument();
  },
};

export const AssistenteComRefeicao: Story = {
  render: () => <RespostaBubble mensagem={camelizar<MensagemNutri>(respostaRefeicaoApi(3))} aoAgir={fn()} />,
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('Fica 8 g de proteína abaixo do jantar original.')).toBeInTheDocument();
  },
};
```

`src/features/nutri/components/SwapCard.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, within } from 'storybook/test';
import { camelizar } from '@/lib/api/case';
import { respostaTrocaApi } from '@/mocks/fixtures/nutri';
import type { CartaoTroca, MensagemNutri } from '../tipos';
import { SwapCard } from './SwapCard';

const cartao = camelizar<MensagemNutri>(respostaTrocaApi(2)).card as CartaoTroca;

const meta = { title: 'Nutri/SwapCard', component: SwapCard, args: { cartao } } satisfies Meta<typeof SwapCard>;
export default meta;
type Story = StoryObj<typeof meta>;

export const ReducaoDeKcal: Story = {
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByRole('group', { name: 'De arroz branco cozido para batata-doce cozida' })).toBeInTheDocument();
    await expect(tela.getByText('−15 kcal no dia')).toHaveClass('text-mata');
    await expect(tela.getByText('42,2 g para 42,3 g')).toBeInTheDocument();
  },
};

export const Aumento: Story = {
  args: { cartao: { ...cartao, calorieDelta: 20 } },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('+20 kcal no dia')).toHaveClass('text-fumo');
  },
};
```

`src/features/nutri/components/MealSuggestionCard.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, within } from 'storybook/test';
import { camelizar } from '@/lib/api/case';
import { respostaRefeicaoApi } from '@/mocks/fixtures/nutri';
import type { CartaoRefeicao, MensagemNutri } from '../tipos';
import { MealSuggestionCard } from './MealSuggestionCard';

const cartao = camelizar<MensagemNutri>(respostaRefeicaoApi(3)).card as CartaoRefeicao;

const meta = { title: 'Nutri/MealSuggestionCard', component: MealSuggestionCard, args: { cartao } } satisfies Meta<typeof MealSuggestionCard>;
export default meta;
type Story = StoryObj<typeof meta>;

export const ComAviso: Story = {
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByText('Jantar, 20:30')).toBeInTheDocument();
    await expect(tela.getAllByRole('listitem')).toHaveLength(3);
    await expect(tela.getByText('Fica 8 g de proteína abaixo do jantar original.')).toBeInTheDocument();
  },
};

export const SemAviso: Story = {
  args: { cartao: { ...cartao, warning: null } },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).queryByText(/proteína abaixo/)).toBeNull();
  },
};
```

`src/features/nutri/components/NutriActions.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { camelizar } from '@/lib/api/case';
import { respostaRefeicaoApi, respostaTrocaApi } from '@/mocks/fixtures/nutri';
import type { MensagemNutri } from '../tipos';
import { NutriActions } from './NutriActions';

const troca = camelizar<MensagemNutri>(respostaTrocaApi(2)).actions;
const refeicao = camelizar<MensagemNutri>(respostaRefeicaoApi(3)).actions;

const meta = { title: 'Nutri/NutriActions', component: NutriActions, args: { acoes: troca, aplicando: false, aoAgir: fn() } } satisfies Meta<typeof NutriActions>;
export default meta;
type Story = StoryObj<typeof meta>;

export const SubstituirOutraDispensar: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await userEvent.click(tela.getByRole('button', { name: 'Substituir no almoço de hoje' }));
    await expect(args.aoAgir).toHaveBeenCalledWith(troca[0]);
    await expect(tela.getByRole('button', { name: 'Ver outras opções' })).toBeInTheDocument();
    await expect(tela.getByRole('button', { name: 'Agora não' })).toBeInTheDocument();
  },
};

export const Aplicar: Story = {
  args: { acoes: refeicao },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('button', { name: 'Aplicar no jantar de hoje' })).toBeInTheDocument();
  },
};

export const VerRefeicao: Story = {
  args: { acoes: [{ index: 0, kind: 'ver-refeicao', label: 'Ver a refeição', slot: 'almoco' }] },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('link', { name: 'Ver a refeição' })).toHaveAttribute('href', '/dieta/almoco');
  },
};

export const Aplicando: Story = {
  args: { aplicando: true },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByRole('button', { name: /Substituir no almoço de hoje/ })).toHaveAttribute('aria-busy', 'true');
    await expect(tela.getByRole('button', { name: 'Agora não' })).toBeDisabled();
  },
};

export const Indisponivel: Story = {
  args: { acoes: [] },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).queryByRole('button')).toBeNull();
  },
};
```

`src/features/nutri/components/ThinkingIndicator.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, within } from 'storybook/test';
import { ThinkingIndicator } from './ThinkingIndicator';

const meta = { title: 'Nutri/ThinkingIndicator', component: ThinkingIndicator } satisfies Meta<typeof ThinkingIndicator>;
export default meta;
type Story = StoryObj<typeof meta>;

export const Anuncia: Story = {
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('status')).toHaveTextContent('O Nutri está montando a resposta');
  },
};
```

Run: `docker compose run --rm web npx vitest run --project storybook src/features/nutri`
Expected: FAIL — componentes inexistentes.

- [ ] **Step 2: Implementar (marcação do protótipo `src/app/(app)/nutri/page.tsx`)**

`src/features/nutri/components/SwapCard.tsx`:
```tsx
import { IconeSeta } from "@/components/icons";
import { gramas, kcal } from "@/lib/format";
import type { CartaoTroca } from "../tipos";

const delta = (n: number) => `${n > 0 ? "+" : n < 0 ? "−" : ""}${Math.abs(Math.round(n))} kcal no dia`;

/** Troca proposta: de → para, com números do catálogo (RN31). */
export function SwapCard({ cartao }: { cartao: CartaoTroca }) {
  return (
    <div
      role="group"
      aria-label={`De ${cartao.from.name.toLowerCase()} para ${cartao.to.name.toLowerCase()}`}
      className="mt-3 animate-escala rounded-[18px] bg-white px-4 py-3.5"
      style={{ animationDelay: "160ms" }}
    >
      <div className="flex items-center gap-2.5">
        <div className="flex-1">
          <p className="text-[13.5px] font-semibold">{cartao.from.name}</p>
          <p className="mt-0.5 text-xs text-fumo">
            {cartao.from.amount}, {kcal(cartao.from.calories)}
          </p>
        </div>
        <IconeSeta size={20} className="shrink-0 animate-entra-lado text-fumo" style={{ animationDelay: "320ms" }} />
        <div className="flex-1 text-right">
          <p className="text-[13.5px] font-semibold">{cartao.to.name}</p>
          <p className="mt-0.5 text-xs text-fumo">
            {cartao.to.amount}, {kcal(cartao.to.calories)}
          </p>
        </div>
      </div>
      <div className="mt-3 flex flex-wrap gap-3.5 border-t border-fio pt-3">
        <span className="text-[12.5px] text-fumo">
          Carboidrato{" "}
          <b className="font-semibold text-tinta">
            {gramas(cartao.carbsBefore)} para {gramas(cartao.carbsAfter)}
          </b>
        </span>
        <span className={`text-[12.5px] font-semibold ${cartao.calorieDelta <= 0 ? "text-mata" : "text-fumo"}`}>
          {delta(cartao.calorieDelta)}
        </span>
      </div>
    </div>
  );
}
```
(`gramas(42.2)` do `@/lib/format` precisa dar "42,2 g" — confira; se der "42 g", mostre com uma casa usando `toLocaleString('pt-BR', { maximumFractionDigits: 1 })` e ajuste a story; registre a ruling.)

`src/features/nutri/components/MealSuggestionCard.tsx`:
```tsx
import { gramas, kcal } from "@/lib/format";
import { cascata } from "@/lib/motion";
import type { CartaoRefeicao } from "../tipos";

/** Refeição inteira proposta pelo Nutri (RN31). */
export function MealSuggestionCard({ cartao }: { cartao: CartaoRefeicao }) {
  return (
    <>
      <div className="mt-3 animate-escala rounded-[18px] bg-white px-4 py-3.5" style={{ animationDelay: "160ms" }}>
        <div className="flex items-baseline justify-between">
          <p className="font-display text-[17px] font-bold tracking-[-0.02em]">
            {cartao.title}, {cartao.time}
          </p>
          <span className="text-[13px] font-semibold">{kcal(cartao.calories)}</span>
        </div>
        <ul className="mt-2 list-none">
          {cartao.items.map((item, i) => (
            <li
              key={`${item.foodId}-${i}`}
              style={cascata(i, 90, 320)}
              className={`flex animate-entra-lado-esq items-baseline gap-2.5 py-2.5 ${i < cartao.items.length - 1 ? "border-b border-fio" : ""}`}
            >
              <span className="flex-1 text-sm font-medium">{item.name}</span>
              <span className="text-[12.5px] text-fumo">{item.amount}</span>
              <span className="w-[58px] text-right text-[12.5px] font-semibold">{kcal(item.calories)}</span>
            </li>
          ))}
        </ul>
        <div className="mt-3 flex gap-3.5 border-t border-fio pt-3">
          <span className="text-[12.5px] text-fumo">
            {gramas(cartao.macros.protein)} <b className="font-semibold text-tinta">proteína</b>
          </span>
          <span className="text-[12.5px] text-fumo">
            {gramas(cartao.macros.carbs)} <b className="font-semibold text-tinta">carbo</b>
          </span>
          <span className="text-[12.5px] text-fumo">
            {gramas(cartao.macros.fat)} <b className="font-semibold text-tinta">gordura</b>
          </span>
        </div>
      </div>
      {cartao.warning ? (
        <div className="mt-3 flex animate-entra items-start gap-2.5 rounded-[14px] bg-gema-fraca px-3.5 py-3" style={{ animationDelay: "560ms" }}>
          <span className="mt-1.5 size-1.5 shrink-0 animate-respira rounded-full bg-gema" />
          <span className="text-[12.5px] leading-snug text-gema-texto">{cartao.warning}</span>
        </div>
      ) : null}
    </>
  );
}
```

`src/features/nutri/components/NutriActions.tsx`:
```tsx
import Link from "next/link";
import { IconeRecomecar } from "@/components/icons";
import { Button } from "@/components/ui/Button";
import { cascata } from "@/lib/motion";
import type { AcaoNutri } from "../tipos";

const contorno =
  "group flex h-11 animate-entra items-center justify-center gap-2 rounded-full border-[1.5px] border-linha text-sm font-semibold transition-[border-color,transform] duration-250 hover:-translate-y-px hover:border-tinta active:scale-95 disabled:opacity-50";

/** Ações da resposta (RF21). A executável fica ocupada enquanto aplica; as outras esperam. */
export function NutriActions({
  acoes,
  aplicando,
  aoAgir,
}: {
  acoes: AcaoNutri[];
  aplicando: boolean;
  aoAgir: (acao: AcaoNutri) => void;
}) {
  if (acoes.length === 0) return null;
  return (
    <div className="mt-3.5 flex flex-col gap-2">
      {acoes.map((acao, i) =>
        acao.kind === "ver-refeicao" ? (
          <Link key={acao.index} href={`/dieta/${acao.slot}`} style={cascata(i, 70, 620)} className={contorno}>
            {acao.label}
          </Link>
        ) : acao.kind === "substituir" || acao.kind === "aplicar-refeicao" ? (
          <div key={acao.index} style={cascata(i, 70, 620)} className="animate-entra">
            <Button tamanho="media" className="w-full" carregando={aplicando} onClick={() => aoAgir(acao)}>
              {acao.label}
            </Button>
          </div>
        ) : (
          <button key={acao.index} type="button" disabled={aplicando} onClick={() => aoAgir(acao)} style={cascata(i, 70, 620)} className={contorno}>
            {acao.kind === "outra-opcao" ? (
              <IconeRecomecar size={16} className="transition-transform duration-500 ease-[cubic-bezier(.22,1,.36,1)] group-hover:rotate-180" />
            ) : null}
            {acao.label}
          </button>
        ),
      )}
    </div>
  );
}
```

`src/features/nutri/components/ThinkingIndicator.tsx`:
```tsx
import { MarcaNutri } from "@/components/icons";

/** Enquanto a resposta não chega. */
export function ThinkingIndicator() {
  return (
    <div className="flex animate-entra gap-2.5">
      <span className="animate-respira">
        <MarcaNutri size={26} />
      </span>
      <div className="flex flex-1 items-center gap-1.5 pt-1.5" role="status">
        <span className="sr-only">O Nutri está montando a resposta</span>
        {[0, 1, 2].map((i) => (
          <span key={i} className="size-1.5 rounded-full bg-pedra motion-safe:animate-bounce" style={{ animationDelay: `${i * 140}ms` }} />
        ))}
      </div>
    </div>
  );
}
```

`src/features/nutri/components/OfflineNotice.tsx`:
```tsx
import Link from "next/link";
import { IconeSemConexao } from "@/components/icons";

/** Sem internet: o plano continua acessível. */
export function OfflineNotice() {
  return (
    <div role="alert" className="mb-4 flex animate-entra-topo items-start gap-3 rounded-2xl bg-alerta-fraca p-4">
      <IconeSemConexao size={20} className="mt-0.5 shrink-0 text-alerta" />
      <div>
        <p className="text-[14.5px] font-semibold text-alerta-texto">Sua pergunta não saiu daqui</p>
        <p className="mt-1 text-[13px] leading-snug text-alerta-texto">
          O aparelho está sem internet. Seu plano de hoje continua salvo: dá para ver as refeições e marcar o que comeu.
        </p>
        <Link
          href="/dieta"
          className="mt-2.5 inline-flex h-9 items-center rounded-full border-[1.5px] border-alerta-texto px-3.5 text-[13px] font-semibold text-alerta-texto"
        >
          Ver as refeições de hoje
        </Link>
      </div>
    </div>
  );
}
```

`src/features/nutri/components/ChatBubble.tsx`:
```tsx
import { IconeRecomecar, MarcaNutri } from "@/components/icons";
import type { AcaoNutri, MensagemNutri } from "../tipos";
import { MealSuggestionCard } from "./MealSuggestionCard";
import { NutriActions } from "./NutriActions";
import { SwapCard } from "./SwapCard";

/** A pergunta da pessoa; se não saiu, oferece reenviar. */
export function PerguntaBubble({
  texto,
  status,
  aoReenviar,
}: {
  texto: string;
  status?: "enviando" | "falhou";
  aoReenviar?: () => void;
}) {
  const falhou = status === "falhou";
  return (
    <div className="flex animate-entra-lado flex-col items-end">
      <p
        className={`max-w-[264px] rounded-[18px] rounded-br-md px-[15px] py-3 text-[14.5px] leading-snug break-words whitespace-pre-wrap ${
          falhou ? "border border-linha bg-white text-fumo" : "bg-tinta text-neve"
        } ${status === "enviando" ? "opacity-80" : ""}`}
      >
        {texto}
      </p>
      {falhou ? (
        <div className="mt-2 flex animate-balanca items-center gap-2.5">
          <span className="text-xs font-medium text-alerta">Não enviada</span>
          <button
            type="button"
            onClick={aoReenviar}
            className="flex h-9 items-center gap-[7px] rounded-full border-[1.5px] border-tinta px-3.5 text-[13px] font-semibold"
          >
            <IconeRecomecar size={15} strokeWidth={2} />
            Tentar de novo
          </button>
        </div>
      ) : null}
    </div>
  );
}

/** A resposta do Nutri: texto, cartão, segundo parágrafo e ações. */
export function RespostaBubble({
  mensagem,
  aplicando = false,
  aoAgir,
}: {
  mensagem: MensagemNutri;
  aplicando?: boolean;
  aoAgir: (acao: AcaoNutri) => void;
}) {
  return (
    <div className="flex animate-entra-lado-esq gap-2.5">
      <span className="animate-pop">
        <MarcaNutri size={26} />
      </span>
      <div className="min-w-0 flex-1">
        <p className="animate-entra text-[14.5px] leading-relaxed break-words whitespace-pre-wrap">{mensagem.content}</p>
        {mensagem.card?.type === "swap" ? <SwapCard cartao={mensagem.card} /> : null}
        {mensagem.card?.type === "meal" ? <MealSuggestionCard cartao={mensagem.card} /> : null}
        {mensagem.followUp ? (
          <p className="mt-3 animate-entra text-[14.5px] leading-relaxed" style={{ animationDelay: "420ms" }}>
            {mensagem.followUp}
          </p>
        ) : null}
        <NutriActions acoes={mensagem.actionsAvailable ? mensagem.actions : []} aplicando={aplicando} aoAgir={aoAgir} />
      </div>
    </div>
  );
}
```

- [ ] **Step 3: Rodar, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project storybook src/features/nutri && npm run lint && npm run typecheck"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(nutri): bolhas, cartões de troca e refeição, ações, indicador e aviso offline

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `ContextCard`, `SuggestionChips`, `ChatComposer` e `ConversationListItem`

**Files (repo front):**
- Create: `src/features/nutri/components/{ContextCard,SuggestionChips,ChatComposer,ConversationListItem}.tsx` e as quatro stories

**Interfaces:**
- Produces:
  - `ContextCard({ linhas?: LinhaContexto[] })` — sem linhas ⇒ `Skeleton` × 3.
  - `SuggestionChips({ sugestoes, aoEscolher })` — vazia não renderiza; `aoEscolher(texto)`.
  - `ChatComposer({ valor, aoMudar, aoEnviar, enviando })` — campo "Escreva sua pergunta" (rótulo acessível "Escreva sua pergunta para o Nutri"), botão "Enviar pergunta"; desabilitado vazio/só espaços/enviando; contador "{n}/1.000" acima de 900; `maxLength=1000`.
  - `ConversationListItem({ conversa, aoApagar, apagando? })` — link `/nutri/{id}` (com `?pergunta=` quando `pergunta` vier), título (ou "Conversa sem título"), "{quando}, {n} mensagens", prévia; botão "Apagar".

- [ ] **Step 1: Stories que devem falhar**

`src/features/nutri/components/ContextCard.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, within } from 'storybook/test';
import { contextoApi } from '@/mocks/fixtures/nutri';
import type { LinhaContexto } from '../tipos';
import { ContextCard } from './ContextCard';

const meta = { title: 'Nutri/ContextCard', component: ContextCard, args: { linhas: contextoApi as LinhaContexto[] } } satisfies Meta<typeof ContextCard>;
export default meta;
type Story = StoryObj<typeof meta>;

export const ComAlergia: Story = {
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByText('O que estou olhando agora')).toBeInTheDocument();
    await expect(tela.getByText('Sua alergia a amendoim e castanhas')).toBeInTheDocument();
  },
};

export const SemProximaRefeicao: Story = {
  args: { linhas: (contextoApi as LinhaContexto[]).slice(1) },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).queryByText(/Seu almoço/)).toBeNull();
  },
};

export const Carregando: Story = {
  args: { linhas: undefined },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('status', { name: 'Carregando o que o Nutri está olhando' })).toBeInTheDocument();
  },
};
```

`src/features/nutri/components/SuggestionChips.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { SuggestionChips } from './SuggestionChips';

const meta = {
  title: 'Nutri/SuggestionChips',
  component: SuggestionChips,
  args: { sugestoes: ['E no jantar, o que como?', 'Por que a batata segura mais a fome?', 'O que como antes do treino?'], aoEscolher: fn() },
} satisfies Meta<typeof SuggestionChips>;
export default meta;
type Story = StoryObj<typeof meta>;

export const TresSugestoes: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await expect(tela.getAllByRole('button')).toHaveLength(3);
    await userEvent.click(tela.getByRole('button', { name: 'E no jantar, o que como?' }));
    await expect(args.aoEscolher).toHaveBeenCalledWith('E no jantar, o que como?');
  },
};

export const UmaSugestao: Story = { args: { sugestoes: ['E depois do treino?'] } };

export const TextoLongo: Story = {
  args: { sugestoes: ['Como eu faço para fechar a proteína do dia sem exagerar no jantar?'] },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('button')).toHaveTextContent('Como eu faço para fechar a proteína do dia sem exagerar no jantar?');
  },
};

export const Vazia: Story = {
  args: { sugestoes: [] },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).queryByRole('button')).toBeNull();
  },
};
```

`src/features/nutri/components/ChatComposer.stories.tsx`:
```tsx
import { useState } from 'react';
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { ChatComposer } from './ChatComposer';

const meta = {
  title: 'Nutri/ChatComposer',
  component: ChatComposer,
  args: { valor: '', aoMudar: fn(), aoEnviar: fn(), enviando: false },
  render: function Controlado(args) {
    const [valor, setValor] = useState(args.valor);
    return <ChatComposer {...args} valor={valor} aoMudar={(v) => { setValor(v); args.aoMudar(v); }} />;
  },
} satisfies Meta<typeof ChatComposer>;
export default meta;
type Story = StoryObj<typeof meta>;

export const Vazio: Story = {
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('button', { name: 'Enviar pergunta' })).toBeDisabled();
  },
};

export const Digitando: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await userEvent.type(tela.getByLabelText('Escreva sua pergunta para o Nutri'), 'Posso trocar o arroz?');
    await userEvent.click(tela.getByRole('button', { name: 'Enviar pergunta' }));
    await expect(args.aoEnviar).toHaveBeenCalled();
  },
};

export const Enviando: Story = {
  args: { valor: 'Posso trocar o arroz?', enviando: true },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('button', { name: 'Enviar pergunta' })).toBeDisabled();
  },
};

export const LimiteDeCaracteres: Story = {
  args: { valor: 'a'.repeat(950) },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('950/1.000')).toBeInTheDocument();
  },
};
```

`src/features/nutri/components/ConversationListItem.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { camelizar } from '@/lib/api/case';
import { conversaApi } from '@/mocks/fixtures/nutri';
import type { Conversa } from '../tipos';
import { ConversationListItem } from './ConversationListItem';

const conversa = camelizar<Conversa>(conversaApi(12));

const meta = {
  title: 'Nutri/ConversationListItem',
  component: ConversationListItem,
  decorators: [(Story) => <ul className="list-none"><Story /></ul>],
  args: { conversa, aoApagar: fn(), agora: new Date('2026-10-01T15:00:00-03:00') },
} satisfies Meta<typeof ConversationListItem>;
export default meta;
type Story = StoryObj<typeof meta>;

export const Normal: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await expect(tela.getByRole('link', { name: /Posso trocar o arroz por batata\?/ })).toHaveAttribute('href', '/nutri/12');
    await expect(tela.getByText('ontem, 6 mensagens')).toBeInTheDocument();
    await userEvent.click(tela.getByRole('button', { name: 'Apagar' }));
    await expect(args.aoApagar).toHaveBeenCalled();
  },
};

export const ComPergunta: Story = {
  args: { pergunta: 'Não tenho frango em casa' },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('link')).toHaveAttribute('href', `/nutri/12?pergunta=${encodeURIComponent('Não tenho frango em casa')}`);
  },
};

export const TituloLongo: Story = {
  args: { conversa: { ...conversa, title: 'Posso trocar o arroz branco do almoço por batata-doce cozida…' } },
};

export const SemTitulo: Story = {
  args: { conversa: { ...conversa, title: null, preview: null } },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('Conversa sem título')).toBeInTheDocument();
  },
};

export const Apagando: Story = {
  args: { apagando: true },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('button', { name: 'Apagar' })).toBeDisabled();
  },
};
```

Run: `docker compose run --rm web npx vitest run --project storybook src/features/nutri`
Expected: FAIL — componentes inexistentes.

- [ ] **Step 2: Implementar**

`src/features/nutri/components/ContextCard.tsx`:
```tsx
import { Skeleton } from "@/components/ui/Skeleton";
import { cascata } from "@/lib/motion";
import type { LinhaContexto } from "../tipos";

const COR = { gema: "bg-gema", alerta: "bg-alerta", mata: "bg-mata" };

/** "O que estou olhando agora" — a versão legível do contexto do Nutri (RN29). */
export function ContextCard({ linhas }: { linhas?: LinhaContexto[] }) {
  return (
    <section className="mt-[18px] animate-escala rounded-[18px] bg-white px-4 pt-1 pb-2" style={{ animationDelay: "160ms" }}>
      <p className="py-3 text-[12.5px] font-semibold text-fumo">O que estou olhando agora</p>
      {!linhas ? (
        <div className="flex flex-col gap-2 pb-2" role="status" aria-label="Carregando o que o Nutri está olhando">
          <Skeleton className="h-5" />
          <Skeleton className="h-5" />
          <Skeleton className="h-5 w-2/3" />
        </div>
      ) : (
        linhas.map((linha, i) => (
          <div
            key={linha.text}
            style={cascata(i, 70, 260)}
            className={`flex animate-entra-lado-esq items-start gap-2.5 py-[9px] ${i < linhas.length - 1 ? "border-b border-fio" : ""}`}
          >
            <span className={`mt-1.5 size-1.5 shrink-0 animate-pop rounded-full ${COR[linha.tone]}`} style={cascata(i, 70, 300)} />
            <span className="text-[13.5px] leading-snug first-letter:uppercase">{linha.text}</span>
          </div>
        ))
      )}
    </section>
  );
}
```

`src/features/nutri/components/SuggestionChips.tsx`:
```tsx
import { cascata } from "@/lib/motion";

/** RN45 — continuações da última resposta; tocar envia a pergunta. */
export function SuggestionChips({ sugestoes, aoEscolher }: { sugestoes: string[]; aoEscolher: (texto: string) => void }) {
  if (sugestoes.length === 0) return null;
  return (
    <div className="flex shrink-0 flex-wrap gap-2 px-4 pb-1" aria-label="Sugestões de pergunta" role="group">
      {sugestoes.map((s, i) => (
        <button
          key={s}
          type="button"
          onClick={() => aoEscolher(s)}
          style={cascata(i, 70, 120)}
          className="min-h-9 animate-escala rounded-full border border-linha bg-white px-3.5 py-1.5 text-left text-[13px] font-medium transition-[border-color,transform] duration-250 hover:-translate-y-px hover:border-pedra active:scale-95"
        >
          {s}
        </button>
      ))}
    </div>
  );
}
```

`src/features/nutri/components/ChatComposer.tsx`:
```tsx
import { IconeEnviar } from "@/components/icons";

const LIMITE = 1000;

/** Campo da pergunta (1–1.000 caracteres). */
export function ChatComposer({
  valor,
  aoMudar,
  aoEnviar,
  enviando,
}: {
  valor: string;
  aoMudar: (valor: string) => void;
  aoEnviar: () => void;
  enviando: boolean;
}) {
  const vazio = valor.trim() === "";
  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        if (!vazio && !enviando) aoEnviar();
      }}
      className="shrink-0 border-t border-linha px-4 pt-3 pb-5 area-segura-baixo"
    >
      {valor.length > 900 ? (
        <p className="mb-1.5 text-right text-xs text-fumo" aria-live="polite">
          {valor.length.toLocaleString("pt-BR")}/{LIMITE.toLocaleString("pt-BR")}
        </p>
      ) : null}
      <div className="flex items-center gap-2.5">
        <label htmlFor="pergunta" className="sr-only">
          Escreva sua pergunta para o Nutri
        </label>
        <input
          id="pergunta"
          value={valor}
          maxLength={LIMITE}
          onChange={(e) => aoMudar(e.target.value)}
          placeholder="Escreva sua pergunta"
          className="h-[50px] flex-1 rounded-full border border-linha bg-white px-4 text-[14.5px] transition placeholder:text-fumo focus:border-tinta focus:shadow-[inset_0_0_0_1px_var(--color-tinta)] focus:outline-none"
        />
        <button
          type="submit"
          aria-label="Enviar pergunta"
          disabled={vazio || enviando}
          className="flex size-[50px] shrink-0 items-center justify-center rounded-full bg-tinta text-neve transition active:scale-90 disabled:opacity-40"
        >
          <IconeEnviar size={20} />
        </button>
      </div>
    </form>
  );
}
```

`src/features/nutri/components/ConversationListItem.tsx`:
```tsx
import Link from "next/link";
import { quando } from "../regras";
import type { Conversa } from "../tipos";

/** Uma conversa anterior: título, quando, quantas mensagens e a prévia da última. */
export function ConversationListItem({
  conversa,
  pergunta,
  apagando = false,
  aoApagar,
  agora,
}: {
  conversa: Conversa;
  pergunta?: string;
  apagando?: boolean;
  aoApagar: () => void;
  agora?: Date;
}) {
  const href = `/nutri/${conversa.id}${pergunta ? `?pergunta=${encodeURIComponent(pergunta)}` : ""}`;
  return (
    <li className={`flex animate-entra items-start gap-3 border-b border-fio py-3.5 transition-opacity last:border-b-0 ${apagando ? "opacity-40" : ""}`}>
      <Link href={href} className="min-w-0 flex-1 rounded-lg">
        <p className="truncate text-[15px] font-semibold tracking-[-0.01em]">{conversa.title ?? "Conversa sem título"}</p>
        <p className="mt-0.5 text-xs text-fumo">
          {conversa.lastMessageAt ? `${quando(conversa.lastMessageAt, agora)}, ` : ""}
          {conversa.messageCount} {conversa.messageCount === 1 ? "mensagem" : "mensagens"}
        </p>
        {conversa.preview ? <p className="mt-1 line-clamp-2 text-[13px] leading-snug text-fumo">{conversa.preview}</p> : null}
      </Link>
      <button
        type="button"
        onClick={aoApagar}
        disabled={apagando}
        className="flex h-9 shrink-0 items-center rounded-full px-3 text-[13px] font-semibold text-fumo transition-colors hover:bg-alerta-fraca hover:text-alerta-texto disabled:opacity-50"
      >
        Apagar
      </button>
    </li>
  );
}
```

- [ ] **Step 3: Rodar, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project storybook src/features/nutri && npm run lint && npm run typecheck"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(nutri): cartão de contexto, chips de continuação, campo da pergunta e item de conversa

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Tela Conversas (`/nutri`)

**Files (repo front):**
- Create: `src/features/nutri/components/ConversasTela.tsx`, `src/features/nutri/components/ConversasTela.integration.test.tsx`
- Modify: `src/app/(app)/nutri/page.tsx` (vira só o contêiner)

**Interfaces:**
- Consumes: hooks da Task 1; `ConversationListItem` (Task 3); `Sheet`, `Button`, `ErrorState`, `Skeleton`, `TopBar`, `Screen`, `useToast`.
- Produces: `ConversasTela` — N05 inteira; sem conversas ⇒ cria/reaproveita e `router.replace('/nutri/{id}[?pergunta=…]')`.

- [ ] **Step 1: Teste que deve falhar**

`src/features/nutri/components/ConversasTela.integration.test.tsx`:
```tsx
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { conversaApi } from '@/mocks/fixtures/nutri';
import { url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { definirUrl, redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { ConversasTela } from './ConversasTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

const comConversas = (...ids: number[]) =>
  server.use(http.get(url('/conversations'), () => HttpResponse.json({ data: ids.map((id) => conversaApi(id, { titulo: `Conversa ${id}` })), meta: { next_cursor: null, per_page: 15 } })));

beforeEach(() => {
  redefinirNavegacao();
  definirUrl('/nutri');
});

describe('Conversas (N05)', () => {
  it('sem conversas, abre direto uma nova levando a pergunta (CA01)', async () => {
    definirUrl(`/nutri?pergunta=${encodeURIComponent('Não tenho frango em casa')}`);
    server.use(http.post(url('/conversations'), () => HttpResponse.json({ data: conversaApi(7, { vazia: true }) }, { status: 201 })));

    renderizar(<ConversasTela />);

    await waitFor(() => expect(roteador.replace).toHaveBeenCalledWith(`/nutri/7?pergunta=${encodeURIComponent('Não tenho frango em casa')}`));
  });

  it('com conversas, mostra "Nova conversa" e a lista; a pergunta vai junto (CA02)', async () => {
    definirUrl(`/nutri?pergunta=${encodeURIComponent('E no jantar?')}`);
    comConversas(3, 2);

    renderizar(<ConversasTela />);

    expect(await screen.findByRole('heading', { name: 'Conversas com o Nutri' })).toBeInTheDocument();
    expect(screen.getByText('Sua pergunta: “E no jantar?”. Escolha onde perguntar.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Conversa 3/ })).toHaveAttribute('href', `/nutri/3?pergunta=${encodeURIComponent('E no jantar?')}`);
  });

  it('"Nova conversa" cria e abre', async () => {
    comConversas(3);
    server.use(http.post(url('/conversations'), () => HttpResponse.json({ data: conversaApi(8, { vazia: true }) }, { status: 201 })));

    renderizar(<ConversasTela />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Nova conversa' }));

    await waitFor(() => expect(roteador.push).toHaveBeenCalledWith('/nutri/8'));
  });

  it('apagar pede confirmação e tira da lista (CA11)', async () => {
    comConversas(3, 2);
    let apagou = false;
    server.use(
      http.delete(url('/conversations/3'), () => {
        apagou = true;
        return new HttpResponse(null, { status: 204 });
      }),
    );
    const usuario = userEvent.setup();

    renderizar(<ConversasTela />);
    const linha = (await screen.findByRole('link', { name: /Conversa 3/ })).closest('li') as HTMLElement;
    await usuario.click(within(linha).getByRole('button', { name: 'Apagar' }));
    const folha = await screen.findByRole('dialog', { name: 'Apagar esta conversa?' });
    expect(within(folha).getByText('O Nutri também esquece o que foi dito nela.')).toBeInTheDocument();
    await usuario.click(within(folha).getByRole('button', { name: 'Apagar' }));

    await waitFor(() => expect(screen.queryByRole('link', { name: /Conversa 3/ })).toBeNull());
    expect(apagou).toBe(true);
  });

  it('erro ao carregar mostra ErrorState, mas "Nova conversa" continua', async () => {
    server.use(http.get(url('/conversations'), () => HttpResponse.json({ message: 'x', code: 'SERVER_ERROR' }, { status: 500 })));

    renderizar(<ConversasTela />);

    expect(await screen.findByText('Não foi possível carregar suas conversas', {}, { timeout: 5000 })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Nova conversa' })).toBeEnabled();
  });
});
```

Run: `docker compose run --rm web npx vitest run --project integration src/features/nutri/components/ConversasTela.integration.test.tsx`
Expected: FAIL — `ConversasTela` não existe.

- [ ] **Step 2: Implementar**

`src/features/nutri/components/ConversasTela.tsx`:
```tsx
"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { ErrorState } from "@/components/app/ErrorState";
import { Screen } from "@/components/app/Screen";
import { TopBar } from "@/components/app/TopBar";
import { IconeMais, MarcaNutri } from "@/components/icons";
import { Button } from "@/components/ui/Button";
import { Sheet } from "@/components/ui/Sheet";
import { Skeleton } from "@/components/ui/Skeleton";
import { useToast } from "@/components/ui/Toaster";
import { comoApiError } from "@/lib/api/errors";
import { useApagarConversa, useConversas, useNovaConversa } from "../hooks";
import { perguntaDaUrl } from "../regras";
import { ConversationListItem } from "./ConversationListItem";

/** N05 — continuar uma conversa ou começar outra (RF19, RN28). */
export function ConversasTela() {
  const router = useRouter();
  const avisar = useToast();
  const pergunta = perguntaDaUrl(useSearchParams().get("pergunta"));
  const conversas = useConversas();
  const nova = useNovaConversa();
  const apagar = useApagarConversa();
  const [alvo, setAlvo] = useState<number | null>(null);
  const fim = useRef<HTMLDivElement>(null);
  const lista = conversas.data?.pages.flatMap((p) => p.data) ?? [];
  const destino = (id: number) => `/nutri/${id}${pergunta ? `?pergunta=${encodeURIComponent(pergunta)}` : ""}`;

  async function comecar(trocar: boolean) {
    try {
      const conversa = await nova.mutateAsync();
      if (trocar) router.replace(destino(conversa.id));
      else router.push(destino(conversa.id));
    } catch (e) {
      avisar({ texto: comoApiError(e).message });
    }
  }

  // Sem conversas anteriores: pula a lista (RF19).
  const semNenhuma = conversas.isSuccess && lista.length === 0;
  useEffect(() => {
    if (semNenhuma && nova.isIdle) void comecar(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [semNenhuma]);

  // Carrega mais ao chegar ao fim da lista.
  useEffect(() => {
    const el = fim.current;
    if (!el || !conversas.hasNextPage || typeof IntersectionObserver === "undefined") return;
    const observador = new IntersectionObserver(([e]) => {
      if (e.isIntersecting && !conversas.isFetchingNextPage) void conversas.fetchNextPage();
    });
    observador.observe(el);
    return () => observador.disconnect();
  }, [conversas]);

  return (
    <Screen>
      <TopBar voltarPara="/hoje" rotuloVoltar="Voltar para hoje" direita={<MarcaNutri size={30} />} />

      <main className="flex-1 px-5 pt-2 pb-8">
        <h1 className="animate-entra font-display text-[28px] leading-tight font-bold tracking-[-0.028em]">Conversas com o Nutri</h1>
        <p className="mt-2 animate-entra text-sm leading-normal text-fumo" style={{ animationDelay: "80ms" }}>
          Continue de onde parou ou comece um assunto novo. O Nutri lembra do que vocês já conversaram.
        </p>

        {pergunta ? (
          <p className="mt-4 animate-entra rounded-2xl bg-gema-fraca px-4 py-3 text-[13.5px] leading-snug text-gema-texto" style={{ animationDelay: "140ms" }}>
            Sua pergunta: “{pergunta}”. Escolha onde perguntar.
          </p>
        ) : null}

        <Button className="mt-5 animate-entra" carregando={nova.isPending} onClick={() => void comecar(false)} style={{ animationDelay: "180ms" }}>
          <IconeMais size={18} />
          Nova conversa
        </Button>

        {conversas.isError ? (
          <div className="mt-6">
            <ErrorState
              titulo="Não foi possível carregar suas conversas"
              descricao="Suas conversas estão salvas. Só a conexão falhou agora."
              aoTentarDeNovo={() => void conversas.refetch()}
            />
          </div>
        ) : !conversas.data || semNenhuma ? (
          <div className="mt-6 flex flex-col gap-3" role="status" aria-label="Carregando conversas">
            <Skeleton className="h-[74px] rounded-2xl" />
            <Skeleton className="h-[74px] rounded-2xl" />
            <Skeleton className="h-[74px] rounded-2xl" />
          </div>
        ) : (
          <>
            <h2 className="mt-7 animate-entra font-display text-[15px] font-semibold" style={{ animationDelay: "220ms" }}>
              Recentes
            </h2>
            <ul className="mt-1 list-none rounded-[20px] bg-white px-4">
              {lista.map((conversa) => (
                <ConversationListItem
                  key={conversa.id}
                  conversa={conversa}
                  pergunta={pergunta || undefined}
                  apagando={apagar.isPending && apagar.variables === conversa.id}
                  aoApagar={() => setAlvo(conversa.id)}
                />
              ))}
            </ul>
            <div ref={fim} />
            {conversas.hasNextPage ? (
              <button
                type="button"
                onClick={() => void conversas.fetchNextPage()}
                className="mt-3 h-11 w-full rounded-full text-sm font-semibold text-mata"
              >
                Ver conversas mais antigas
              </button>
            ) : null}
          </>
        )}
      </main>

      <Sheet
        aberta={alvo !== null}
        aoFechar={() => setAlvo(null)}
        titulo="Apagar esta conversa?"
        descricao="O Nutri também esquece o que foi dito nela."
        tom="destrutivo"
      >
        <div className="mt-5 flex flex-col gap-2">
          <Button
            variante="destrutiva"
            onClick={() => {
              if (alvo === null) return;
              apagar.mutate(alvo, { onError: (e) => avisar({ texto: comoApiError(e).message }) });
              setAlvo(null);
            }}
          >
            Apagar
          </Button>
          <button type="button" onClick={() => setAlvo(null)} className="flex h-12 items-center justify-center text-[14.5px] font-semibold text-fumo">
            Cancelar
          </button>
        </div>
      </Sheet>
    </Screen>
  );
}
```
(Confira se o `Button` aceita `style`; se não aceitar, envolva em `<div className="mt-5 animate-entra" style=…>` e registre a ruling.)

`src/app/(app)/nutri/page.tsx` (substituir inteiro):
```tsx
import { ConversasTela } from "@/features/nutri/components/ConversasTela";

export default function Nutri() {
  return <ConversasTela />;
}
```

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project integration src/features/nutri && npm run lint && npm run typecheck && npm test"`
Expected: tudo verde (tire `'src/app/(app)/nutri/page.tsx'` dos dois blocos LEGADO do `eslint.config.mjs`).

```bash
git add -A && git commit -m "feat(nutri): tela de conversas — continuar, começar e apagar (RF19)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Chat (`/nutri/[conversa]`)

**Files (repo front):**
- Create: `src/features/nutri/components/ChatTela.tsx`, `src/features/nutri/components/ChatTela.integration.test.tsx`, `src/app/(app)/nutri/[conversa]/page.tsx`

**Interfaces:**
- Consumes: Tasks 1–3; `useDia`, `AvisoDeAlteracao` (04B); `usePerfil` (nome preferido).
- Produces: `ChatTela({ id })` — S14 inteira.

- [ ] **Step 1: Teste que deve falhar**

`src/features/nutri/components/ChatTela.integration.test.tsx`:
```tsx
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { diaApi } from '@/mocks/fixtures/dia';
import { conversaApi, mensagemUsuarioApi, respostaTrocaApi } from '@/mocks/fixtures/nutri';
import { erroDaApi, url } from '@/mocks/handlers/auth';
import { respondendoMensagens } from '@/mocks/handlers/nutri';
import { server } from '@/mocks/server';
import { definirUrl, redefinirNavegacao } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { ChatTela } from './ChatTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

const conversa = (id = 5, vazia = false) => http.get(url(`/conversations/${id}`), () => HttpResponse.json({ data: conversaApi(id, { vazia }) }));
const respondendoPergunta = (resposta = respostaTrocaApi(12)) =>
  http.post(url('/conversations/5/messages'), async ({ request }) => {
    const { content } = (await request.json()) as { content: string };
    return HttpResponse.json({ data: { user_message: mensagemUsuarioApi(11, content), assistant_message: resposta } }, { status: 201 });
  });

beforeEach(() => {
  redefinirNavegacao();
  definirUrl('/nutri/5');
});

describe('Chat do Nutri (S14)', () => {
  it('conversa vazia: saudação, contexto e perguntas prontas', async () => {
    server.use(conversa(5, true), respondendoMensagens(5, []));

    renderizar(<ChatTela id={5} />);

    expect(await screen.findByRole('heading', { name: 'No que posso ajudar, Camila?' })).toBeInTheDocument();
    expect(await screen.findByText('Sua alergia a amendoim e castanhas')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'O que comer antes do treino das 19:00?' })).toBeInTheDocument();
  });

  it('?pergunta= chega no campo sem enviar (RF19)', async () => {
    definirUrl(`/nutri/5?pergunta=${encodeURIComponent('Não tenho frango em casa')}`);
    let enviou = false;
    server.use(conversa(5, true), respondendoMensagens(5, []), http.post(url('/conversations/5/messages'), () => { enviou = true; return HttpResponse.json({}); }));

    renderizar(<ChatTela id={5} />);

    expect(await screen.findByLabelText('Escreva sua pergunta para o Nutri')).toHaveValue('Não tenho frango em casa');
    expect(enviou).toBe(false);
  });

  it('pergunta, mostra a resposta com cartão e chips; tocar num chip envia (RF20, CA13)', async () => {
    server.use(conversa(5, true), respondendoMensagens(5, []), respondendoPergunta());
    const usuario = userEvent.setup();

    renderizar(<ChatTela id={5} />);
    await usuario.type(await screen.findByLabelText('Escreva sua pergunta para o Nutri'), 'Posso trocar o arroz por batata?');
    await usuario.click(screen.getByRole('button', { name: 'Enviar pergunta' }));

    expect(await screen.findByRole('group', { name: 'De arroz branco cozido para batata-doce cozida' })).toBeInTheDocument();
    expect(screen.getByText('Posso trocar o arroz por batata?')).toBeInTheDocument();
    const chips = screen.getByRole('group', { name: 'Sugestões de pergunta' });
    await usuario.click(within(chips).getByRole('button', { name: 'E no jantar, o que como?' }));
    expect(await screen.findByText('E no jantar, o que como?', { selector: 'p' })).toBeInTheDocument();
  });

  it('IA fora: "Não enviada", sem chips; "Tentar de novo" reenvia (CA09)', async () => {
    let tentativas = 0;
    server.use(
      conversa(5, true),
      respondendoMensagens(5, []),
      http.post(url('/conversations/5/messages'), async ({ request }) => {
        tentativas++;
        if (tentativas === 1) return erroDaApi(503, 'AI_UNAVAILABLE', 'O Nutri não respondeu agora. Tente de novo.');
        const { content } = (await request.json()) as { content: string };
        return HttpResponse.json({ data: { user_message: mensagemUsuarioApi(11, content), assistant_message: respostaTrocaApi(12) } }, { status: 201 });
      }),
    );
    const usuario = userEvent.setup();

    renderizar(<ChatTela id={5} />);
    await usuario.type(await screen.findByLabelText('Escreva sua pergunta para o Nutri'), 'Oi');
    await usuario.click(screen.getByRole('button', { name: 'Enviar pergunta' }));

    expect(await screen.findByText('Não enviada')).toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Sugestões de pergunta' })).toBeNull();
    await usuario.click(screen.getByRole('button', { name: 'Tentar de novo' }));

    expect(await screen.findByRole('group', { name: /De arroz branco cozido/ })).toBeInTheDocument();
    expect(screen.queryByText('Não enviada')).toBeNull();
  });

  it('429 avisa quanto esperar', async () => {
    server.use(
      conversa(5, true),
      respondendoMensagens(5, []),
      http.post(url('/conversations/5/messages'), () =>
        HttpResponse.json({ message: 'x', code: 'TOO_MANY_REQUESTS', details: { retry_after: 42 } }, { status: 429 }),
      ),
    );
    const usuario = userEvent.setup();

    renderizar(<ChatTela id={5} />);
    await usuario.type(await screen.findByLabelText('Escreva sua pergunta para o Nutri'), 'Oi');
    await usuario.click(screen.getByRole('button', { name: 'Enviar pergunta' }));

    expect(await screen.findByText('Muitas perguntas seguidas. Tente de novo em 42 segundos.')).toBeInTheDocument();
  });

  it('aplicar a troca: ações somem, confirmação entra e o toast oferece "Desfazer" (RF21, CA05)', async () => {
    server.use(
      conversa(5),
      respondendoMensagens(5, [respostaTrocaApi(12), mensagemUsuarioApi(11)]),
      http.post(url('/messages/12/actions/0'), () =>
        HttpResponse.json({
          data: {
            message: { id: 12, actions: [], actions_available: false },
            confirmation: {
              id: 13, role: 'assistant', content: 'Feito. Seu almoço de hoje vai com batata-doce cozida.', created_at: '2026-10-01T11:03:00-03:00',
              follow_up: null, follow_up_suggestions: [], card: null,
              actions: [{ index: 0, kind: 'ver-refeicao', label: 'Ver a refeição', slot: 'almoco' }], actions_available: true, rating: null,
            },
            day: diaApi({ ultimaAlteracao: { id: 3, text: 'Arroz branco cozido trocado por batata-doce cozida' } }),
          },
        }),
      ),
    );
    const usuario = userEvent.setup();

    renderizar(<ChatTela id={5} />);
    await usuario.click(await screen.findByRole('button', { name: 'Substituir no almoço de hoje' }));

    expect(await screen.findByText('Feito. Seu almoço de hoje vai com batata-doce cozida.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Substituir no almoço de hoje' })).toBeNull();
    expect(screen.getByRole('link', { name: 'Ver a refeição' })).toHaveAttribute('href', '/dieta/almoco');
    expect(await screen.findByText('Arroz branco cozido trocado por batata-doce cozida')).toBeInTheDocument();
  });

  it('ação que já expirou avisa e some (CA08)', async () => {
    server.use(
      conversa(5),
      respondendoMensagens(5, [respostaTrocaApi(12), mensagemUsuarioApi(11)]),
      http.post(url('/messages/12/actions/0'), () => erroDaApi(409, 'ACTION_EXPIRED', 'Essa sugestão era para 30/09.')),
    );

    renderizar(<ChatTela id={5} />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Substituir no almoço de hoje' }));

    expect(await screen.findByText('Essa sugestão era para 30/09.')).toBeInTheDocument();
  });

  it('"Ver outras opções" manda a pergunta', async () => {
    let perguntou = '';
    server.use(
      conversa(5),
      respondendoMensagens(5, [respostaTrocaApi(12), mensagemUsuarioApi(11)]),
      http.post(url('/conversations/5/messages'), async ({ request }) => {
        perguntou = ((await request.json()) as { content: string }).content;
        return HttpResponse.json({ data: { user_message: mensagemUsuarioApi(13, perguntou), assistant_message: respostaTrocaApi(14) } }, { status: 201 });
      }),
    );

    renderizar(<ChatTela id={5} />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Ver outras opções' }));

    await waitFor(() => expect(perguntou).toBe('Quero ver outras opções'));
  });

  it('conversa que não existe: "Conversa não encontrada" com "Ver conversas"', async () => {
    server.use(
      http.get(url('/conversations/5'), () => erroDaApi(404, 'NOT_FOUND', 'Não encontrado.')),
      http.get(url('/conversations/5/messages'), () => erroDaApi(404, 'NOT_FOUND', 'Não encontrado.')),
    );

    renderizar(<ChatTela id={5} />);

    expect(await screen.findByRole('heading', { name: 'Conversa não encontrada' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Ver conversas' })).toHaveAttribute('href', '/nutri');
  });

  it('mensagens antigas carregam por botão', async () => {
    server.use(
      conversa(5),
      http.get(url('/conversations/5/messages'), ({ request }) => {
        const cursor = new URL(request.url).searchParams.get('cursor');
        return HttpResponse.json(
          cursor
            ? { data: [mensagemUsuarioApi(1, 'A primeira pergunta')], meta: { next_cursor: null, per_page: 30 } }
            : { data: [respostaTrocaApi(12, { acoes: false }), mensagemUsuarioApi(11)], meta: { next_cursor: 'c1', per_page: 30 } },
        );
      }),
    );

    renderizar(<ChatTela id={5} />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Ver mensagens anteriores' }));

    expect(await screen.findByText('A primeira pergunta')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Ver mensagens anteriores' })).toBeNull();
  });
});
```

Run: `docker compose run --rm web npx vitest run --project integration src/features/nutri/components/ChatTela.integration.test.tsx`
Expected: FAIL — `ChatTela` não existe.

- [ ] **Step 2: Implementar**

`src/features/nutri/components/ChatTela.tsx`:
```tsx
"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Screen } from "@/components/app/Screen";
import { TopBar } from "@/components/app/TopBar";
import { IconeAvancar, MarcaNutri } from "@/components/icons";
import { Skeleton } from "@/components/ui/Skeleton";
import { useToast } from "@/components/ui/Toaster";
import { AvisoDeAlteracao } from "@/features/dia/components/AvisoDeAlteracao";
import { useDia } from "@/features/dia/hooks";
import { usePerfil } from "@/features/perfil/hooks";
import { comoApiError } from "@/lib/api/errors";
import { cascata } from "@/lib/motion";
import { useContexto, useConversa, useMensagens, usePerguntar, useResolverAcao, useSugestoes } from "../hooks";
import { juntarPaginas, perguntaDaAcao, perguntaDaUrl, ultimasSugestoes } from "../regras";
import type { AcaoNutri, MensagemNutri, Pendente } from "../tipos";
import { ChatComposer } from "./ChatComposer";
import { PerguntaBubble, RespostaBubble } from "./ChatBubble";
import { ContextCard } from "./ContextCard";
import { OfflineNotice } from "./OfflineNotice";
import { SuggestionChips } from "./SuggestionChips";
import { ThinkingIndicator } from "./ThinkingIndicator";

function useOnline() {
  const [online, setOnline] = useState(true);
  useEffect(() => {
    const atualizar = () => setOnline(navigator.onLine);
    atualizar();
    window.addEventListener("online", atualizar);
    window.addEventListener("offline", atualizar);
    return () => {
      window.removeEventListener("online", atualizar);
      window.removeEventListener("offline", atualizar);
    };
  }, []);
  return online;
}

/** S14 — o chat (RF20, RF21). */
export function ChatTela({ id }: { id: number }) {
  const avisar = useToast();
  const online = useOnline();
  const conversa = useConversa(id);
  const mensagens = useMensagens(id);
  const perguntar = usePerguntar(id);
  const resolver = useResolverAcao(id);
  const dia = useDia();
  const perfil = usePerfil();
  const [rascunho, setRascunho] = useState(() => perguntaDaUrl(useSearchParams().get("pergunta")));
  const [pendente, setPendente] = useState<Pendente | null>(null);
  const [aplicando, setAplicando] = useState<number | null>(null);
  const fim = useRef<HTMLDivElement>(null);
  const lista = juntarPaginas(mensagens.data?.pages.map((p) => p.data) ?? []);
  const proxima = dia.data?.meals.find((m) => m.isNext);
  const offline = !online || pendente?.status === "falhou";

  useEffect(() => {
    fim.current?.scrollIntoView({ behavior: "smooth", block: "end" });
  }, [lista.length, pendente]);

  async function enviar(texto: string) {
    const pergunta = texto.trim();
    if (!pergunta || perguntar.isPending) return;
    setRascunho("");
    const local: Pendente = { id: `p-${Date.now()}`, content: pergunta, status: "enviando" };
    if (!navigator.onLine) {
      setPendente({ ...local, status: "falhou" });
      return;
    }
    setPendente(local);
    try {
      await perguntar.mutateAsync(pergunta);
      setPendente(null);
    } catch (e) {
      const erro = comoApiError(e);
      if (erro.code === "TOO_MANY_REQUESTS") {
        const segundos = Number(erro.details.retryAfter) || 60;
        avisar({ texto: `Muitas perguntas seguidas. Tente de novo em ${segundos} segundos.` });
        setPendente(null);
        setRascunho(pergunta);
        return;
      }
      setPendente({ ...local, status: "falhou" });
    }
  }

  function agir(acao: AcaoNutri, mensagem: MensagemNutri) {
    const pergunta = perguntaDaAcao(acao, mensagem);
    if (pergunta) {
      void enviar(pergunta);
      return;
    }
    setAplicando(mensagem.id);
    resolver.mutate(
      { mensagem, indice: acao.index },
      {
        onError: (e) => avisar({ texto: comoApiError(e).message }),
        onSettled: () => setAplicando(null),
      },
    );
  }

  if (conversa.isError && comoApiError(conversa.error).status === 404) {
    return (
      <Screen>
        <TopBar voltarPara="/nutri" rotuloVoltar="Voltar para as conversas" />
        <main className="flex-1 px-5 pt-6">
          <h1 className="font-display text-2xl font-bold">Conversa não encontrada</h1>
          <p className="mt-2 text-sm text-fumo">Ela pode ter sido apagada.</p>
          <Link href="/nutri" className="mt-4 inline-block text-sm font-semibold text-mata">
            Ver conversas
          </Link>
        </main>
      </Screen>
    );
  }

  const vazia = mensagens.isSuccess && lista.length === 0 && !pendente;
  const sugestoes = perguntar.isPending || pendente?.status === "falhou" ? [] : ultimasSugestoes(lista);

  return (
    <Screen>
      <TopBar
        voltarPara="/nutri"
        rotuloVoltar="Voltar para as conversas"
        className={vazia ? "" : "border-b border-linha pb-3"}
        direita={
          <div className="flex flex-1 items-center gap-3 pl-1">
            <MarcaNutri size={34} apagada={offline} />
            <div className="flex-1">
              <p className="font-display text-[19px] font-bold tracking-[-0.02em]">Nutri</p>
              <p className={`text-xs ${offline ? "font-medium text-alerta" : "text-fumo"}`}>
                {offline ? "Sem conexão" : proxima ? `Olhando seu ${proxima.name.toLowerCase()} de hoje` : "Conhece seu plano e suas restrições"}
              </p>
            </div>
          </div>
        }
      />

      <AvisoDeAlteracao alteracao={dia.data?.lastChange ?? null} />

      <main className="flex-1 px-5 pt-4" aria-live="polite">
        {offline ? <OfflineNotice /> : null}

        {mensagens.isPending ? (
          <div className="flex flex-col gap-4" role="status" aria-label="Carregando a conversa">
            <Skeleton className="ml-auto h-12 w-2/3 rounded-[18px]" />
            <Skeleton className="h-24 rounded-[18px]" />
          </div>
        ) : vazia ? (
          <EstadoInicial nome={perfil.data?.preferredName} aoEscolher={(q) => void enviar(q)} />
        ) : (
          <div className="flex flex-col gap-4">
            {mensagens.hasNextPage ? (
              <button
                type="button"
                onClick={() => void mensagens.fetchNextPage()}
                className="mx-auto h-9 rounded-full px-4 text-[13px] font-semibold text-mata"
              >
                Ver mensagens anteriores
              </button>
            ) : null}
            {lista.map((m) =>
              m.role === "user" ? (
                <PerguntaBubble key={m.id} texto={m.content} />
              ) : (
                <RespostaBubble key={m.id} mensagem={m} aplicando={aplicando === m.id} aoAgir={(a) => agir(a, m)} />
              ),
            )}
            {pendente ? (
              <PerguntaBubble
                texto={pendente.content}
                status={pendente.status}
                aoReenviar={() => {
                  setPendente(null);
                  void enviar(pendente.content);
                }}
              />
            ) : null}
            {perguntar.isPending ? <ThinkingIndicator /> : null}
          </div>
        )}
        <div ref={fim} />
      </main>

      <SuggestionChips sugestoes={sugestoes} aoEscolher={(s) => void enviar(s)} />
      <ChatComposer valor={rascunho} aoMudar={setRascunho} aoEnviar={() => void enviar(rascunho)} enviando={perguntar.isPending} />
    </Screen>
  );
}

function EstadoInicial({ nome, aoEscolher }: { nome?: string; aoEscolher: (q: string) => void }) {
  const contexto = useContexto();
  const sugestoes = useSugestoes();
  return (
    <>
      <h1 className="animate-entra font-display text-[27px] leading-tight font-bold tracking-[-0.028em]">
        No que posso ajudar{nome ? `, ${nome}` : ""}?
      </h1>
      <p className="mt-2 animate-entra text-sm leading-normal text-fumo" style={{ animationDelay: "90ms" }}>
        Pergunte como se estivesse falando com a nutricionista da academia.
      </p>
      <ContextCard linhas={contexto.data} />
      <h2 className="mt-5 animate-entra font-display text-[15px] font-semibold" style={{ animationDelay: "420ms" }}>
        Perguntas que cabem agora
      </h2>
      <div className="mt-2.5 flex flex-col gap-2">
        {(sugestoes.data ?? []).map((s, i) => (
          <button
            key={s.id}
            type="button"
            onClick={() => aoEscolher(s.question)}
            style={cascata(i, 80, 480)}
            className="group flex min-h-15 animate-entra items-center gap-3 rounded-2xl border border-linha bg-white py-3.5 pr-3.5 pl-4 text-left transition-[border-color,box-shadow,transform] duration-250 ease-[cubic-bezier(.22,1,.36,1)] hover:-translate-y-0.5 hover:border-tinta hover:shadow-[0_12px_28px_-20px_rgba(21,37,28,.9)] active:scale-[0.99]"
          >
            <span className="flex-1 text-[14.5px] leading-snug font-medium">{s.question}</span>
            <IconeAvancar size={18} className="shrink-0 text-fumo transition-transform duration-250 group-hover:translate-x-1" />
          </button>
        ))}
      </div>
    </>
  );
}
```
(O `useSearchParams()` dentro do inicializador do `useState` quebra a regra dos hooks — leia antes: `const busca = useSearchParams();` no topo do componente e `useState(() => perguntaDaUrl(busca.get("pergunta")))`. O teste do 429 depende de o erro trazer `details.retry_after`; se o `ApiExceptionRenderer` do backend mandar o tempo em outro lugar (cabeçalho `Retry-After`), leia de lá no `client.ts` e registre a ruling.)

`src/app/(app)/nutri/[conversa]/page.tsx`:
```tsx
"use client";

import { useParams } from "next/navigation";
import { ChatTela } from "@/features/nutri/components/ChatTela";

export default function Chat() {
  const { conversa } = useParams<{ conversa: string }>();
  return <ChatTela id={Number(conversa)} />;
}
```

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project integration src/features/nutri && npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(nutri): chat ligado à API — perguntas, cartões, ações, chips, falha e offline (RF20, RF21)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Tirar o mock do Nutri

**Files (repo front):**
- Modify: `src/lib/mock-api.ts` (tira `askNutri`, `buildNutriContext`, `getSubstitutions` se ninguém mais usar), `src/lib/plan-store.tsx` (tira `substituirAlimento`, `aplicarRefeicao`, `alternarRefeicao`, `desfazer`, `ultimaAlteracao`, `limparAviso` se ninguém mais usar), `src/lib/types.ts` (tipos `Nutri*` do protótipo sem uso), `src/mocks/fixtures/mock-data.ts` (`mockSuggestions` sem uso), `eslint.config.mjs`

**Interfaces:**
- Produces: nada novo; o protótipo do Nutri sai.

- [ ] **Step 1: Encontrar o que ficou sem uso**

```bash
cd /home/alvez/atividade-extensionista/frontend
for s in askNutri buildNutriContext getSubstitutions mockSuggestions substituirAlimento aplicarRefeicao alternarRefeicao ultimaAlteracao limparAviso NutriMessage NutriAction NutriSuggestion NutriContext; do
  echo "== $s"; grep -rn "\b$s\b" src --include=*.ts --include=*.tsx | grep -v "^src/lib/mock-api.ts\|^src/lib/plan-store.tsx\|^src/lib/types.ts\|^src/mocks/fixtures/mock-data.ts" | head -3
done
```
Expected: os que não listarem nenhum uso fora dos próprios arquivos saem.

- [ ] **Step 2: Apagar o que não tem uso e conferir**

Remova as funções/tipos/fixtures sem uso apontados no Step 1 (e os `import` que sobrarem). No `eslint.config.mjs`, tire `'src/app/(app)/nutri/page.tsx'` dos blocos LEGADO se ainda estiver.

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm run build"`
Expected: tudo verde.

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "refactor(nutri): tira o Nutri de protótipo (askNutri e afins)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: E2E-08 e a parte do Nutri no E2E-06

**Files (repo front):**
- Create: `e2e/nutri.spec.ts`
- Modify: `e2e/contas.ts` (tipo `'nutri'` em `conta`)

**Interfaces:**
- Consumes: contas `nutri-{b}` (05A Task 8) e `alergia-{b}` (04B); `FakeAiClient` com os cenários do chat (05A Task 5).

- [ ] **Step 1: Subir o backend do 05A e semear**

```bash
cd /home/alvez/atividade-extensionista/backend
git switch plano-05a-nutri-api
export AI_FAKE_FAIL_PLAN_FOR=falha-chromium@e2e.pratoforte.test,falha-webkit@e2e.pratoforte.test
docker compose up -d && docker compose exec api php artisan migrate:fresh --seeder=E2ESeeder --force
```
Expected: sem erro; `queue` rodando.

- [ ] **Step 2: Escrever os E2E**

`e2e/contas.ts` — no tipo de `conta`, acrescentar `'nutri'`.

`e2e/nutri.spec.ts`:
```ts
import { expect, test } from '@playwright/test';
import { conta, entrar } from './contas';

test('conversa nova, ação aplicada, voltar e continuar a anterior (E2E-08)', async ({ page, browserName }) => {
  await entrar(page, conta('nutri', browserName));
  await expect(page).toHaveURL(/\/hoje$/);

  await page.getByRole('link', { name: /^Perguntar ao Nutri sobre/ }).click();
  await expect(page).toHaveURL(/\/nutri\/\d+\?pergunta=/); // sem conversas: abre direto (CA01)
  await expect(page.getByLabel('Escreva sua pergunta para o Nutri')).not.toHaveValue('');

  await page.getByLabel('Escreva sua pergunta para o Nutri').fill('Posso trocar o arroz por batata?');
  await page.getByRole('button', { name: 'Enviar pergunta' }).click();
  const substituir = page.getByRole('button', { name: /^Substituir no .* de hoje$/ });
  await expect(substituir).toBeVisible({ timeout: 15_000 });
  await substituir.click();

  await expect(page.getByText(/^Feito\. Seu .* de hoje vai com /)).toBeVisible();
  await expect(page.getByRole('button', { name: 'Desfazer' })).toBeVisible();
  const primeira = new URL(page.url()).pathname;

  await page.getByRole('link', { name: 'Voltar para as conversas' }).click();
  await expect(page.getByRole('heading', { name: 'Conversas com o Nutri' })).toBeVisible();
  await expect(page.getByRole('link', { name: /Posso trocar o arroz por batata\?/ })).toBeVisible();

  await page.getByRole('button', { name: 'Nova conversa' }).click();
  await expect(page.getByRole('heading', { name: /^No que posso ajudar/ })).toBeVisible();
  await page.getByRole('link', { name: 'Voltar para as conversas' }).click();

  await page.getByRole('link', { name: /Posso trocar o arroz por batata\?/ }).click();
  await expect(page).toHaveURL(new RegExp(`${primeira}$`));
  await expect(page.getByText('Posso trocar o arroz por batata?', { exact: true })).toBeVisible();
  await expect(page.getByText(/^Feito\. Seu .* de hoje vai com /)).toBeVisible();
});

test('com alergia a castanhas, o Nutri não oferece trocar por castanha (E2E-06, parte Nutri)', async ({ page, browserName }) => {
  await entrar(page, conta('alergia', browserName));
  await expect(page).toHaveURL(/\/hoje$/);
  await page.goto('/nutri');
  await expect(page).toHaveURL(/\/nutri\/\d+$/);

  await page.getByLabel('Escreva sua pergunta para o Nutri').fill('Posso pôr castanha no lanche?');
  await page.getByRole('button', { name: 'Enviar pergunta' }).click();

  await expect(page.getByText(/^Com a sua restrição, castanha fica de fora/)).toBeVisible({ timeout: 15_000 });
  await expect(page.getByRole('button', { name: /^Substituir no/ })).toHaveCount(0);
  await expect(page.getByRole('group', { name: /castanha/i })).toHaveCount(0);
  await expect(page.getByRole('group', { name: 'Sugestões de pergunta' })).not.toContainText(/castanha|amendoim/i);
});
```
(O cenário "castanha" do `FakeAiClient` responde de forma segura para quem tem a alergia — revisão do 05A; o que o app garante por código é que nada vira ação, cartão ou chip. Com a IA de verdade, o texto depende do prompt.)

- [ ] **Step 3: Rodar**

Run: `docker compose run --rm web npm run e2e`
Expected: PASS em chromium e webkit (os anteriores + 2 novos).

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "test(e2e): conversa com o Nutri, ação aplicada e alergia (E2E-08, E2E-06)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Acabamento visual (D11) com `frontend-design`

**Files (repo front):**
- Modify: `src/features/nutri/components/*.tsx` (só visual)

- [ ] **Step 1: Refinar com o skill `frontend-design`**

Invocar `frontend-design` com: "Refinar a tela Conversas (nova) e o chat do Nutri do Prato Forte: a lista de conversas e o botão 'Nova conversa' ganham identidade do mock (tinta, gema, mata, papel), a chegada de uma resposta e de uma confirmação ganham um momento de movimento que mostre o que mudou, chips entram em cascata; sem mudar props, textos, roles, aria nem ids; só tokens do globals.css e animações transform/opacity; reduced-motion respeitado."

- [ ] **Step 2: Suíte, build e E2E**

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm run build"` e, com o banco recém-semeado, `docker compose run --rm web npm run e2e`.
Expected: tudo verde.

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "style(nutri): acabamento das conversas e do chat (D11)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
