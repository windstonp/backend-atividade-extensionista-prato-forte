# Plano 08B — Validação com a comunidade: telas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ligar a validação (spec 07) no app: 👍/👎 nas respostas do Nutri e no plano pronto (RF31), convite em Hoje, item "Avaliar o app" no Perfil e o questionário de 13 telas em `/perfil/avaliar` (RF32), termo atualizado (RN03/RN42) e o E2E-12.

**Architecture:** `src/features/validacao`: tipos, chamadas (`lib/api/validacao.ts`), hooks (`useAvaliacao` com estado local otimista e reversão; status/resposta/dispensa do questionário), componentes `RatingButtons` (apresentação) + `Avaliacao` (liga o hook), `InviteBanner`, `LikertQuestion` e o contêiner `QuestionarioTela`, que reaproveita a casca `OnboardingStep` (ganha `aoVoltar`). `RespostaBubble` ganha a prop opcional `rodape`.

**Tech Stack:** Next 16.3.5, React 19.2, TanStack Query 5, Storybook 10.6, Vitest 4.1, MSW 2, Playwright 1.63.

**Spec:** `specs/07-validacao-feedback/spec.md` (§2 RF31–RF32, §3, §4, §7 CA01–CA06, §8, §9). Contrato: Plano 08A (`PUT/DELETE /ratings`, `GET /usability-responses/status`, `POST /usability-responses`, `POST /usability-responses/dismiss`, `rating` nas mensagens e no plano; `terms_version` `2026-10`).

**Onde rodar:** front em `/home/alvez/atividade-extensionista/frontend`, branch `plano-08b-validacao-telas` saindo de `plano-07c-unidades`. E2E com o backend na branch `plano-08a-validacao-api`.

## Decisões deste plano (rulings sobre a spec)

1. **Estado da avaliação** fica no componente (inicial = `rating` da API) com salvamento otimista; recarregar a conversa traz o valor do servidor (CA01). Nada de mexer no cache de mensagens.
2. **Comentário (👎):** abre um campo "O que não ajudou?" com "Enviar" e "Pular"; "Enviar" manda `PUT` de novo com `comment`; "Pular" só fecha. Trocar para 👍 limpa o comentário (o servidor também limpa).
3. **Questionário:** estado só no cliente (spec §4); "Voltar" navega entre as telas sem perder respostas; a tela 1 volta para `/perfil`.
4. **409 no envio** ⇒ tela de agradecimento, como sucesso.
5. **Termo:** novo parágrafo sobre os comentários livres; `TERMO_VERSAO = '2026-10'` (casado com o backend 08A).

## Global Constraints

- Rótulos: "Resposta útil" / "Resposta não ajudou"; no plano "O plano faz sentido" / "O plano não faz sentido"; pergunta no Pronto "Esse plano faz sentido para você?"; comentário "O que não ajudou?" (até 500), "Enviar", "Pular".
- Convite: "Você já usa o Prato Forte há uma semana. Topa responder umas perguntas rápidas? Leva uns 2 minutos e ajuda o projeto da UNINTER." com "Responder" e "Agora não".
- Perfil: item "Avaliar o app" (→ `/perfil/avaliar`); respondido ⇒ "Obrigado por avaliar!" e sem link.
- Questionário: as 10 afirmações SUS e as escalas **exatamente** como o §4 N08; tela 11 "As sugestões do plano e do Nutri foram úteis para você?" (1 Nada úteis … 5 Muito úteis); telas 12 "O que mais te ajudou?" e 13 "O que atrapalhou ou faltou?" (opcionais, até 1.000) + "Enviar"; agradecimento com "Voltar para o app".
- `aria-pressed` nos botões de avaliação; `radiogroup` com setas no Likert.
- D11 com `frontend-design`.

## Review Focus

1. **Toque duplo rápido no 👍** ⇒ termina marcado ou desmarcado de forma coerente com o servidor (um pedido por vez) (Task 2).
2. **Erro ao avaliar** ⇒ volta ao estado anterior e avisa (Task 2).
3. **Erro no envio do questionário** ⇒ fica na última tela com "Tentar de novo", nada se perde (Task 4).
4. **"Agora não" e voltar a Hoje** ⇒ o convite não reaparece (Task 5).
5. **Texto aberto com 1.000+ caracteres** ⇒ o campo não deixa passar de 1.000 (Task 4).

---

### Task 1: Tipos, chamadas, hooks e MSW

**Files (repo front):**
- Create: `src/features/validacao/{tipos,hooks}.ts`, `src/lib/api/validacao.ts`, `src/mocks/fixtures/validacao.ts`, `src/mocks/handlers/validacao.ts`
- Modify: `src/lib/chaves.ts`, `src/mocks/handlers/index.ts`, `src/features/nutri/tipos.ts` (`rating`), `src/features/dia/tipos.ts` (`Plano.rating`)
- Test: `src/features/validacao/hooks.integration.test.tsx`

**Interfaces:**
- Produces: `ValorAvaliacao = 'up' | 'down'`; `Avaliacao = { value: ValorAvaliacao; comment: string | null }`; `Alvo = { tipo: 'nutri_message' | 'meal_plan'; id: number }`; `StatusUsabilidade = { round: string; responded: boolean; invite: boolean }`; `RespostaUsabilidade = { susAnswers: number[]; usefulness: number; liked: string | null; disliked: string | null }`.
  - API: `avaliar(alvo, value, comment?)`, `removerAvaliacao(alvo)`, `getStatusUsabilidade()`, `responderQuestionario(resposta)`, `dispensarConvite()`.
  - Hooks: `useAvaliacao(alvo, inicial): { valor: Avaliacao | null; salvando: boolean; marcar(v: ValorAvaliacao): void; comentar(texto: string): void }`; `useStatusUsabilidade()`, `useResponderQuestionario()`, `useDispensarConvite()` (otimista: `invite` = false).
  - `CHAVES.usabilidade`.

- [ ] **Step 1: Branch e teste que deve falhar**

```bash
cd /home/alvez/atividade-extensionista/frontend
git switch plano-07c-unidades && git switch -c plano-08b-validacao-telas
```

`src/features/validacao/hooks.integration.test.tsx`:
```tsx
import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { delay, http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { Toaster } from '@/components/ui/Toaster';
import { CHAVES } from '@/lib/chaves';
import { url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { novoClienteDeTeste } from '@/test/renderizar';
import { useAvaliacao, useDispensarConvite, useStatusUsabilidade } from './hooks';
import type { StatusUsabilidade } from './tipos';

function comCliente() {
  const cliente = novoClienteDeTeste();
  const wrapper = ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={cliente}>
      <Toaster>{children}</Toaster>
    </QueryClientProvider>
  );
  return { cliente, wrapper };
}

const alvo = { tipo: 'nutri_message' as const, id: 12 };

describe('useAvaliacao', () => {
  it('marca na hora, manda o PUT e tocar de novo remove (DELETE)', async () => {
    const pedidos: string[] = [];
    server.use(
      http.put(url('/ratings'), async ({ request }) => {
        pedidos.push(`PUT ${JSON.stringify(await request.json())}`);
        return HttpResponse.json({ data: {} });
      }),
      http.delete(url('/ratings'), () => {
        pedidos.push('DELETE');
        return new HttpResponse(null, { status: 204 });
      }),
    );
    const { result } = renderHook(() => useAvaliacao(alvo, null), comCliente());

    act(() => result.current.marcar('up'));
    expect(result.current.valor).toEqual({ value: 'up', comment: null });
    await waitFor(() => expect(result.current.salvando).toBe(false));
    act(() => result.current.marcar('up'));
    await waitFor(() => expect(result.current.valor).toBeNull());
    await waitFor(() => expect(pedidos).toEqual(['PUT {"rateable_type":"nutri_message","rateable_id":12,"value":"up","comment":null}', 'DELETE']));
  });

  it('comentário vai junto do 👎', async () => {
    let corpo: unknown;
    server.use(http.put(url('/ratings'), async ({ request }) => ((corpo = await request.json()), HttpResponse.json({ data: {} }))));
    const { result } = renderHook(() => useAvaliacao(alvo, { value: 'down', comment: null }), comCliente());

    act(() => result.current.comentar('Não tenho batata-doce em casa.'));

    await waitFor(() => expect(corpo).toEqual({ rateable_type: 'nutri_message', rateable_id: 12, value: 'down', comment: 'Não tenho batata-doce em casa.' }));
  });

  it('erro: volta ao que era', async () => {
    server.use(http.put(url('/ratings'), async () => (await delay(50), HttpResponse.json({ message: 'x', code: 'SERVER_ERROR' }, { status: 500 }))));
    const { result } = renderHook(() => useAvaliacao(alvo, { value: 'down', comment: null }), comCliente());

    act(() => result.current.marcar('up'));
    expect(result.current.valor?.value).toBe('up');
    await waitFor(() => expect(result.current.valor?.value).toBe('down'));
  });
});

describe('questionário', () => {
  it('"Agora não" some com o convite na hora', async () => {
    server.use(http.get(url('/usability-responses/status'), () => HttpResponse.json({ data: { round: '2026-1', responded: false, invite: true } })));
    const { cliente, wrapper } = comCliente();
    const { result } = renderHook(() => ({ status: useStatusUsabilidade(), dispensar: useDispensarConvite() }), { wrapper });
    await waitFor(() => expect(result.current.status.data?.invite).toBe(true));

    act(() => result.current.dispensar.mutate());

    expect(cliente.getQueryData<StatusUsabilidade>(CHAVES.usabilidade)?.invite).toBe(false);
  });
});
```

Run: `docker compose run --rm web npx vitest run --project integration src/features/validacao`
Expected: FAIL — módulos inexistentes.

- [ ] **Step 2: Implementar**

`src/features/validacao/tipos.ts`:
```ts
export type ValorAvaliacao = 'up' | 'down';

export interface Avaliacao {
  value: ValorAvaliacao;
  comment: string | null;
}

export interface Alvo {
  tipo: 'nutri_message' | 'meal_plan';
  id: number;
}

export interface StatusUsabilidade {
  round: string;
  responded: boolean;
  invite: boolean;
}

export interface RespostaUsabilidade {
  susAnswers: number[];
  usefulness: number;
  liked: string | null;
  disliked: string | null;
}
```

`src/lib/api/validacao.ts`:
```ts
import type { Alvo, RespostaUsabilidade, StatusUsabilidade, ValorAvaliacao } from '@/features/validacao/tipos';
import { api } from './client';

type Dados<T> = { data: T };
const corpo = (alvo: Alvo) => ({ rateableType: alvo.tipo, rateableId: alvo.id });

/** PUT /ratings — avaliar de novo substitui (RN40). */
export const avaliar = (alvo: Alvo, value: ValorAvaliacao, comment: string | null = null) =>
  api<unknown>('/ratings', { method: 'PUT', body: { ...corpo(alvo), value, comment } });

/** DELETE /ratings — idempotente. */
export const removerAvaliacao = (alvo: Alvo) => api<void>('/ratings', { method: 'DELETE', body: corpo(alvo) });

export const getStatusUsabilidade = () => api<Dados<StatusUsabilidade>>('/usability-responses/status').then((r) => r.data);

export const responderQuestionario = (resposta: RespostaUsabilidade) =>
  api<Dados<{ round: string; responded: true }>>('/usability-responses', { method: 'POST', body: resposta }).then((r) => r.data);

export const dispensarConvite = () => api<void>('/usability-responses/dismiss', { method: 'POST' });
```

`src/lib/chaves.ts` — `usabilidade: ['usabilidade'],`.

`src/features/validacao/hooks.ts`:
```ts
'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { useToast } from '@/components/ui/Toaster';
import { comoApiError } from '@/lib/api/errors';
import * as validacao from '@/lib/api/validacao';
import { CHAVES } from '@/lib/chaves';
import type { Alvo, Avaliacao, StatusUsabilidade, ValorAvaliacao } from './tipos';

/**
 * 👍/👎 de uma resposta ou do plano (RF31): muda na hora; um pedido por vez; se falhar, volta e avisa.
 * Tocar no mesmo ícone de novo remove.
 */
export function useAvaliacao(alvo: Alvo, inicial: Avaliacao | null) {
  const avisar = useToast();
  const [valor, setValor] = useState<Avaliacao | null>(inicial);
  const [salvando, setSalvando] = useState(false);
  const fila = useRef<Promise<unknown>>(Promise.resolve());

  function salvar(novo: Avaliacao | null, anterior: Avaliacao | null) {
    setValor(novo);
    setSalvando(true);
    fila.current = fila.current
      .then(() => (novo ? validacao.avaliar(alvo, novo.value, novo.comment) : validacao.removerAvaliacao(alvo)))
      .catch((erro: unknown) => {
        setValor(anterior);
        avisar({ texto: comoApiError(erro).message });
      })
      .finally(() => setSalvando(false));
  }

  return {
    valor,
    salvando,
    marcar: (v: ValorAvaliacao) => salvar(valor?.value === v ? null : { value: v, comment: null }, valor),
    comentar: (texto: string) => salvar({ value: 'down', comment: texto.trim() || null }, valor),
  };
}

export const useStatusUsabilidade = () => useQuery({ queryKey: CHAVES.usabilidade, queryFn: validacao.getStatusUsabilidade });

export function useResponderQuestionario() {
  const cliente = useQueryClient();
  return useMutation({
    mutationFn: validacao.responderQuestionario,
    onSuccess: () => cliente.setQueryData<StatusUsabilidade>(CHAVES.usabilidade, (s) => (s ? { ...s, responded: true, invite: false } : s)),
  });
}

/** "Agora não": o convite some na hora (RF32). */
export function useDispensarConvite() {
  const cliente = useQueryClient();
  return useMutation({
    mutationFn: validacao.dispensarConvite,
    onMutate: () => cliente.setQueryData<StatusUsabilidade>(CHAVES.usabilidade, (s) => (s ? { ...s, invite: false } : s)),
  });
}
```

`src/mocks/fixtures/validacao.ts`:
```ts
export const statusUsabilidadeApi = (parcial: { responded?: boolean; invite?: boolean } = {}) => ({
  round: '2026-1',
  responded: parcial.responded ?? false,
  invite: parcial.invite ?? false,
});
```

`src/mocks/handlers/validacao.ts`:
```ts
import { http, HttpResponse } from 'msw';
import { statusUsabilidadeApi } from '../fixtures/validacao';
import { url } from './auth';

/** Padrão: sem convite; avaliar e responder dão certo. */
export const handlersValidacao = [
  http.put(url('/ratings'), () => HttpResponse.json({ data: {} })),
  http.delete(url('/ratings'), () => new HttpResponse(null, { status: 204 })),
  http.get(url('/usability-responses/status'), () => HttpResponse.json({ data: statusUsabilidadeApi() })),
  http.post(url('/usability-responses'), () => HttpResponse.json({ data: { round: '2026-1', responded: true } }, { status: 201 })),
  http.post(url('/usability-responses/dismiss'), () => new HttpResponse(null, { status: 204 })),
];
```
`src/mocks/handlers/index.ts` — acrescentar `handlersValidacao`.

Tipos existentes: em `src/features/nutri/tipos.ts`, `rating: null;` ⇒ `rating: Avaliacao | null;`; em `src/features/dia/tipos.ts`, `Plano` ganha `rating?: Avaliacao | null;` (import de `@/features/validacao/tipos`).

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project integration src/features/validacao && npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(validacao): tipos, chamadas, hooks e MSW da validação

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `RatingButtons` e `Avaliacao` — no chat e no plano pronto

**Files (repo front):**
- Create: `src/features/validacao/components/{RatingButtons,Avaliacao}.tsx`, `src/features/validacao/components/RatingButtons.stories.tsx`
- Modify: `src/features/nutri/components/ChatBubble.tsx` (prop `rodape`), `src/features/nutri/components/ChatTela.tsx`, `src/features/dia/components/ProntoTela.tsx`
- Test: `src/features/nutri/components/ChatTela.integration.test.tsx`, `src/features/dia/components/ProntoTela.integration.test.tsx` (acrescentar)

**Interfaces:**
- Produces: `RatingButtons({ variante: 'resposta' | 'plano'; valor: Avaliacao | null; salvando?: boolean; aoMarcar(v); aoComentar(texto) })`; `Avaliacao({ alvo, inicial, variante })`; `RespostaBubble` com `rodape?: React.ReactNode`.

- [ ] **Step 1: Testes que devem falhar**

`src/features/validacao/components/RatingButtons.stories.tsx`:
```tsx
import { useState } from 'react';
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import type { Avaliacao } from '../tipos';
import { RatingButtons } from './RatingButtons';

const meta = {
  title: 'Validação/RatingButtons',
  component: RatingButtons,
  args: { variante: 'resposta', valor: null, aoMarcar: fn(), aoComentar: fn() },
  render: function Controlado(args) {
    const [valor, setValor] = useState<Avaliacao | null>(args.valor);
    return (
      <RatingButtons
        {...args}
        valor={valor}
        aoMarcar={(v) => {
          setValor(valor?.value === v ? null : { value: v, comment: null });
          args.aoMarcar(v);
        }}
      />
    );
  },
} satisfies Meta<typeof RatingButtons>;
export default meta;
type Story = StoryObj<typeof meta>;

export const Neutro: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await expect(tela.getByRole('button', { name: 'Resposta útil' })).toHaveAttribute('aria-pressed', 'false');
    await userEvent.click(tela.getByRole('button', { name: 'Resposta útil' }));
    await expect(args.aoMarcar).toHaveBeenCalledWith('up');
    await expect(tela.getByRole('button', { name: 'Resposta útil' })).toHaveAttribute('aria-pressed', 'true');
  },
};

export const Positivo: Story = { args: { valor: { value: 'up', comment: null } } };

export const NegativoComComentario: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await userEvent.click(tela.getByRole('button', { name: 'Resposta não ajudou' }));
    await userEvent.type(tela.getByLabelText('O que não ajudou?'), 'Não tenho batata-doce em casa.');
    await userEvent.click(tela.getByRole('button', { name: 'Enviar' }));
    await expect(args.aoComentar).toHaveBeenCalledWith('Não tenho batata-doce em casa.');
    await expect(tela.queryByLabelText('O que não ajudou?')).toBeNull();
  },
};

export const Pular: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await userEvent.click(tela.getByRole('button', { name: 'Resposta não ajudou' }));
    await userEvent.click(tela.getByRole('button', { name: 'Pular' }));
    await expect(args.aoComentar).not.toHaveBeenCalled();
    await expect(tela.queryByLabelText('O que não ajudou?')).toBeNull();
  },
};

export const Plano: Story = {
  args: { variante: 'plano' },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByRole('button', { name: 'O plano faz sentido' })).toBeInTheDocument();
    await expect(tela.getByRole('button', { name: 'O plano não faz sentido' })).toBeInTheDocument();
  },
};

export const Teclado: Story = {
  play: async ({ canvasElement, args }) => {
    await userEvent.tab();
    await userEvent.keyboard('{Enter}');
    await expect(args.aoMarcar).toHaveBeenCalledWith('up');
    await expect(within(canvasElement).getByRole('button', { name: 'Resposta útil' })).toHaveFocus();
  },
};
```

Em `ChatTela.integration.test.tsx`, acrescentar:
```tsx
  it('avaliar a resposta: 👍 marca e vai para a API (CA01)', async () => {
    let corpo: unknown;
    server.use(
      conversa(5),
      respondendoMensagens(5, [{ ...respostaTrocaApi(12, { acoes: false }), rating: null }, mensagemUsuarioApi(11)]),
      http.put(url('/ratings'), async ({ request }) => ((corpo = await request.json()), HttpResponse.json({ data: {} }))),
    );

    renderizar(<ChatTela id={5} />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Resposta útil' }));

    await waitFor(() => expect(corpo).toEqual({ rateable_type: 'nutri_message', rateable_id: 12, value: 'up', comment: null }));
    expect(screen.getByRole('button', { name: 'Resposta útil' })).toHaveAttribute('aria-pressed', 'true');
  });

  it('avaliação salva volta marcada ao abrir a conversa', async () => {
    server.use(conversa(5), respondendoMensagens(5, [{ ...respostaTrocaApi(12, { acoes: false }), rating: { value: 'down', comment: 'x' } }, mensagemUsuarioApi(11)]));

    renderizar(<ChatTela id={5} />);

    expect(await screen.findByRole('button', { name: 'Resposta não ajudou' })).toHaveAttribute('aria-pressed', 'true');
  });
```

Em `ProntoTela.integration.test.tsx` (ver o arranjo que já existe para o plano pronto), acrescentar:
```tsx
  it('pergunta se o plano faz sentido e avalia o plano', async () => {
    let corpo: unknown;
    server.use(http.put(url('/ratings'), async ({ request }) => ((corpo = await request.json()), HttpResponse.json({ data: {} }))));
    // … mesmo arranjo do teste "plano pronto" deste arquivo (plano 42 pronto)

    renderizar(<ProntoTela />);
    expect(await screen.findByText('Esse plano faz sentido para você?')).toBeInTheDocument();
    await userEvent.setup().click(screen.getByRole('button', { name: 'O plano faz sentido' }));

    await waitFor(() => expect(corpo).toMatchObject({ rateable_type: 'meal_plan', value: 'up' }));
  });
```
(complete o arranjo copiando o do teste existente de plano pronto no mesmo arquivo; o `rateable_id` é o id do plano.)

Run: `docker compose run --rm web bash -c "npx vitest run --project storybook src/features/validacao; npx vitest run --project integration src/features/nutri/components/ChatTela.integration.test.tsx src/features/dia/components/ProntoTela.integration.test.tsx"`
Expected: FAIL.

- [ ] **Step 2: Implementar**

`src/features/validacao/components/RatingButtons.tsx`:
```tsx
"use client";

import { useId, useState } from "react";
import type { Avaliacao, ValorAvaliacao } from "../tipos";

const ROTULOS = {
  resposta: { up: "Resposta útil", down: "Resposta não ajudou" },
  plano: { up: "O plano faz sentido", down: "O plano não faz sentido" },
} as const;

function Polegar({ para, cheio }: { para: ValorAvaliacao; cheio: boolean }) {
  return (
    <svg viewBox="0 0 24 24" width={18} height={18} aria-hidden="true" className={para === "down" ? "rotate-180" : ""}>
      <path
        d="M7 10v11H4V10h3Zm2 11h8.2a2 2 0 0 0 2-1.6l1.3-6.6A2 2 0 0 0 18.5 10H14l.7-3.8A2.3 2.3 0 0 0 12.4 3.5L9 10v11Z"
        fill={cheio ? "currentColor" : "none"}
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinejoin="round"
      />
    </svg>
  );
}

/** 👍/👎 (RF31). Depois do 👎, pergunta o que não ajudou (opcional). */
export function RatingButtons({
  variante,
  valor,
  salvando = false,
  aoMarcar,
  aoComentar,
}: {
  variante: "resposta" | "plano";
  valor: Avaliacao | null;
  salvando?: boolean;
  aoMarcar: (v: ValorAvaliacao) => void;
  aoComentar: (texto: string) => void;
}) {
  const [comentando, setComentando] = useState(false);
  const [texto, setTexto] = useState("");
  const idCampo = useId();
  const rotulos = ROTULOS[variante];

  function marcar(v: ValorAvaliacao) {
    setComentando(v === "down" && valor?.value !== "down");
    aoMarcar(v);
  }

  return (
    <div className="mt-2.5">
      <div className="flex gap-1.5">
        {(["up", "down"] as const).map((v) => {
          const marcado = valor?.value === v;
          return (
            <button
              key={v}
              type="button"
              aria-label={rotulos[v]}
              aria-pressed={marcado}
              aria-busy={salvando || undefined}
              onClick={() => marcar(v)}
              className={`flex size-9 items-center justify-center rounded-full border transition-[background-color,color,border-color,transform] duration-250 active:scale-90 ${
                marcado ? (v === "up" ? "animate-pop border-mata bg-mata text-neve" : "animate-pop border-alerta bg-alerta text-neve") : "border-linha bg-white text-fumo hover:border-pedra"
              }`}
            >
              <Polegar para={v} cheio={marcado} />
            </button>
          );
        })}
      </div>
      {comentando ? (
        <div className="mt-2.5 animate-entra rounded-2xl bg-white p-3">
          <label htmlFor={idCampo} className="text-[13px] font-semibold">
            O que não ajudou?
          </label>
          <textarea
            id={idCampo}
            value={texto}
            maxLength={500}
            onChange={(e) => setTexto(e.target.value)}
            rows={2}
            className="mt-1.5 w-full resize-none rounded-xl border border-linha px-3 py-2 text-[14px] focus:border-tinta focus:outline-none"
          />
          <div className="mt-2 flex justify-end gap-2">
            <button type="button" onClick={() => setComentando(false)} className="h-9 rounded-full px-3.5 text-[13px] font-semibold text-fumo">
              Pular
            </button>
            <button
              type="button"
              disabled={texto.trim() === ""}
              onClick={() => {
                aoComentar(texto);
                setComentando(false);
              }}
              className="h-9 rounded-full bg-tinta px-4 text-[13px] font-semibold text-neve disabled:opacity-40"
            >
              Enviar
            </button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
```

`src/features/validacao/components/Avaliacao.tsx`:
```tsx
"use client";

import { useAvaliacao } from "../hooks";
import type { Alvo, Avaliacao as Valor } from "../tipos";
import { RatingButtons } from "./RatingButtons";

/** Liga os botões à API; o valor inicial vem da API (`rating`). */
export function Avaliacao({ alvo, inicial, variante }: { alvo: Alvo; inicial: Valor | null; variante: "resposta" | "plano" }) {
  const { valor, salvando, marcar, comentar } = useAvaliacao(alvo, inicial);
  return <RatingButtons variante={variante} valor={valor} salvando={salvando} aoMarcar={marcar} aoComentar={comentar} />;
}
```

`ChatBubble.tsx` — `RespostaBubble` ganha `rodape?: React.ReactNode` e o renderiza depois do `NutriActions`.
`ChatTela.tsx` — `<RespostaBubble … rodape={<Avaliacao alvo={{ tipo: "nutri_message", id: m.id }} inicial={m.rating} variante="resposta" />} />`.
`ProntoTela.tsx` — depois da `section` do dia comum:
```tsx
        <div className="mt-5 animate-entra" style={{ animationDelay: "900ms" }}>
          <p className="text-[14px] font-semibold">Esse plano faz sentido para você?</p>
          <Avaliacao alvo={{ tipo: "meal_plan", id: plano.data.id }} inicial={plano.data.rating ?? null} variante="plano" />
        </div>
```

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project storybook src/features/validacao && npx vitest run --project integration src/features && npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(validacao): 👍/👎 nas respostas do Nutri e no plano pronto (RF31, CA01, CA02)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `LikertQuestion` e `aoVoltar` no `OnboardingStep`

**Files (repo front):**
- Create: `src/features/validacao/components/LikertQuestion.tsx`, `src/features/validacao/components/LikertQuestion.stories.tsx`
- Modify: `src/features/onboarding/components/OnboardingStep.tsx` (`aoVoltar?`)

**Interfaces:**
- Produces: `LikertQuestion({ rotulo, opcoes: string[], valor: number | null, aoEscolher(n) })` — `radiogroup` com `OptionRow`, setas movem a escolha; `OnboardingStep` com `aoVoltar?: () => void` (botão "Voltar" quando não há `voltarPara`).

- [ ] **Step 1: Stories que devem falhar**

`src/features/validacao/components/LikertQuestion.stories.tsx`:
```tsx
import { useState } from 'react';
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { LikertQuestion } from './LikertQuestion';

const ESCALA = ['Discordo totalmente', 'Discordo', 'Neutro', 'Concordo', 'Concordo totalmente'];

const meta = {
  title: 'Validação/LikertQuestion',
  component: LikertQuestion,
  args: { rotulo: 'Eu usaria o Prato Forte com frequência.', opcoes: ESCALA, valor: null, aoEscolher: fn() },
  render: function Controlado(args) {
    const [valor, setValor] = useState(args.valor);
    return <LikertQuestion {...args} valor={valor} aoEscolher={(n) => { setValor(n); args.aoEscolher(n); }} />;
  },
} satisfies Meta<typeof LikertQuestion>;
export default meta;
type Story = StoryObj<typeof meta>;

export const SemResposta: Story = {
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByRole('radiogroup', { name: 'Eu usaria o Prato Forte com frequência.' })).toBeInTheDocument();
    await expect(tela.getAllByRole('radio').filter((r) => r.getAttribute('aria-checked') === 'true')).toHaveLength(0);
  },
};

export const Respondida: Story = {
  play: async ({ canvasElement, args }) => {
    await userEvent.click(within(canvasElement).getByRole('radio', { name: 'Concordo' }));
    await expect(args.aoEscolher).toHaveBeenCalledWith(4);
  },
};

export const Setas: Story = {
  args: { valor: 3 },
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    tela.getByRole('radio', { name: 'Neutro' }).focus();
    await userEvent.keyboard('{ArrowDown}');
    await expect(args.aoEscolher).toHaveBeenLastCalledWith(4);
    await expect(tela.getByRole('radio', { name: 'Concordo' })).toHaveFocus();
  },
};
```

Run: `docker compose run --rm web npx vitest run --project storybook src/features/validacao/components/LikertQuestion.stories.tsx`
Expected: FAIL.

- [ ] **Step 2: Implementar**

`src/features/validacao/components/LikertQuestion.tsx`:
```tsx
"use client";

import { useRef } from "react";
import { OptionRow } from "@/components/ui/OptionRow";
import { cascata } from "@/lib/motion";

/** Escala de 5 pontos como grupo de rádio: setas trocam a escolha (WAI-ARIA). Valores 1…n. */
export function LikertQuestion({ rotulo, opcoes, valor, aoEscolher }: { rotulo: string; opcoes: string[]; valor: number | null; aoEscolher: (n: number) => void }) {
  const grupo = useRef<HTMLDivElement>(null);

  function mover(evento: React.KeyboardEvent) {
    const passo = evento.key === "ArrowDown" || evento.key === "ArrowRight" ? 1 : evento.key === "ArrowUp" || evento.key === "ArrowLeft" ? -1 : 0;
    if (passo === 0) return;
    evento.preventDefault();
    const proximo = Math.min(opcoes.length, Math.max(1, (valor ?? 0) + passo));
    aoEscolher(proximo);
    grupo.current?.querySelectorAll<HTMLElement>('[role="radio"]')[proximo - 1]?.focus();
  }

  return (
    <div ref={grupo} role="radiogroup" aria-label={rotulo} onKeyDown={mover} className="flex flex-col gap-2">
      {opcoes.map((opcao, i) => (
        <OptionRow key={opcao} marcado={valor === i + 1} onClick={() => aoEscolher(i + 1)} titulo={opcao} compacto className="animate-entra" style={cascata(i, 50, 160)} />
      ))}
    </div>
  );
}
```

`OnboardingStep.tsx` — prop `aoVoltar?: () => void`; no cabeçalho, quando não há `voltarPara` e há `aoVoltar`, um `<button type="button" aria-label="Voltar" onClick={aoVoltar} …>` com o mesmo visual do `Link`.

- [ ] **Step 3: Rodar, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project storybook src/features/validacao && npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(validacao): escala Likert acessível e voltar por callback na casca das etapas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Questionário (`/perfil/avaliar`)

**Files (repo front):**
- Create: `src/features/validacao/perguntas.ts`, `src/features/validacao/components/QuestionarioTela.tsx`, `src/features/validacao/components/QuestionarioTela.integration.test.tsx`, `src/app/(app)/perfil/avaliar/page.tsx`

**Interfaces:**
- Consumes: Tasks 1 e 3; `OnboardingStep`, `EmptyState` de agradecimento (`ButtonLink`).
- Produces: `AFIRMACOES_SUS` (10), `ESCALA_SUS` (5), `ESCALA_UTILIDADE` (5); `QuestionarioTela`.

- [ ] **Step 1: Teste que deve falhar**

`src/features/validacao/components/QuestionarioTela.integration.test.tsx`:
```tsx
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { erroDaApi, url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { redefinirNavegacao } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { QuestionarioTela } from './QuestionarioTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));
beforeEach(() => redefinirNavegacao());

const RESPOSTAS = ['Concordo', 'Discordo', 'Concordo totalmente', 'Discordo totalmente', 'Concordo', 'Discordo', 'Concordo totalmente', 'Discordo totalmente', 'Concordo', 'Discordo'];

async function responderAteOFim(usuario: ReturnType<typeof userEvent.setup>) {
  for (const [i, resposta] of RESPOSTAS.entries()) {
    expect(await screen.findByText(`Etapa ${i + 1} de 13`)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Continuar' })).toBeDisabled();
    await usuario.click(screen.getByRole('radio', { name: resposta }));
    await usuario.click(screen.getByRole('button', { name: 'Continuar' }));
  }
  await usuario.click(await screen.findByRole('radio', { name: '4' }));
  await usuario.click(screen.getByRole('button', { name: 'Continuar' }));
  await usuario.type(await screen.findByLabelText('O que mais te ajudou?'), 'Os horários batem com o meu treino.');
  await usuario.click(screen.getByRole('button', { name: 'Continuar' }));
  expect(await screen.findByLabelText('O que atrapalhou ou faltou?')).toHaveAttribute('maxlength', '1000');
  await usuario.click(screen.getByRole('button', { name: 'Enviar' }));
}

describe('Questionário (N08)', () => {
  it('13 telas, envia as respostas e agradece (CA05)', async () => {
    let corpo: unknown;
    server.use(http.post(url('/usability-responses'), async ({ request }) => ((corpo = await request.json()), HttpResponse.json({ data: { round: '2026-1', responded: true } }, { status: 201 }))));
    const usuario = userEvent.setup();

    renderizar(<QuestionarioTela />);
    await responderAteOFim(usuario);

    expect(await screen.findByRole('heading', { name: 'Obrigado por avaliar!' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Voltar para o app' })).toHaveAttribute('href', '/hoje');
    expect(corpo).toEqual({ sus_answers: [4, 2, 5, 1, 4, 2, 5, 1, 4, 2], usefulness: 4, liked: 'Os horários batem com o meu treino.', disliked: null });
  });

  it('voltar não perde a resposta', async () => {
    const usuario = userEvent.setup();
    renderizar(<QuestionarioTela />);

    await usuario.click(await screen.findByRole('radio', { name: 'Concordo' }));
    await usuario.click(screen.getByRole('button', { name: 'Continuar' }));
    await usuario.click(await screen.findByRole('button', { name: 'Voltar' }));

    expect(await screen.findByRole('radio', { name: 'Concordo' })).toHaveAttribute('aria-checked', 'true');
  });

  it('erro no envio: fica na última tela e "Tentar de novo" reenvia', async () => {
    let tentativas = 0;
    server.use(
      http.post(url('/usability-responses'), () => (++tentativas === 1 ? erroDaApi(500, 'SERVER_ERROR', 'Algo deu errado do nosso lado. Tente de novo.') : HttpResponse.json({ data: { round: '2026-1', responded: true } }, { status: 201 }))),
    );
    const usuario = userEvent.setup();

    renderizar(<QuestionarioTela />);
    await responderAteOFim(usuario);
    expect(await screen.findByText('Algo deu errado do nosso lado. Tente de novo.')).toBeInTheDocument();
    await usuario.click(screen.getByRole('button', { name: 'Tentar de novo' }));

    expect(await screen.findByRole('heading', { name: 'Obrigado por avaliar!' })).toBeInTheDocument();
  });

  it('já respondeu (409): agradece (CA06)', async () => {
    server.use(http.post(url('/usability-responses'), () => erroDaApi(409, 'ALREADY_RESPONDED', 'Você já respondeu. Obrigado!')));
    const usuario = userEvent.setup();

    renderizar(<QuestionarioTela />);
    await responderAteOFim(usuario);

    expect(await screen.findByRole('heading', { name: 'Obrigado por avaliar!' })).toBeInTheDocument();
  });
});
```

Run: `docker compose run --rm web npx vitest run --project integration src/features/validacao/components/QuestionarioTela.integration.test.tsx`
Expected: FAIL.

- [ ] **Step 2: Implementar**

`src/features/validacao/perguntas.ts`:
```ts
/** Questionário SUS adaptado ao Prato Forte (spec 07 §4 N08). A ordem importa para o escore. */
export const AFIRMACOES_SUS = [
  'Eu usaria o Prato Forte com frequência.',
  'Achei o Prato Forte mais complicado do que precisava.',
  'Achei o Prato Forte fácil de usar.',
  'Eu precisaria da ajuda de alguém que entende de tecnologia para usar o Prato Forte.',
  'As partes do Prato Forte (plano, Nutri, evolução) funcionam bem juntas.',
  'O Prato Forte tem coisas que não combinam entre si.',
  'Imagino que a maioria das pessoas aprende a usar o Prato Forte rapidinho.',
  'Achei o Prato Forte atrapalhado de usar.',
  'Me senti seguro(a) usando o Prato Forte.',
  'Precisei aprender muita coisa antes de conseguir usar o Prato Forte.',
];

export const ESCALA_SUS = ['Discordo totalmente', 'Discordo', 'Neutro', 'Concordo', 'Concordo totalmente'];

/** Tela 11: 1 (Nada úteis) a 5 (Muito úteis). */
export const ESCALA_UTILIDADE = ['1', '2', '3', '4', '5'];

export const TOTAL_DE_TELAS = 13;
```

`src/features/validacao/components/QuestionarioTela.tsx`:
```tsx
"use client";

import { useState } from "react";
import { Screen } from "@/components/app/Screen";
import { ButtonLink } from "@/components/ui/Button";
import { OnboardingStep } from "@/features/onboarding/components/OnboardingStep";
import { comoApiError } from "@/lib/api/errors";
import { useResponderQuestionario } from "../hooks";
import { AFIRMACOES_SUS, ESCALA_SUS, ESCALA_UTILIDADE, TOTAL_DE_TELAS } from "../perguntas";
import { LikertQuestion } from "./LikertQuestion";

/** N08 — uma pergunta por tela, respostas só no aparelho até o envio (RF32). */
export function QuestionarioTela() {
  const responder = useResponderQuestionario();
  const [tela, setTela] = useState(1);
  const [sus, setSus] = useState<(number | null)[]>(() => Array(10).fill(null));
  const [utilidade, setUtilidade] = useState<number | null>(null);
  const [ajudou, setAjudou] = useState("");
  const [atrapalhou, setAtrapalhou] = useState("");
  const [obrigado, setObrigado] = useState(false);

  const erro = responder.error ? comoApiError(responder.error) : null;
  if (obrigado || erro?.code === "ALREADY_RESPONDED") {
    return (
      <Screen>
        <main className="flex flex-1 flex-col items-start justify-center px-6">
          <h1 className="animate-entra font-display text-[30px] leading-tight font-bold tracking-[-0.03em]">Obrigado por avaliar!</h1>
          <p className="mt-3 animate-entra text-[15px] leading-relaxed text-fumo" style={{ animationDelay: "100ms" }}>
            Suas respostas ajudam a ajustar o Prato Forte para quem treina na Zfit.
          </p>
          <ButtonLink href="/hoje" className="mt-6 animate-entra" style={{ animationDelay: "200ms" }}>
            Voltar para o app
          </ButtonLink>
        </main>
      </Screen>
    );
  }

  function enviar() {
    responder.mutate(
      { susAnswers: sus.map((n) => n ?? 3), usefulness: utilidade ?? 3, liked: ajudou.trim() || null, disliked: atrapalhou.trim() || null },
      { onSuccess: () => setObrigado(true) },
    );
  }

  const comum = {
    numero: tela,
    total: TOTAL_DE_TELAS,
    voltarPara: tela === 1 ? "/perfil" : null,
    aoVoltar: tela === 1 ? undefined : () => setTela(tela - 1),
  };

  if (tela <= 10) {
    const i = tela - 1;
    return (
      <OnboardingStep key={tela} {...comum} titulo={AFIRMACOES_SUS[i]} descricao="Quanto você concorda?" podeContinuar={sus[i] !== null} aoContinuar={() => setTela(tela + 1)}>
        <LikertQuestion rotulo={AFIRMACOES_SUS[i]} opcoes={ESCALA_SUS} valor={sus[i]} aoEscolher={(n) => setSus(sus.map((v, j) => (j === i ? n : v)))} />
      </OnboardingStep>
    );
  }
  if (tela === 11) {
    return (
      <OnboardingStep key={tela} {...comum} titulo="As sugestões do plano e do Nutri foram úteis para você?" descricao="1 é nada úteis; 5 é muito úteis." podeContinuar={utilidade !== null} aoContinuar={() => setTela(12)}>
        <LikertQuestion rotulo="As sugestões do plano e do Nutri foram úteis para você?" opcoes={ESCALA_UTILIDADE} valor={utilidade} aoEscolher={setUtilidade} />
      </OnboardingStep>
    );
  }
  const ultima = tela === 13;
  const [rotulo, valor, mudar] = ultima ? ["O que atrapalhou ou faltou?", atrapalhou, setAtrapalhou] as const : ["O que mais te ajudou?", ajudou, setAjudou] as const;
  return (
    <OnboardingStep
      key={tela}
      {...comum}
      titulo={rotulo}
      descricao="Opcional. Escreva do seu jeito."
      rotuloBotao={ultima ? (erro ? "Tentar de novo" : "Enviar") : "Continuar"}
      rotuloSalvando="Enviando…"
      salvando={responder.isPending}
      erroAoSalvar={erro}
      aoContinuar={ultima ? enviar : () => setTela(13)}
    >
      <label htmlFor="resposta-aberta" className="sr-only">
        {rotulo}
      </label>
      <textarea
        id="resposta-aberta"
        value={valor}
        maxLength={1000}
        onChange={(e) => mudar(e.target.value)}
        rows={5}
        className="w-full resize-none rounded-2xl border border-linha bg-white px-4 py-3 text-[15px] focus:border-tinta focus:outline-none"
      />
    </OnboardingStep>
  );
}
```
(O `aria-label` do campo é o próprio `label` — o teste procura por `getByLabelText('O que mais te ajudou?')`. Se o `FormError` do `OnboardingStep` mostrar a mensagem do erro, o teste "Tentar de novo" já a encontra.)

`src/app/(app)/perfil/avaliar/page.tsx`:
```tsx
import { QuestionarioTela } from "@/features/validacao/components/QuestionarioTela";

export default function Avaliar() {
  return <QuestionarioTela />;
}
```

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project integration src/features/validacao && npm run lint && npm run typecheck && npm test && npm run build"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(validacao): questionário de usabilidade em 13 telas (RF32, CA05, CA06)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Convite em Hoje, "Avaliar o app" no Perfil e o termo

**Files (repo front):**
- Create: `src/features/validacao/components/InviteBanner.tsx`, `src/features/validacao/components/InviteBanner.stories.tsx`
- Modify: `src/features/dia/components/HojeTela.tsx`, `src/features/perfil/components/PerfilTela.tsx`, `src/features/auth/termo.ts`
- Test: `src/features/dia/components/HojeTela.integration.test.tsx`, `src/features/perfil/components/PerfilTela.integration.test.tsx` (acrescentar)

**Interfaces:**
- Produces: `InviteBanner({ aoDispensar })` (link "Responder" → `/perfil/avaliar`, botão "Agora não").

- [ ] **Step 1: Testes que devem falhar**

`InviteBanner.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { InviteBanner } from './InviteBanner';

const meta = { title: 'Validação/InviteBanner', component: InviteBanner, args: { aoDispensar: fn() } } satisfies Meta<typeof InviteBanner>;
export default meta;
type Story = StoryObj<typeof meta>;

export const Visivel: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await expect(tela.getByText(/^Você já usa o Prato Forte há uma semana\./)).toBeInTheDocument();
    await expect(tela.getByRole('link', { name: 'Responder' })).toHaveAttribute('href', '/perfil/avaliar');
    await userEvent.click(tela.getByRole('button', { name: 'Agora não' }));
    await expect(args.aoDispensar).toHaveBeenCalled();
  },
};
```

`HojeTela.integration.test.tsx` — acrescentar:
```tsx
it('convite do questionário aparece quando elegível e "Agora não" some com ele (CA04)', async () => {
  let dispensou = false;
  server.use(
    http.get(url('/usability-responses/status'), () => HttpResponse.json({ data: { round: '2026-1', responded: false, invite: !dispensou } })),
    http.post(url('/usability-responses/dismiss'), () => ((dispensou = true), new HttpResponse(null, { status: 204 }))),
  );
  renderizar(<HojeTela />);

  await userEvent.setup().click(await screen.findByRole('button', { name: 'Agora não' }));

  expect(screen.queryByText(/^Você já usa o Prato Forte há uma semana\./)).toBeNull();
  await waitFor(() => expect(dispensou).toBe(true));
});
```
`PerfilTela.integration.test.tsx` — acrescentar:
```tsx
it('"Avaliar o app" leva ao questionário; depois de responder, agradece sem link (CA06)', async () => {
  const { unmount } = renderizar(<PerfilTela />);
  expect(await screen.findByRole('link', { name: /Avaliar o app/ })).toHaveAttribute('href', '/perfil/avaliar');
  unmount();

  server.use(http.get(url('/usability-responses/status'), () => HttpResponse.json({ data: { round: '2026-1', responded: true, invite: false } })));
  renderizar(<PerfilTela />);
  expect(await screen.findByText('Obrigado por avaliar!')).toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /Avaliar o app/ })).toBeNull();
});
```
(imports de `waitFor`/`userEvent`/`http`/`HttpResponse`/`url` onde faltarem.)

Run: `docker compose run --rm web bash -c "npx vitest run --project storybook src/features/validacao/components/InviteBanner.stories.tsx; npx vitest run --project integration src/features/dia/components/HojeTela.integration.test.tsx src/features/perfil"`
Expected: FAIL.

- [ ] **Step 2: Implementar**

`InviteBanner.tsx`:
```tsx
import Link from "next/link";

/** Convite para o questionário (RN41), dispensável. */
export function InviteBanner({ aoDispensar }: { aoDispensar: () => void }) {
  return (
    <section className="mt-4 animate-entra-topo rounded-[20px] bg-tinta px-[18px] py-4 text-neve">
      <p className="text-[14px] leading-snug">
        Você já usa o Prato Forte há uma semana. Topa responder umas perguntas rápidas? Leva uns 2 minutos e ajuda o projeto da UNINTER.
      </p>
      <div className="mt-3 flex gap-2">
        <Link href="/perfil/avaliar" className="flex h-10 items-center rounded-full bg-gema px-4 text-[14px] font-semibold text-tinta transition-transform active:scale-95">
          Responder
        </Link>
        <button type="button" onClick={aoDispensar} className="h-10 rounded-full px-3.5 text-[14px] font-semibold text-salvia">
          Agora não
        </button>
      </div>
    </section>
  );
}
```
`HojeTela.tsx` — `const usabilidade = useStatusUsabilidade(); const dispensar = useDispensarConvite();` e, logo no começo do `main` do dia, `{usabilidade.data?.invite ? <InviteBanner aoDispensar={() => dispensar.mutate()} /> : null}`.
`PerfilTela.tsx` — `const usabilidade = useStatusUsabilidade();`; na lista, antes de "Notificações e conta", o item `{ href: "/perfil/avaliar", titulo: "Avaliar o app", valor: "Responda umas perguntas rápidas" }`; quando `usabilidade.data?.responded`, o item vira texto sem link: título "Avaliar o app", valor "Obrigado por avaliar!" (no `map`, renderize `<div>` em vez de `<Link>` para itens com `desabilitado: true`).
`termo.ts` — `TERMO_VERSAO = '2026-10'` e um parágrafo novo depois do da pesquisa: "Se você avaliar uma resposta ou o app e escrever um comentário, ele entra na pesquisa como você escreveu, sem o seu nome."

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project storybook src/features/validacao && npx vitest run --project integration src/features && npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(validacao): convite em Hoje, \"Avaliar o app\" no Perfil e termo 2026-10 (RF32, CA04, CA06)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: E2E-12

**Files (repo front):**
- Create: `e2e/validacao.spec.ts`
- Modify: `e2e/contas.ts` (tipo `'avaliar'`)

- [ ] **Step 1: Backend do 08A semeado**

```bash
cd /home/alvez/atividade-extensionista/backend && git switch plano-08a-validacao-api
docker compose up -d && docker compose exec api php artisan migrate:fresh --seeder=E2ESeeder --force
```

- [ ] **Step 2: Escrever**

`e2e/validacao.spec.ts`:
```ts
import { expect, test } from '@playwright/test';
import { conta, entrar } from './contas';

test('avaliar resposta do Nutri e responder o questionário (E2E-12)', async ({ page, browserName }) => {
  await entrar(page, conta('avaliar', browserName));
  await expect(page).toHaveURL(/\/hoje$/);

  await page.goto('/nutri');
  await expect(page).toHaveURL(/\/nutri\/\d+$/);
  await page.getByLabel('Escreva sua pergunta para o Nutri').fill('Posso trocar o arroz por batata?');
  await page.getByRole('button', { name: 'Enviar pergunta' }).click();
  const util = page.getByRole('button', { name: 'Resposta útil' });
  await expect(util).toBeVisible({ timeout: 15_000 });
  await util.click();
  await expect(util).toHaveAttribute('aria-pressed', 'true');
  await page.reload();
  await expect(page.getByRole('button', { name: 'Resposta útil' })).toHaveAttribute('aria-pressed', 'true');

  await page.goto('/hoje');
  await page.getByRole('link', { name: 'Responder' }).click();
  await expect(page).toHaveURL(/\/perfil\/avaliar$/);
  for (let i = 0; i < 10; i++) {
    await page.getByRole('radio', { name: 'Concordo', exact: true }).click();
    await page.getByRole('button', { name: 'Continuar' }).click();
  }
  await page.getByRole('radio', { name: '4', exact: true }).click();
  await page.getByRole('button', { name: 'Continuar' }).click();
  await page.getByRole('button', { name: 'Continuar' }).click();
  await page.getByRole('button', { name: 'Enviar' }).click();
  await expect(page.getByRole('heading', { name: 'Obrigado por avaliar!' })).toBeVisible();

  await page.goto('/perfil');
  await expect(page.getByText('Obrigado por avaliar!')).toBeVisible();
});
```
`e2e/contas.ts` — acrescentar `'avaliar'` ao tipo.

- [ ] **Step 3: Rodar e commit**

Run: `docker compose run --rm web npm run e2e`
Expected: todos verdes (os anteriores + 1 em cada navegador).

```bash
git add -A && git commit -m "test(e2e): avaliar resposta e responder o questionário (E2E-12)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Acabamento visual (D11) com `frontend-design`

- [ ] **Step 1:** Invocar `frontend-design` com: "Refinar os botões de avaliação, o convite em Hoje e o questionário do Prato Forte: marcar 👍/👎 com um momento que confirme a escolha, o questionário com entrada lateral entre as telas como no onboarding; sem mudar props, textos, roles, aria nem ids; só tokens do globals.css e transform/opacity; reduced-motion respeitado." Conferir com screenshot em 390 px.
- [ ] **Step 2:** `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm run build"` e o E2E com o banco semeado. Expected: tudo verde.
- [ ] **Step 3:** Commit `style(validacao): acabamento da avaliação, do convite e do questionário (D11)` (se houver mudança).
