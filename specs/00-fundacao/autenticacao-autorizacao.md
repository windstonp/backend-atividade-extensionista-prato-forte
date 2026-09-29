# Autenticação e autorização

## 1. Decisão: Laravel Sanctum em modo SPA (cookie de sessão) ✅ D7

| | Node (referência) 🟤 | Laravel (proposto) |
|---|---|---|
| Mecanismo | JWT de acesso (15 min) + refresh token (30 dias) em tabela | Sessão de servidor (driver `database`) via cookie `HttpOnly`, `Secure`, `SameSite=Lax` |
| Segredos | fixos no código (`config/auth.ts`) | `APP_KEY` no ambiente |
| Onde fica no cliente | corpo/header/query (`x-access-token`, `?token=`) | cookie gerenciado pelo navegador; o JS nunca vê a credencial |
| Logout | inexistente | invalida a sessão no servidor |
| Problemas | middleware valida o token de acesso com o segredo do refresh; controllers decodificam o token por conta própria | — |

**Por quê**: o frontend é uma SPA do mesmo domínio-raiz; sessão por cookie é o caminho recomendado pelo Laravel, elimina refresh token, protege contra roubo de token por XSS e dá logout real.

**Requisito de implantação**: front e API sob o **mesmo domínio-raiz** (ex.: `app.pratoforte.exemplo` e `api.pratoforte.exemplo`).
**Fallback** 🟡 se isso não for possível: tokens de API do Sanctum (`createToken`), guardados em memória + cookie `HttpOnly` definido por uma rota do Next. Só adotar se o domínio comum for inviável.

### Configuração
- `config/cors.php`: `paths: ['api/*', 'sanctum/csrf-cookie']`, `allowed_origins: [env('FRONTEND_URL')]`, `supports_credentials: true`.
- `.env`: `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN=.{dominio}`, `SESSION_SECURE_COOKIE=true`, `SESSION_LIFETIME=43200` (30 dias, com "lembrar de mim" sempre ligado 🟡 — público usa o celular pessoal).
- Frontend: `fetch(..., { credentials: 'include' })`; antes da primeira escrita, `GET /sanctum/csrf-cookie`; envia `X-XSRF-TOKEN` lido do cookie `XSRF-TOKEN`.

## 2. Fluxos

### Cadastro — `POST /api/v1/register` (RF01)
Cria `users` + `profiles` vazio + `user_settings` padrão numa transação, inicia a sessão (`Auth::login`) e regenera o ID da sessão. Não cria conversa de boas-vindas nem mensagem `system` (diferente do 🟤; o estado inicial do Nutri é da interface).

### Login — `POST /api/v1/login` (RF02)
`Auth::attempt(['email' => lower(email), 'password'], remember: true)`; `session()->regenerate()`. Erro genérico `INVALID_CREDENTIALS` (não diz se o e-mail existe). Throttle por e-mail + IP (5/min).
Resposta inclui `onboarding_completed` e `next_step` para o front decidir o destino.

### Logout — `POST /api/v1/logout` (RF03)
`Auth::guard('web')->logout()`, `session()->invalidate()`, `session()->regenerateToken()`. `204`.

### Sessão
- `GET /api/v1/me` devolve usuário + estado do onboarding; o front chama ao carregar o app.
- Sessão expirada ⇒ `401 UNAUTHENTICATED` ⇒ front redireciona para `/entrar?voltar=…`.

### Recuperar senha (RF04)
1. `POST /password/forgot {email}` → `Password::sendResetLink` com `ResetPasswordNotification` (pt-BR, marca Prato Forte — não "Rentx" 🟤) cujo link é `${FRONTEND_URL}/senha/redefinir?token=…&email=…`. Sempre `200` (RN04).
2. `POST /password/reset {token, email, password, password_confirmation}` → `Password::reset`; sucesso encerra todas as sessões do usuário (apaga linhas em `sessions`) e **não** autentica automaticamente (o usuário faz login). Token de 60 min, uso único (padrão do Laravel; o Node usava 3 h e tabela própria).

### Trocar senha (RF05) — `PUT /me/password` — RN05
### Apagar conta (RF06) — `DELETE /me {password}` — RN06

## 3. Autorização

Um único papel (✅ D4): **usuário**. Não há `role`, `is_admin` nem permissões granulares. A autorização se resume a **posse** (RN43) e **estado** (RN07).

| Recurso | Regra | Implementação |
|---|---|---|
| Perfil, configurações, dia, pesagens, progresso | sempre do usuário autenticado; não há ID na rota | consultas a partir de `$request->user()` |
| `MealPlan` (`/plans/{plan}`) | `plan.user_id == user.id` | `MealPlanPolicy::view` → `denyAsNotFound` |
| `NutriConversation` | idem | `NutriConversationPolicy::view/delete/sendMessage` |
| `NutriMessage` (ações) | mensagem pertence a conversa do usuário **e** `role = assistant` | `NutriMessagePolicy::applyAction` |
| `DayMealItem` (`/days/{date}/items/{item}`) | item pertence a `day_meal` do usuário **e** da data da rota | checagem em `DayService` + escopo na consulta |
| `Rating` | item avaliado pertence ao usuário e é avaliável (RN40) | `RatingPolicy` |
| Rotas do app | onboarding concluído | middleware `onboarded` |

Form Requests com recurso chamam a Policy em `authorize()`; o restante retorna `true` (a autenticação já é garantida pela rota).

## 4. Proteção das rotas no frontend

- `src/proxy.ts` (Next 16 — o antigo `middleware.ts` foi renomeado para `proxy`): se não existir o cookie de sessão, rotas do app (`/hoje`, `/dieta`, `/nutri`, `/evolucao`, `/perfil`, `/onboarding/*`) redirecionam para `/entrar`. É só uma primeira barreira de UX — **a autoridade é a API**.
- Layout autenticado chama `GET /me`; `401` → `/entrar`; `onboarding_completed = false` → `/onboarding/{next_step}`.
- Rotas públicas: `/`, `/cadastro`, `/entrar`, `/senha/*`. Usuário logado que abre `/`, `/cadastro` ou `/entrar` vai para `/hoje` (ou para a etapa pendente) — decidido **no cliente** por `GET /me` (`RedirecionarSeLogado`), não no `proxy.ts`: depois do logout o Laravel mantém um cookie de sessão anônima, então a simples presença do cookie não prova login e o `proxy` criaria um laço `/entrar` ↔ `/hoje`.
- O link "Pular para o app" (`sr-only`) da tela inicial 🔵 é **removido** (contornava a autenticação — ver `99-inconsistencias.md`).
