# Plano 07B — Configurações e avisos: telas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ligar a tela de Configurações (`/perfil/configuracoes`) à API do Plano 07A — avisos com permissão do navegador e inscrição Web Push (RF26), escolha de medidas (RF30, só a preferência; a conversão nas telas é o Plano 07C), resumo real no Perfil, `sw.js` + manifesto do PWA, logout que tira a inscrição (CA07) — e apagar o que sobrava do protótipo (`usePlan`, `mock-api`, `mock-data`).

**Architecture:** `src/lib/push.ts` isola as APIs do navegador (`Notification`, `serviceWorker`, `PushManager`) atrás de funções pequenas e testáveis com stubs. `src/features/configuracoes`: tipos, chamadas (`lib/api/configuracoes.ts`), regra do resumo, hooks (salvar otimista com reversão; a unidade também atualiza o `['me']`), o componente `AvisosNoCelular` (três `Toggle` + mensagem de permissão/suporte) e o contêiner `ConfiguracoesTela`. `public/sw.js` mostra o aviso e abre a URL ao tocar; `src/app/manifest.ts` + `public/icone.svg` tornam o app instalável (iPhone só recebe aviso com o app na tela de início).

**Tech Stack:** Next 16.3.5 (App Router, `app/manifest.ts`), React 19.2, TanStack Query 5, Storybook 10.6, Vitest 4.1, MSW 2, Playwright 1.63.

**Spec:** `specs/06-configuracoes-notificacoes/spec.md` (§2 RF26, RF30, §3, §4 S19, §7 CA01, CA02, CA07, §8), `specs/00-fundacao/regras-de-negocio.md` (RN38, RN39), RNF-OPE-05. Contrato: Plano 07A (`GET/PUT /settings`, `POST/DELETE /push-subscriptions`).

**Onde rodar:** front em `/home/alvez/atividade-extensionista/frontend`, branch `plano-07b-configuracoes-telas` saindo de `plano-06b-evolucao-telas`. E2E com o backend na branch `plano-07a-configuracoes-api`.

## Decisões deste plano (rulings sobre a spec)

1. **"Este navegador já tem inscrição?"** é perguntado ao próprio navegador (`pushManager.getSubscription()`), não ao contador do servidor — o contador inclui outros aparelhos.
2. **Ligar qualquer aviso** pede a permissão (se preciso) antes de salvar; **desligar** só salva. Sem chave VAPID no servidor (`vapid_public_key: null`), os toggles ficam desabilitados com "Os avisos ainda não estão disponíveis neste servidor."
3. **Resumo no Perfil:** 0 ligados ⇒ "Avisos desligados"; só o lembrete ⇒ "Lembretes de refeição ligados"; senão "{n} avisos ligados" (1 ⇒ "1 aviso ligado").
4. **Ícone do PWA:** `public/icone.svg` (marca do Nutri sobre gema), `sizes: "any"`; ícones PNG para o iPhone ficam para o Plano 09 (deploy) — registrado.
5. **Versão no rodapé:** "Versão {NEXT_PUBLIC_APP_VERSION}"; sem a variável, a linha não aparece.
6. **Logout:** tira a inscrição do navegador e manda `DELETE /push-subscriptions` antes do `POST /logout`; falha nesse passo não impede sair.

## Global Constraints

- Textos exatos: "Configurações", "Avisos no celular", "Lembrete de refeição" / "15 minutos antes de cada horário", "Resumo da semana" / "Todo domingo à noite", "Dicas do Nutri" / "No máximo duas por semana", "Medidas", "Quilo e centímetro", "Libra e polegada", "Os avisos estão bloqueados no navegador. Libere nas configurações do celular para ligar.", "No iPhone, os avisos só funcionam com o Prato Forte na tela de início: toque em Compartilhar → Adicionar à Tela de Início.", "Este navegador não recebe avisos. Tente no Chrome do celular.", "Não foi possível carregar suas configurações", "O Prato Forte é um projeto de extensão do curso de Ciência da Computação da UNINTER, feito junto com a academia Zfit, em Capivari de Baixo."
- A permissão do navegador só é pedida ao **ligar** um aviso (CA01).
- `Toggle`: `role="switch"`, `aria-checked`, descrição ligada por `aria-describedby`; desabilitado durante a requisição e sem suporte.
- Salvar é otimista: o toggle muda na hora e volta com toast se o `PUT` falhar.
- Nenhum dado mockado nas telas; nada do protótipo sobra (`usePlan`, `mock-api`, `mock-data`).
- D11 com `frontend-design`.

## Review Focus

1. **Toque duplo rápido num toggle** → um pedido de permissão e um `PUT` com o valor final (toggle desabilitado enquanto salva) (Task 4).
2. **Permissão negada numa aba e concedida depois nas configurações do celular** → ao voltar e ligar, funciona (a checagem é na hora do toque, não no carregamento) (Task 4).
3. **Logout offline** → sai mesmo assim (Task 5).
4. **Unidade trocada** → o `['me']` passa a dizer `imperial` sem recarregar (para o Plano 07C) (Task 2).
5. **Servidor sem VAPID** → toggles desabilitados com a explicação, nada quebra (Task 4).

---

### Task 1: `push.ts` — suporte, permissão, inscrição e saída

**Files (repo front):**
- Create: `src/lib/push.ts`, `src/lib/push.test.ts`

**Interfaces:**
- Produces: `type SuportePush = 'ok' | 'sem-suporte' | 'ios-sem-pwa'`; `suportePush(): SuportePush`; `inscricaoAtual(): Promise<PushSubscription | null>`; `inscrever(chaveVapid: string): Promise<InscricaoJson | 'negada'>` (`InscricaoJson = { endpoint: string; keys: { p256dh: string; auth: string }; contentEncoding: 'aes128gcm' }`); `cancelarInscricao(): Promise<string | null>` (endpoint cancelado); `chaveParaBytes(base64url): Uint8Array`.

- [ ] **Step 1: Branch e teste que deve falhar**

```bash
cd /home/alvez/atividade-extensionista/frontend
git switch plano-06b-evolucao-telas && git switch -c plano-07b-configuracoes-telas
```

`src/lib/push.test.ts`:
```ts
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cancelarInscricao, chaveParaBytes, inscrever, suportePush } from './push';

const inscricaoFalsa = {
  endpoint: 'https://fcm.googleapis.com/fcm/send/abc',
  toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/fcm/send/abc', keys: { p256dh: 'BPublica', auth: 'segredo' } }),
  unsubscribe: vi.fn(async () => true),
};

function navegador({ push = true, permissao = 'granted' as NotificationPermission, ios = false, instalado = false, atual = null as unknown } = {}) {
  const subscribe = vi.fn(async () => inscricaoFalsa);
  vi.stubGlobal('Notification', push ? { permission: 'default', requestPermission: vi.fn(async () => permissao) } : undefined);
  vi.stubGlobal('PushManager', push ? function PushManager() {} : undefined);
  Object.defineProperty(window.navigator, 'serviceWorker', {
    configurable: true,
    value: push ? { register: vi.fn(async () => ({})), ready: Promise.resolve({ pushManager: { subscribe, getSubscription: vi.fn(async () => atual) } }) } : undefined,
  });
  Object.defineProperty(window.navigator, 'userAgent', { configurable: true, value: ios ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)' : 'Mozilla/5.0 (Linux; Android 14)' });
  Object.defineProperty(window.navigator, 'standalone', { configurable: true, value: instalado });
  return { subscribe };
}

afterEach(() => {
  vi.unstubAllGlobals();
  vi.clearAllMocks();
});

describe('push', () => {
  it('detecta suporte, falta de suporte e iPhone fora da tela de início', () => {
    navegador();
    expect(suportePush()).toBe('ok');
    navegador({ push: false });
    expect(suportePush()).toBe('sem-suporte');
    navegador({ push: false, ios: true });
    expect(suportePush()).toBe('ios-sem-pwa');
    navegador({ ios: true, instalado: true });
    expect(suportePush()).toBe('ok');
  });

  it('com permissão, inscreve com a chave do servidor e devolve o JSON da inscrição (CA01)', async () => {
    const { subscribe } = navegador();

    expect(await inscrever('BAAA')).toEqual({ endpoint: 'https://fcm.googleapis.com/fcm/send/abc', keys: { p256dh: 'BPublica', auth: 'segredo' }, contentEncoding: 'aes128gcm' });
    expect(subscribe).toHaveBeenCalledWith({ userVisibleOnly: true, applicationServerKey: chaveParaBytes('BAAA') });
  });

  it('permissão negada não inscreve (CA02)', async () => {
    const { subscribe } = navegador({ permissao: 'denied' });

    expect(await inscrever('BAAA')).toBe('negada');
    expect(subscribe).not.toHaveBeenCalled();
  });

  it('cancelar devolve o endpoint e tira a inscrição do navegador; sem inscrição, null', async () => {
    navegador({ atual: inscricaoFalsa });
    expect(await cancelarInscricao()).toBe('https://fcm.googleapis.com/fcm/send/abc');
    expect(inscricaoFalsa.unsubscribe).toHaveBeenCalled();

    navegador({ atual: null });
    expect(await cancelarInscricao()).toBeNull();
    navegador({ push: false });
    expect(await cancelarInscricao()).toBeNull();
  });

  it('chave base64url vira bytes', () => {
    expect(Array.from(chaveParaBytes('AQID'))).toEqual([1, 2, 3]);
    expect(Array.from(chaveParaBytes('_-8'))).toEqual([255, 239]);
  });
});
```

Run: `docker compose run --rm web npx vitest run --project unit src/lib/push.test.ts`
Expected: FAIL — módulo inexistente.

- [ ] **Step 2: Implementar**

`src/lib/push.ts`:
```ts
/**
 * Web Push no navegador (spec 06 §3): suporte, permissão, inscrição e saída.
 * O `sw.js` (em `public/`) mostra o aviso e abre a tela certa ao tocar.
 */

export type SuportePush = 'ok' | 'sem-suporte' | 'ios-sem-pwa';

export interface InscricaoJson {
  endpoint: string;
  keys: { p256dh: string; auth: string };
  contentEncoding: 'aes128gcm';
}

const temPush = () =>
  typeof window !== 'undefined' && 'serviceWorker' in navigator && Boolean(navigator.serviceWorker) && typeof window.PushManager !== 'undefined' && typeof window.Notification !== 'undefined';

const ehIphone = () => typeof navigator !== 'undefined' && /iPhone|iPad|iPod/.test(navigator.userAgent);
const instalado = () =>
  (navigator as Navigator & { standalone?: boolean }).standalone === true || window.matchMedia?.('(display-mode: standalone)').matches === true;

/** No iPhone o push só existe com o app na tela de início (RNF-OPE-05). */
export function suportePush(): SuportePush {
  if (ehIphone() && !instalado()) return 'ios-sem-pwa';
  return temPush() ? 'ok' : 'sem-suporte';
}

/** A chave VAPID pública (base64url) no formato que o `pushManager.subscribe` pede. */
export function chaveParaBytes(base64url: string): Uint8Array {
  const base64 = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
  return Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
}

async function registro(): Promise<ServiceWorkerRegistration> {
  await navigator.serviceWorker.register('/sw.js', { scope: '/' });
  return navigator.serviceWorker.ready;
}

export async function inscricaoAtual(): Promise<PushSubscription | null> {
  if (!temPush()) return null;
  return (await registro()).pushManager.getSubscription();
}

/** Pede a permissão (só aqui, CA01) e inscreve este navegador. */
export async function inscrever(chaveVapid: string): Promise<InscricaoJson | 'negada'> {
  const permissao = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
  if (permissao !== 'granted') return 'negada';
  const inscricao = await (await registro()).pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: chaveParaBytes(chaveVapid) });
  const json = inscricao.toJSON() as { endpoint: string; keys: { p256dh: string; auth: string } };
  return { endpoint: json.endpoint, keys: { p256dh: json.keys.p256dh, auth: json.keys.auth }, contentEncoding: 'aes128gcm' };
}

/** Logout (CA07): tira a inscrição deste navegador e devolve o endpoint para o servidor apagar. */
export async function cancelarInscricao(): Promise<string | null> {
  const atual = await inscricaoAtual();
  if (!atual) return null;
  await atual.unsubscribe();
  return atual.endpoint;
}
```

- [ ] **Step 3: Rodar, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project unit src/lib/push.test.ts && npm run lint && npm run typecheck"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(configuracoes): suporte, permissão e inscrição Web Push no navegador

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Tipos, chamadas, regra do resumo, hooks e MSW

**Files (repo front):**
- Create: `src/features/configuracoes/{tipos,regras,hooks}.ts`, `src/lib/api/configuracoes.ts`, `src/mocks/fixtures/configuracoes.ts`, `src/mocks/handlers/configuracoes.ts`
- Modify: `src/lib/chaves.ts`, `src/mocks/handlers/index.ts`
- Test: `src/features/configuracoes/regras.test.ts`, `src/features/configuracoes/hooks.integration.test.tsx`

**Interfaces:**
- Consumes: `api`, `CHAVE_ME` (`features/auth/hooks`), `User`.
- Produces:
  - Tipos: `Avisos` (`{ mealReminders: boolean; weeklySummary: boolean; tips: boolean }`), `Configuracoes` (`{ unitSystem: 'metric' | 'imperial'; notifications: Avisos; push: { vapidPublicKey: string | null; subscriptions: number } }`), `MudancaConfiguracoes` (`{ unitSystem?; notifications?: Partial<Avisos> }`).
  - API: `getConfiguracoes()`, `salvarConfiguracoes(mudanca)`, `enviarInscricao(inscricao: InscricaoJson)`, `removerInscricao(endpoint)`.
  - Regra: `resumoDosAvisos(avisos: Avisos): string`.
  - Hooks: `useConfiguracoes()`, `useSalvarConfiguracoes()` (otimista; reverte e avisa no erro; grava `unitSystem` no `['me']`).
  - `CHAVES.configuracoes`.
  - MSW: `configuracoesApi(parcial?)`; handlers padrão `GET/PUT /settings`, `POST/DELETE /push-subscriptions`.

- [ ] **Step 1: Testes que devem falhar**

`src/features/configuracoes/regras.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { resumoDosAvisos } from './regras';

describe('resumoDosAvisos (Perfil, S17)', () => {
  it.each([
    [{ mealReminders: false, weeklySummary: false, tips: false }, 'Avisos desligados'],
    [{ mealReminders: true, weeklySummary: false, tips: false }, 'Lembretes de refeição ligados'],
    [{ mealReminders: false, weeklySummary: true, tips: false }, '1 aviso ligado'],
    [{ mealReminders: true, weeklySummary: true, tips: false }, '2 avisos ligados'],
    [{ mealReminders: true, weeklySummary: true, tips: true }, '3 avisos ligados'],
  ])('%o → %s', (avisos, texto) => expect(resumoDosAvisos(avisos)).toBe(texto));
});
```

`src/features/configuracoes/hooks.integration.test.tsx`:
```tsx
import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClientProvider } from '@tanstack/react-query';
import { delay, http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { Toaster } from '@/components/ui/Toaster';
import { CHAVE_ME } from '@/features/auth/hooks';
import { CHAVES } from '@/lib/chaves';
import { url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { novoClienteDeTeste } from '@/test/renderizar';
import { useConfiguracoes, useSalvarConfiguracoes } from './hooks';
import type { Configuracoes } from './tipos';

function comCliente() {
  const cliente = novoClienteDeTeste();
  const wrapper = ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={cliente}>
      <Toaster>{children}</Toaster>
    </QueryClientProvider>
  );
  return { cliente, wrapper };
}

describe('hooks de Configurações', () => {
  it('muda na hora e volta se o PUT falhar', async () => {
    server.use(
      http.put(url('/settings'), async () => {
        await delay(80);
        return HttpResponse.json({ message: 'x', code: 'SERVER_ERROR' }, { status: 500 });
      }),
    );
    const { cliente, wrapper } = comCliente();
    const { result } = renderHook(() => ({ dados: useConfiguracoes(), salvar: useSalvarConfiguracoes() }), { wrapper });
    await waitFor(() => expect(result.current.dados.data).toBeDefined());

    act(() => result.current.salvar.mutate({ notifications: { tips: true } }));
    await waitFor(() => expect(cliente.getQueryData<Configuracoes>(CHAVES.configuracoes)!.notifications.tips).toBe(true));
    await waitFor(() => expect(result.current.salvar.isError).toBe(true));
    expect(cliente.getQueryData<Configuracoes>(CHAVES.configuracoes)!.notifications.tips).toBe(false);
  });

  it('trocar a unidade também muda o ["me"] (para as telas do 07C)', async () => {
    const { cliente, wrapper } = comCliente();
    cliente.setQueryData(CHAVE_ME, { id: 1, name: 'Camila', settings: { unitSystem: 'metric' } });
    const { result } = renderHook(() => useSalvarConfiguracoes(), { wrapper });

    await act(() => result.current.mutateAsync({ unitSystem: 'imperial' }));

    expect(cliente.getQueryData<{ settings: { unitSystem: string } }>(CHAVE_ME)!.settings.unitSystem).toBe('imperial');
  });
});
```

Run: `docker compose run --rm web npx vitest run --project unit --project integration src/features/configuracoes`
Expected: FAIL — módulos inexistentes.

- [ ] **Step 2: Implementar**

`src/features/configuracoes/tipos.ts`:
```ts
export interface Avisos {
  mealReminders: boolean;
  weeklySummary: boolean;
  tips: boolean;
}

export type SistemaDeMedidas = 'metric' | 'imperial';

/** `GET/PUT /settings` (spec 06 §5), em camelCase. */
export interface Configuracoes {
  unitSystem: SistemaDeMedidas;
  notifications: Avisos;
  push: { vapidPublicKey: string | null; subscriptions: number };
}

export interface MudancaConfiguracoes {
  unitSystem?: SistemaDeMedidas;
  notifications?: Partial<Avisos>;
}
```

`src/lib/api/configuracoes.ts`:
```ts
import type { Configuracoes, MudancaConfiguracoes } from '@/features/configuracoes/tipos';
import type { InscricaoJson } from '@/lib/push';
import { api } from './client';

type Dados<T> = { data: T };

export const getConfiguracoes = () => api<Dados<Configuracoes>>('/settings').then((r) => r.data);

/** PUT /settings — só o que mudou. */
export const salvarConfiguracoes = (mudanca: MudancaConfiguracoes) =>
  api<Dados<Configuracoes>>('/settings', { method: 'PUT', body: mudanca }).then((r) => r.data);

/** POST /push-subscriptions — upsert por endpoint. */
export const enviarInscricao = (inscricao: InscricaoJson) => api<void>('/push-subscriptions', { method: 'POST', body: inscricao });

/** DELETE /push-subscriptions — idempotente (CA07). */
export const removerInscricao = (endpoint: string) => api<void>('/push-subscriptions', { method: 'DELETE', body: { endpoint } });
```

`src/lib/chaves.ts` — acrescentar `configuracoes: ['configuracoes'],`.

`src/features/configuracoes/regras.ts`:
```ts
import type { Avisos } from './tipos';

/** O que o item "Notificações e conta" do Perfil diz (spec 06 §4). */
export function resumoDosAvisos(avisos: Avisos): string {
  const ligados = [avisos.mealReminders, avisos.weeklySummary, avisos.tips].filter(Boolean).length;
  if (ligados === 0) return 'Avisos desligados';
  if (ligados === 1 && avisos.mealReminders) return 'Lembretes de refeição ligados';
  return `${ligados} ${ligados === 1 ? 'aviso ligado' : 'avisos ligados'}`;
}
```

`src/features/configuracoes/hooks.ts`:
```ts
'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useToast } from '@/components/ui/Toaster';
import { CHAVE_ME } from '@/features/auth/hooks';
import * as configuracoes from '@/lib/api/configuracoes';
import { comoApiError } from '@/lib/api/errors';
import { CHAVES } from '@/lib/chaves';
import type { User } from '@/lib/types';
import type { Configuracoes, MudancaConfiguracoes } from './tipos';

export const useConfiguracoes = () => useQuery({ queryKey: CHAVES.configuracoes, queryFn: configuracoes.getConfiguracoes });

const aplicar = (atual: Configuracoes, mudanca: MudancaConfiguracoes): Configuracoes => ({
  ...atual,
  unitSystem: mudanca.unitSystem ?? atual.unitSystem,
  notifications: { ...atual.notifications, ...mudanca.notifications },
});

/** Otimista: muda na hora; se o servidor recusar, volta e avisa. A unidade também vai para o `['me']`. */
export function useSalvarConfiguracoes() {
  const cliente = useQueryClient();
  const avisar = useToast();
  return useMutation({
    mutationFn: configuracoes.salvarConfiguracoes,
    onMutate: async (mudanca: MudancaConfiguracoes) => {
      await cliente.cancelQueries({ queryKey: CHAVES.configuracoes });
      const antes = cliente.getQueryData<Configuracoes>(CHAVES.configuracoes);
      if (antes) cliente.setQueryData(CHAVES.configuracoes, aplicar(antes, mudanca));
      return { antes };
    },
    onError: (erro, _mudanca, contexto) => {
      if (contexto?.antes) cliente.setQueryData(CHAVES.configuracoes, contexto.antes);
      avisar({ texto: comoApiError(erro).message });
    },
    onSuccess: (salvas) => {
      cliente.setQueryData(CHAVES.configuracoes, salvas);
      cliente.setQueryData<User>(CHAVE_ME, (eu) => (eu ? { ...eu, settings: { ...eu.settings, unitSystem: salvas.unitSystem } } : eu));
    },
  });
}
```

`src/mocks/fixtures/configuracoes.ts`:
```ts
/** `GET /settings` como a API devolve (snake_case): o padrão do RN38. */
export function configuracoesApi(parcial: { unit_system?: string; meal_reminders?: boolean; weekly_summary?: boolean; tips?: boolean; vapid?: string | null; inscricoes?: number } = {}) {
  return {
    unit_system: parcial.unit_system ?? 'metric',
    notifications: {
      meal_reminders: parcial.meal_reminders ?? true,
      weekly_summary: parcial.weekly_summary ?? true,
      tips: parcial.tips ?? false,
    },
    push: { vapid_public_key: parcial.vapid === undefined ? 'BChaveDeTeste' : parcial.vapid, subscriptions: parcial.inscricoes ?? 0 },
  };
}
```

`src/mocks/handlers/configuracoes.ts`:
```ts
import { http, HttpResponse } from 'msw';
import { configuracoesApi } from '../fixtures/configuracoes';
import { url } from './auth';

/** Padrão: configurações do RN38; o PUT devolve o que recebeu aplicado ao padrão. */
export const handlersConfiguracoes = [
  http.get(url('/settings'), () => HttpResponse.json({ data: configuracoesApi() })),
  http.put(url('/settings'), async ({ request }) => {
    const corpo = (await request.json()) as { unit_system?: string; notifications?: Record<string, boolean> };
    return HttpResponse.json({ data: configuracoesApi({ unit_system: corpo.unit_system, ...corpo.notifications }) });
  }),
  http.post(url('/push-subscriptions'), () => HttpResponse.json({ data: { subscribed: true } }, { status: 201 })),
  http.delete(url('/push-subscriptions'), () => new HttpResponse(null, { status: 204 })),
];
```

`src/mocks/handlers/index.ts` — acrescentar `handlersConfiguracoes`.

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project unit --project integration src/features/configuracoes && npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(configuracoes): tipos, chamadas, resumo, hooks e MSW de Configurações

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `Toggle` acessível e `AvisosNoCelular`

**Files (repo front):**
- Modify: `src/components/ui/Toggle.tsx`
- Create: `src/components/ui/Toggle.stories.tsx`, `src/features/configuracoes/components/AvisosNoCelular.tsx`, `src/features/configuracoes/components/AvisosNoCelular.stories.tsx`

**Interfaces:**
- Produces: `Toggle({ ligado, onChange, rotulo, descricao?, desabilitado? })` (descrição em `aria-describedby`; desabilitado ⇒ `disabled`); `AvisosNoCelular({ avisos, estado, salvando, aoMudar })` com `estado: 'ok' | 'negada' | 'sem-suporte' | 'ios-sem-pwa' | 'sem-servidor'` e `aoMudar(chave: keyof Avisos, valor: boolean)`.

- [ ] **Step 1: Stories que devem falhar**

`src/components/ui/Toggle.stories.tsx`:
```tsx
import { useState } from 'react';
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { Toggle } from './Toggle';

const meta = {
  title: 'UI/Toggle',
  component: Toggle,
  args: { ligado: false, onChange: fn(), rotulo: 'Lembrete de refeição', descricao: '15 minutos antes de cada horário' },
  render: function Controlado(args) {
    const [ligado, setLigado] = useState(args.ligado);
    return <Toggle {...args} ligado={ligado} onChange={(v) => { setLigado(v); args.onChange(v); }} />;
  },
} satisfies Meta<typeof Toggle>;
export default meta;
type Story = StoryObj<typeof meta>;

export const Desligado: Story = {
  play: async ({ canvasElement, args }) => {
    const chave = within(canvasElement).getByRole('switch', { name: 'Lembrete de refeição' });
    await expect(chave).toHaveAttribute('aria-checked', 'false');
    await expect(chave).toHaveAccessibleDescription('15 minutos antes de cada horário');
    await userEvent.click(chave);
    await expect(args.onChange).toHaveBeenCalledWith(true);
    await expect(chave).toHaveAttribute('aria-checked', 'true');
  },
};

export const Ligado: Story = { args: { ligado: true } };

export const Desabilitado: Story = {
  args: { desabilitado: true },
  play: async ({ canvasElement, args }) => {
    const chave = within(canvasElement).getByRole('switch');
    await expect(chave).toBeDisabled();
    await userEvent.click(chave);
    await expect(args.onChange).not.toHaveBeenCalled();
  },
};
```

`src/features/configuracoes/components/AvisosNoCelular.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { AvisosNoCelular } from './AvisosNoCelular';

const meta = {
  title: 'Configurações/AvisosNoCelular',
  component: AvisosNoCelular,
  args: { avisos: { mealReminders: true, weeklySummary: true, tips: false }, estado: 'ok', salvando: false, aoMudar: fn() },
} satisfies Meta<typeof AvisosNoCelular>;
export default meta;
type Story = StoryObj<typeof meta>;

export const PermissaoPadrao: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await expect(tela.getAllByRole('switch')).toHaveLength(3);
    await userEvent.click(tela.getByRole('switch', { name: 'Dicas do Nutri' }));
    await expect(args.aoMudar).toHaveBeenCalledWith('tips', true);
  },
};

export const PermissaoNegada: Story = {
  args: { estado: 'negada', avisos: { mealReminders: false, weeklySummary: false, tips: false } },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('Os avisos estão bloqueados no navegador. Libere nas configurações do celular para ligar.')).toBeInTheDocument();
  },
};

export const SemSuporte: Story = {
  args: { estado: 'sem-suporte' },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByText('Este navegador não recebe avisos. Tente no Chrome do celular.')).toBeInTheDocument();
    for (const chave of tela.getAllByRole('switch')) await expect(chave).toBeDisabled();
  },
};

export const IosSemPwa: Story = {
  args: { estado: 'ios-sem-pwa' },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('No iPhone, os avisos só funcionam com o Prato Forte na tela de início: toque em Compartilhar → Adicionar à Tela de Início.')).toBeInTheDocument();
  },
};

export const Salvando: Story = {
  args: { salvando: true },
  play: async ({ canvasElement }) => {
    for (const chave of within(canvasElement).getAllByRole('switch')) await expect(chave).toBeDisabled();
  },
};
```

Run: `docker compose run --rm web npx vitest run --project storybook src/components/ui/Toggle.stories.tsx src/features/configuracoes`
Expected: FAIL — `desabilitado`/descrição e `AvisosNoCelular` inexistentes.

- [ ] **Step 2: Implementar**

`src/components/ui/Toggle.tsx` (substituir inteiro):
```tsx
"use client";

import { useId } from "react";

/** Interruptor (`role="switch"`), com a descrição lida junto do rótulo. */
export function Toggle({
  ligado,
  onChange,
  rotulo,
  descricao,
  desabilitado = false,
}: {
  ligado: boolean;
  onChange: (v: boolean) => void;
  rotulo: string;
  descricao?: string;
  desabilitado?: boolean;
}) {
  const idDescricao = useId();
  return (
    <button
      type="button"
      role="switch"
      aria-checked={ligado}
      aria-describedby={descricao ? idDescricao : undefined}
      disabled={desabilitado}
      onClick={() => onChange(!ligado)}
      className="flex min-h-[62px] w-full items-center gap-3.5 py-3 text-left active:scale-100 disabled:cursor-not-allowed disabled:opacity-60"
    >
      <span className="flex-1">
        <span className="block text-[15px] font-semibold">{rotulo}</span>
        {descricao ? (
          <span id={idDescricao} className="mt-0.5 block text-[13px] text-fumo">
            {descricao}
          </span>
        ) : null}
      </span>
      <span className={`relative h-7 w-[46px] shrink-0 rounded-full transition-colors duration-300 ${ligado ? "bg-mata" : "bg-[#cfd6cc]"}`}>
        <span
          className={`absolute top-[3px] left-[3px] size-[22px] rounded-full bg-white shadow-[0_1px_3px_rgba(21,37,28,.25)] transition-[transform,width] duration-300 ease-[cubic-bezier(.34,1.56,.64,1)] ${
            ligado ? "translate-x-[18px]" : ""
          }`}
        />
      </span>
    </button>
  );
}
```
(O nome acessível do `switch` vem do texto interno — rótulo + descrição; se o `getByRole('switch', { name: 'Lembrete de refeição' })` não bater por incluir a descrição, ponha `aria-label={rotulo}` e registre a ruling.)

`src/features/configuracoes/components/AvisosNoCelular.tsx`:
```tsx
import { Toggle } from "@/components/ui/Toggle";
import type { Avisos } from "../tipos";

export type EstadoDosAvisos = "ok" | "negada" | "sem-suporte" | "ios-sem-pwa" | "sem-servidor";

const MENSAGEM: Record<Exclude<EstadoDosAvisos, "ok">, string> = {
  negada: "Os avisos estão bloqueados no navegador. Libere nas configurações do celular para ligar.",
  "sem-suporte": "Este navegador não recebe avisos. Tente no Chrome do celular.",
  "ios-sem-pwa": "No iPhone, os avisos só funcionam com o Prato Forte na tela de início: toque em Compartilhar → Adicionar à Tela de Início.",
  "sem-servidor": "Os avisos ainda não estão disponíveis neste servidor.",
};

const ITENS: { chave: keyof Avisos; rotulo: string; descricao: string }[] = [
  { chave: "mealReminders", rotulo: "Lembrete de refeição", descricao: "15 minutos antes de cada horário" },
  { chave: "weeklySummary", rotulo: "Resumo da semana", descricao: "Todo domingo à noite" },
  { chave: "tips", rotulo: "Dicas do Nutri", descricao: "No máximo duas por semana" },
];

/** "Avisos no celular" (S19, RF26). Sem suporte ou sem servidor, os interruptores ficam travados. */
export function AvisosNoCelular({
  avisos,
  estado,
  salvando,
  aoMudar,
}: {
  avisos: Avisos;
  estado: EstadoDosAvisos;
  salvando: boolean;
  aoMudar: (chave: keyof Avisos, valor: boolean) => void;
}) {
  const travado = salvando || estado === "sem-suporte" || estado === "ios-sem-pwa" || estado === "sem-servidor";
  return (
    <section>
      <h2 className="mt-[22px] text-[12.5px] font-semibold text-fumo">Avisos no celular</h2>
      {estado !== "ok" ? (
        <p role="status" className="mt-2.5 animate-entra rounded-2xl bg-gema-fraca px-4 py-3 text-[13px] leading-snug text-gema-texto">
          {MENSAGEM[estado]}
        </p>
      ) : null}
      <div className="mt-2.5 rounded-[20px] bg-white px-[18px]">
        {ITENS.map((item, i) => (
          <div key={item.chave} className={i < ITENS.length - 1 ? "border-b border-fio" : ""}>
            <Toggle
              ligado={avisos[item.chave]}
              onChange={(v) => aoMudar(item.chave, v)}
              rotulo={item.rotulo}
              descricao={item.descricao}
              desabilitado={travado}
            />
          </div>
        ))}
      </div>
    </section>
  );
}
```

- [ ] **Step 3: Rodar, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project storybook src/components/ui/Toggle.stories.tsx src/features/configuracoes && npm run lint && npm run typecheck"`
Expected: PASS e OK.

```bash
git add -A && git commit -m "feat(configuracoes): Toggle acessível e bloco de avisos no celular

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Tela de Configurações (`/perfil/configuracoes`)

**Files (repo front):**
- Create: `src/features/configuracoes/components/ConfiguracoesTela.tsx`, `src/features/configuracoes/components/ConfiguracoesTela.integration.test.tsx`
- Modify: `src/app/(app)/perfil/configuracoes/page.tsx` (vira só o contêiner)

**Interfaces:**
- Consumes: Tasks 1–3; `usePerfil`; `Segmento`; `ContaSection`; `ErrorState`, `Skeleton`, `Screen`, `TopBar`, `useToast`.
- Produces: `ConfiguracoesTela` — S19 inteira.

- [ ] **Step 1: Teste que deve falhar**

`src/features/configuracoes/components/ConfiguracoesTela.integration.test.tsx`:
```tsx
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as push from '@/lib/push';
import { configuracoesApi } from '@/mocks/fixtures/configuracoes';
import { erroDaApi, url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { redefinirNavegacao } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { ConfiguracoesTela } from './ConfiguracoesTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));
vi.mock('@/lib/push', () => ({
  suportePush: vi.fn(() => 'ok'),
  inscricaoAtual: vi.fn(async () => null),
  inscrever: vi.fn(async () => ({ endpoint: 'https://fcm.googleapis.com/fcm/send/abc', keys: { p256dh: 'BPublica', auth: 'segredo' }, contentEncoding: 'aes128gcm' })),
  cancelarInscricao: vi.fn(async () => null),
}));

const corpos: { put: unknown[]; post: unknown[] } = { put: [], post: [] };

beforeEach(() => {
  redefinirNavegacao();
  corpos.put = [];
  corpos.post = [];
  vi.mocked(push.suportePush).mockReturnValue('ok');
  vi.mocked(push.inscricaoAtual).mockResolvedValue(null);
  server.use(
    http.put(url('/settings'), async ({ request }) => {
      const corpo = (await request.json()) as { unit_system?: string; notifications?: Record<string, boolean> };
      corpos.put.push(corpo);
      return HttpResponse.json({ data: configuracoesApi({ unit_system: corpo.unit_system, ...corpo.notifications }) });
    }),
    http.post(url('/push-subscriptions'), async ({ request }) => {
      corpos.post.push(await request.json());
      return HttpResponse.json({ data: { subscribed: true } }, { status: 201 });
    }),
  );
});

describe('Configurações (S19)', () => {
  it('ligar um aviso pela primeira vez pede permissão, inscreve e salva (CA01)', async () => {
    const usuario = userEvent.setup();
    renderizar(<ConfiguracoesTela />);

    await usuario.click(await screen.findByRole('switch', { name: 'Dicas do Nutri' }));

    await waitFor(() => expect(corpos.put).toEqual([{ notifications: { tips: true } }]));
    expect(push.inscrever).toHaveBeenCalledWith('BChaveDeTeste');
    expect(corpos.post).toEqual([{ endpoint: 'https://fcm.googleapis.com/fcm/send/abc', keys: { p256dh: 'BPublica', auth: 'segredo' }, content_encoding: 'aes128gcm' }]);
    expect(screen.getByRole('switch', { name: 'Dicas do Nutri' })).toHaveAttribute('aria-checked', 'true');
  });

  it('com inscrição neste navegador, só salva', async () => {
    vi.mocked(push.inscricaoAtual).mockResolvedValue({ endpoint: 'x' } as PushSubscription);
    const usuario = userEvent.setup();
    renderizar(<ConfiguracoesTela />);

    await usuario.click(await screen.findByRole('switch', { name: 'Dicas do Nutri' }));

    await waitFor(() => expect(corpos.put).toHaveLength(1));
    expect(push.inscrever).not.toHaveBeenCalled();
  });

  it('permissão negada: volta a desligado e explica (CA02)', async () => {
    vi.mocked(push.inscrever).mockResolvedValueOnce('negada');
    const usuario = userEvent.setup();
    renderizar(<ConfiguracoesTela />);

    await usuario.click(await screen.findByRole('switch', { name: 'Dicas do Nutri' }));

    expect(await screen.findByText('Os avisos estão bloqueados no navegador. Libere nas configurações do celular para ligar.')).toBeInTheDocument();
    expect(screen.getByRole('switch', { name: 'Dicas do Nutri' })).toHaveAttribute('aria-checked', 'false');
    expect(corpos.put).toEqual([]);
  });

  it('desligar não pede permissão', async () => {
    const usuario = userEvent.setup();
    renderizar(<ConfiguracoesTela />);

    await usuario.click(await screen.findByRole('switch', { name: 'Lembrete de refeição' }));

    await waitFor(() => expect(corpos.put).toEqual([{ notifications: { meal_reminders: false } }]));
    expect(push.inscrever).not.toHaveBeenCalled();
  });

  it('erro no PUT: o toggle volta e avisa', async () => {
    vi.mocked(push.inscricaoAtual).mockResolvedValue({ endpoint: 'x' } as PushSubscription);
    server.use(http.put(url('/settings'), () => erroDaApi(500, 'SERVER_ERROR', 'Algo deu errado do nosso lado. Tente de novo.')));
    const usuario = userEvent.setup();
    renderizar(<ConfiguracoesTela />);

    await usuario.click(await screen.findByRole('switch', { name: 'Dicas do Nutri' }));

    expect(await screen.findByText('Algo deu errado do nosso lado. Tente de novo.')).toBeInTheDocument();
    expect(screen.getByRole('switch', { name: 'Dicas do Nutri' })).toHaveAttribute('aria-checked', 'false');
  });

  it('sem suporte ou servidor sem VAPID: interruptores travados com a explicação', async () => {
    vi.mocked(push.suportePush).mockReturnValue('sem-suporte');
    const { unmount } = renderizar(<ConfiguracoesTela />);
    expect(await screen.findByText('Este navegador não recebe avisos. Tente no Chrome do celular.')).toBeInTheDocument();
    unmount();

    vi.mocked(push.suportePush).mockReturnValue('ok');
    server.use(http.get(url('/settings'), () => HttpResponse.json({ data: configuracoesApi({ vapid: null }) })));
    renderizar(<ConfiguracoesTela />);
    expect(await screen.findByText('Os avisos ainda não estão disponíveis neste servidor.')).toBeInTheDocument();
    expect(screen.getByRole('switch', { name: 'Dicas do Nutri' })).toBeDisabled();
  });

  it('medidas: escolher "Libra e polegada" salva imperial', async () => {
    const usuario = userEvent.setup();
    renderizar(<ConfiguracoesTela />);

    await usuario.click(within(await screen.findByRole('radiogroup', { name: 'Medidas' })).getByRole('radio', { name: 'Libra e polegada' }));

    await waitFor(() => expect(corpos.put).toEqual([{ unit_system: 'imperial' }]));
  });

  it('rodapé do projeto e erro ao carregar', async () => {
    renderizar(<ConfiguracoesTela />);
    expect(await screen.findByText(/^O Prato Forte é um projeto de extensão do curso de Ciência da Computação da UNINTER/)).toBeInTheDocument();
  });

  it('erro ao carregar: ErrorState', async () => {
    server.use(http.get(url('/settings'), () => erroDaApi(500, 'SERVER_ERROR', 'x')));
    renderizar(<ConfiguracoesTela />);

    expect(await screen.findByText('Não foi possível carregar suas configurações')).toBeInTheDocument();
  });
});
```

Run: `docker compose run --rm web npx vitest run --project integration src/features/configuracoes/components/ConfiguracoesTela.integration.test.tsx`
Expected: FAIL — `ConfiguracoesTela` não existe.

- [ ] **Step 2: Implementar**

`src/features/configuracoes/components/ConfiguracoesTela.tsx`:
```tsx
"use client";

import { useState } from "react";
import { ErrorState } from "@/components/app/ErrorState";
import { Screen } from "@/components/app/Screen";
import { TopBar } from "@/components/app/TopBar";
import { Segmento } from "@/components/ui/Field";
import { Skeleton } from "@/components/ui/Skeleton";
import { useToast } from "@/components/ui/Toaster";
import { ContaSection } from "@/features/auth/components/ContaSection";
import { usePerfil } from "@/features/perfil/hooks";
import { enviarInscricao } from "@/lib/api/configuracoes";
import { comoApiError } from "@/lib/api/errors";
import { inscrever, inscricaoAtual, suportePush } from "@/lib/push";
import { useConfiguracoes, useSalvarConfiguracoes } from "../hooks";
import type { Avisos, SistemaDeMedidas } from "../tipos";
import { AvisosNoCelular, type EstadoDosAvisos } from "./AvisosNoCelular";

/** S19 — avisos no celular, medidas e conta (RF26, RF30). */
export function ConfiguracoesTela() {
  const avisar = useToast();
  const configuracoes = useConfiguracoes();
  const salvar = useSalvarConfiguracoes();
  const perfil = usePerfil();
  const [negada, setNegada] = useState(false);
  const [inscrevendo, setInscrevendo] = useState(false);
  const dados = configuracoes.data;
  const versao = process.env.NEXT_PUBLIC_APP_VERSION;

  const suporte = suportePush();
  const estado: EstadoDosAvisos =
    suporte !== "ok" ? suporte : !dados?.push.vapidPublicKey ? "sem-servidor" : negada ? "negada" : "ok";

  async function mudarAviso(chave: keyof Avisos, valor: boolean) {
    if (!dados) return;
    if (valor && dados.push.vapidPublicKey) {
      setInscrevendo(true);
      try {
        // A permissão é pedida só aqui, ao ligar (CA01); a inscrição é a deste navegador.
        if (!(await inscricaoAtual())) {
          const inscricao = await inscrever(dados.push.vapidPublicKey);
          if (inscricao === "negada") {
            setNegada(true);
            return;
          }
          await enviarInscricao(inscricao);
        }
        setNegada(false);
      } catch (erro) {
        avisar({ texto: comoApiError(erro).message });
        return;
      } finally {
        setInscrevendo(false);
      }
    }
    salvar.mutate({ notifications: { [chave]: valor } });
  }

  return (
    <Screen>
      <TopBar voltarPara="/perfil" rotuloVoltar="Voltar para o perfil" />

      <main className="flex-1 px-5 pt-1.5">
        <h1 className="animate-entra font-display text-[28px] font-bold tracking-[-0.028em]">Configurações</h1>

        {configuracoes.isError ? (
          <ErrorState
            titulo="Não foi possível carregar suas configurações"
            descricao="Suas escolhas estão salvas. Só a conexão falhou agora."
            aoTentarDeNovo={() => void configuracoes.refetch()}
          />
        ) : !dados ? (
          <div role="status" aria-label="Carregando suas configurações">
            <Skeleton className="mt-6 h-[200px] rounded-3xl" />
            <Skeleton className="mt-4 h-12 rounded-xl" />
          </div>
        ) : (
          <>
            <AvisosNoCelular avisos={dados.notifications} estado={estado} salvando={salvar.isPending || inscrevendo} aoMudar={(c, v) => void mudarAviso(c, v)} />
            <div className="mt-[22px]">
              <Segmento
                label="Medidas"
                valor={dados.unitSystem}
                onChange={(v: SistemaDeMedidas) => salvar.mutate({ unitSystem: v })}
                opcoes={[
                  { valor: "metric", rotulo: "Quilo e centímetro" },
                  { valor: "imperial", rotulo: "Libra e polegada" },
                ]}
              />
            </div>
          </>
        )}

        <ContaSection />

        <div className="mt-[22px] border-t border-linha pt-[18px]">
          <p className="text-[12.5px] leading-relaxed text-fumo">
            O Prato Forte é um projeto de extensão do curso de Ciência da Computação da UNINTER, feito junto com a academia{" "}
            {perfil.data?.gym ?? "Zfit"}, em {perfil.data?.city ?? "Capivari de Baixo"}.
          </p>
          {versao ? <p className="mt-2 text-[12.5px] text-musgo">Versão {versao}</p> : null}
        </div>
      </main>

      <div className="h-8 shrink-0 area-segura-baixo" />
    </Screen>
  );
}
```
(O `snakear` do cliente transforma `mealReminders` em `meal_reminders` no corpo; o teste espera snake_case. `suportePush()` roda no render: no servidor devolve `sem-suporte`; se aparecer aviso de hidratação, leia o suporte num `useSyncExternalStore` com snapshot de servidor `'ok'` e registre a ruling.)

`src/app/(app)/perfil/configuracoes/page.tsx` (substituir inteiro):
```tsx
import { ConfiguracoesTela } from "@/features/configuracoes/components/ConfiguracoesTela";

export default function Configuracoes() {
  return <ConfiguracoesTela />;
}
```

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project integration src/features/configuracoes && npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(configuracoes): tela de configurações ligada à API (RF26, RF30, CA01, CA02)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Resumo no Perfil e logout que tira a inscrição (CA07)

**Files (repo front):**
- Modify: `src/features/perfil/components/PerfilTela.tsx`, `src/features/perfil/components/PerfilTela.integration.test.tsx`, `src/features/auth/components/ContaSection.tsx`
- Test: `src/features/auth/components/ContaSection.integration.test.tsx` (criar se não existir; senão acrescentar)

**Interfaces:**
- Consumes: `useConfiguracoes`, `resumoDosAvisos` (Task 2); `cancelarInscricao` (Task 1); `removerInscricao`.

- [ ] **Step 1: Testes que devem falhar**

Em `src/features/perfil/components/PerfilTela.integration.test.tsx`, acrescentar:
```tsx
it('"Notificações e conta" mostra o resumo real dos avisos', async () => {
  renderizar(<PerfilTela />);

  expect(await screen.findByRole('link', { name: /Notificações e conta.*2 avisos ligados/s })).toHaveAttribute('href', '/perfil/configuracoes');
});
```
(imports que faltarem: `screen` de `@testing-library/react`.)

`src/features/auth/components/ContaSection.integration.test.tsx`:
```tsx
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as navegar from '@/lib/navegar';
import * as push from '@/lib/push';
import { url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { renderizar } from '@/test/renderizar';
import { ContaSection } from './ContaSection';

vi.mock('@/lib/push', () => ({ cancelarInscricao: vi.fn(async () => 'https://fcm.googleapis.com/fcm/send/abc') }));
vi.mock('@/lib/navegar', () => ({ recarregarEm: vi.fn() }));

describe('Sair (CA07)', () => {
  let ordem: string[];
  beforeEach(() => {
    ordem = [];
    server.use(
      http.delete(url('/push-subscriptions'), async ({ request }) => {
        ordem.push(`delete ${((await request.json()) as { endpoint: string }).endpoint}`);
        return new HttpResponse(null, { status: 204 });
      }),
      http.post(url('/logout'), () => {
        ordem.push('logout');
        return new HttpResponse(null, { status: 204 });
      }),
    );
  });

  it('tira a inscrição deste navegador antes de sair', async () => {
    renderizar(<ContaSection />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Sair desta conta' }));

    await waitFor(() => expect(navegar.recarregarEm).toHaveBeenCalledWith('/'));
    expect(ordem).toEqual(['delete https://fcm.googleapis.com/fcm/send/abc', 'logout']);
  });

  it('se tirar a inscrição falhar (offline), sai mesmo assim', async () => {
    vi.mocked(push.cancelarInscricao).mockRejectedValueOnce(new Error('offline'));
    renderizar(<ContaSection />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Sair desta conta' }));

    await waitFor(() => expect(navegar.recarregarEm).toHaveBeenCalledWith('/'));
    expect(ordem).toEqual(['logout']);
  });
});
```

Run: `docker compose run --rm web npx vitest run --project integration src/features/perfil src/features/auth/components/ContaSection.integration.test.tsx`
Expected: FAIL — resumo fixo e logout sem tirar a inscrição.

- [ ] **Step 2: Implementar**

`src/features/perfil/components/PerfilTela.tsx` — importar `useConfiguracoes` e `resumoDosAvisos`; no `Conteudo`, `const configuracoes = useConfiguracoes();` e trocar o item:
```tsx
    {
      href: "/perfil/configuracoes",
      titulo: "Notificações e conta",
      valor: configuracoes.data ? resumoDosAvisos(configuracoes.data.notifications) : "Avisos, medidas e conta",
    },
```
(e apagar o comentário "Resumo das notificações ligadas: Plano 07".)

`src/features/auth/components/ContaSection.tsx` — importar `cancelarInscricao` de `@/lib/push` e `removerInscricao` de `@/lib/api/configuracoes`; no começo de `aoSair()`:
```tsx
    // CA07: este navegador para de receber avisos. Falhar aqui (offline) não impede sair.
    try {
      const endpoint = await cancelarInscricao();
      if (endpoint) await removerInscricao(endpoint);
    } catch {
      /* segue para o logout */
    }
```

- [ ] **Step 3: Rodar, suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project integration src/features/perfil src/features/auth && npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(configuracoes): resumo dos avisos no Perfil e logout que tira a inscrição (CA07)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: `sw.js`, manifesto e ícone do PWA

**Files (repo front):**
- Create: `public/sw.js`, `public/icone.svg`, `src/app/manifest.ts`, `src/app/manifest.test.ts`

**Interfaces:**
- Produces: service worker com `push` (mostra `title`/`body`/`tag`, guarda `data.url`) e `notificationclick` (foca uma aba aberta do app ou abre a URL); manifesto `standalone`.

- [ ] **Step 1: Teste que deve falhar**

`src/app/manifest.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import manifest from './manifest';

describe('manifesto do PWA', () => {
  it('instalável, em tela cheia e com as cores do app', () => {
    expect(manifest()).toMatchObject({
      name: 'Prato Forte',
      short_name: 'Prato Forte',
      start_url: '/hoje',
      display: 'standalone',
      background_color: '#eceee7',
      theme_color: '#eceee7',
      icons: [{ src: '/icone.svg', sizes: 'any', type: 'image/svg+xml' }],
    });
  });
});
```

Run: `docker compose run --rm web npx vitest run --project unit src/app/manifest.test.ts`
Expected: FAIL — módulo inexistente.

- [ ] **Step 2: Implementar**

`src/app/manifest.ts`:
```ts
import type { MetadataRoute } from 'next';

/** PWA: no iPhone os avisos só chegam com o app na tela de início (RNF-OPE-05). */
export default function manifest(): MetadataRoute.Manifest {
  return {
    name: 'Prato Forte',
    short_name: 'Prato Forte',
    description: 'Guia nutricional para quem treina na Zfit.',
    start_url: '/hoje',
    display: 'standalone',
    background_color: '#eceee7',
    theme_color: '#eceee7',
    lang: 'pt-BR',
    icons: [{ src: '/icone.svg', sizes: 'any', type: 'image/svg+xml' }],
  };
}
```

`public/icone.svg`:
```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
  <rect width="512" height="512" rx="112" fill="#15251c"/>
  <circle cx="256" cy="256" r="150" fill="#e8a93c"/>
  <circle cx="256" cy="256" r="52" fill="#15251c"/>
</svg>
```

`public/sw.js`:
```js
/* Service worker do Prato Forte: só avisos (spec 06). Sem cache offline. */

self.addEventListener('push', (evento) => {
  const dados = evento.data ? evento.data.json() : {};
  evento.waitUntil(
    self.registration.showNotification(dados.title || 'Prato Forte', {
      body: dados.body || '',
      tag: dados.tag,
      icon: '/icone.svg',
      badge: '/icone.svg',
      data: { url: (dados.data && dados.data.url) || '/hoje' },
    }),
  );
});

self.addEventListener('notificationclick', (evento) => {
  evento.notification.close();
  const url = new URL(evento.notification.data.url, self.location.origin).href;
  evento.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((abas) => {
      const aberta = abas.find((aba) => aba.url.startsWith(self.location.origin));
      if (aberta) return aberta.navigate(url).then((aba) => aba && aba.focus());
      return self.clients.openWindow(url);
    }),
  );
});
```

- [ ] **Step 3: Rodar, build e commit**

Run: `docker compose run --rm web bash -c "npx vitest run --project unit src/app/manifest.test.ts && npm run lint && npm run typecheck && npm run build"`
Expected: PASS; o build lista `/manifest.webmanifest`.

```bash
git add -A && git commit -m "feat(configuracoes): service worker dos avisos, manifesto e ícone do PWA

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Tirar o resto do protótipo

**Files (repo front):**
- Delete: `src/lib/plan-store.tsx`, `src/lib/mock-api.ts`, `src/mocks/fixtures/mock-data.ts`
- Modify: `src/app/(app)/layout.tsx` (sem `PlanProvider`), `src/lib/types.ts` (sem `Profile`/`Restriction` se ficarem sem uso), `eslint.config.mjs` (blocos LEGADO)

- [ ] **Step 1: Conferir que nada mais usa**

```bash
cd /home/alvez/atividade-extensionista/frontend
grep -rn "plan-store\|mock-api\|mock-data\|usePlan\|PlanProvider\|mockProfile" src e2e --include=*.ts --include=*.tsx | grep -v "^src/lib/plan-store.tsx\|^src/lib/mock-api.ts\|^src/mocks/fixtures/mock-data.ts"
```
Expected: só `src/app/(app)/layout.tsx`.

- [ ] **Step 2: Apagar e conferir**

- `git rm src/lib/plan-store.tsx src/lib/mock-api.ts src/mocks/fixtures/mock-data.ts`
- `src/app/(app)/layout.tsx`: tirar o import e o `<PlanProvider>` (os filhos vão direto dentro do `AuthGate`).
- `src/lib/types.ts`: apagar `Profile` e `Restriction` se o `grep -rnw "Profile\|Restriction" src` não achar outro uso.
- `eslint.config.mjs`: o primeiro bloco LEGADO (`files: ['src/lib/mock-api.ts']`) sai inteiro; do segundo, sai `'src/lib/plan-store.tsx'`. A regra `no-restricted-imports` de `mock-data` continua (protege `src/mocks`).

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm run build"`
Expected: tudo verde.

- [ ] **Step 3: E2E e commit**

Com o backend do 07A no ar e semeado (`cd ../backend && git switch plano-07a-configuracoes-api && docker compose up -d && docker compose exec api php artisan migrate:fresh --seeder=E2ESeeder --force`):

Run: `docker compose run --rm web npm run e2e`
Expected: todos verdes (nenhum E2E novo: push real não é automatizável — spec 06 §8).

```bash
git add -A && git commit -m "refactor(configuracoes): tira o protótipo que sobrava (usePlan, mock-api, mock-data)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Acabamento visual (D11) com `frontend-design`

**Files (repo front):**
- Modify: `src/features/configuracoes/components/*.tsx`, `src/components/ui/Toggle.tsx` (só visual)

- [ ] **Step 1: Refinar com o skill `frontend-design`**

Invocar `frontend-design` com: "Refinar a tela de Configurações do Prato Forte: o interruptor ligado responde com um momento de movimento que confirma o que mudou; a mensagem de permissão/suporte entra sem empurrar a tela aos trancos; sem mudar props, textos, roles, aria nem ids; só tokens do globals.css e animações transform/opacity; reduced-motion respeitado." Conferir com screenshot em 390 px.

- [ ] **Step 2: Suíte, build e E2E**

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm run build"` e `docker compose run --rm web npm run e2e` com o banco recém-semeado.
Expected: tudo verde.

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "style(configuracoes): acabamento da tela de configurações (D11)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
