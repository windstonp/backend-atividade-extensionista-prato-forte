# 01 — Contas e autenticação

## 1. Contexto e fontes
- 🟢 O documento não descreve contas, mas o plano é "a partir do perfil de cada usuário" — exige identidade persistente.
- 🟤 Node: `POST /users` (cria + loga + cria chat "Boas vindas"), `POST /sessions`, `POST /refresh-token`, forgot/reset (rotas **nunca montadas**), avatar (rota nunca montada). JWT com segredos no código.
- 🔵 Mocks: **não há** telas de cadastro nem login; "Já tenho conta" leva direto a `/hoje`; Configurações tem "Trocar senha", "Sair desta conta", "Apagar minha conta e meus dados" (botões sem ação).
- ✅ D2: conta criada **antes** do onboarding. ✅ D5: trocar/recuperar senha e apagar conta no MVP. ✅ D7: Sanctum SPA.
- Regras: RN01–RN07, RN43. Autenticação detalhada em `00-fundacao/autenticacao-autorizacao.md`.

## 2. Requisitos funcionais

### RF01 — Cadastro
**Descrição:** o sistema permite criar uma conta com nome, e-mail e senha, aceitando o termo de uso de dados.
**Atores:** visitante.
**Pré-condições:** não autenticado.
**Fluxo principal:**
1. Na Boas-vindas, o visitante toca "Montar meu plano".
2. Informa nome, e-mail, senha e marca o aceite do termo.
3. O sistema valida, cria usuário + perfil vazio + configurações padrão, inicia a sessão.
4. O visitante é levado a `/onboarding/objetivo`.
**Alternativos:** e-mail já cadastrado → mensagem no campo com link "Entrar com este e-mail".
**Regras:** RN01, RN02, RN03.

### RF02 — Login
**Atores:** usuário. **Pré-condições:** não autenticado.
**Fluxo principal:** 1. "Já tenho conta" → `/entrar`. 2. Informa e-mail e senha. 3. Sistema autentica. 4. Onboarding completo → `/hoje` (ou `?voltar=`); incompleto → `/onboarding/{next_step}`.
**Alternativos:** credenciais erradas → "E-mail ou senha incorretos." (sem dizer qual); 6ª tentativa em 1 min → 429 com tempo de espera.
**Regras:** RN07, rate limit `login`.

### RF03 — Logout
**Fluxo:** Configurações → "Sair desta conta" → sessão invalidada, inscrição push deste navegador removida, cache limpo → Boas-vindas.

### RF04 — Recuperar senha
**Fluxo principal:** 1. Login → "Esqueci minha senha". 2. Informa e-mail. 3. Sistema **sempre** confirma "Se houver uma conta com esse e-mail, enviamos um link." 4. E-mail com link (60 min, uso único). 5. Link abre `/senha/redefinir`; usuário define nova senha. 6. Sessões antigas encerradas; vai ao Login com mensagem de sucesso.
**Alternativos:** link expirado/usado → "Esse link expirou. Peça outro." com botão para `/senha/esqueci`.
**Regras:** RN02, RN04.

### RF05 — Trocar senha
**Atores:** usuário autenticado. **Fluxo:** Configurações → "Trocar senha" → senha atual + nova + confirmação → sucesso: toast "Senha trocada." e volta a Configurações; outras sessões encerradas.
**Regras:** RN02, RN05.

### RF06 — Apagar conta
**Fluxo:** Configurações → "Apagar minha conta e meus dados" → folha de confirmação explicando o que será apagado → senha → "Apagar tudo" → dados removidos, sessão encerrada → Boas-vindas com toast "Sua conta foi apagada."
**Regras:** RN06.

## 3. Fluxos

```
Visitante → Boas-vindas → "Montar meu plano" → Cadastro → (201, sessão) → Onboarding/objetivo
                        ↘ "Já tenho conta" → Login ─┬─ ok + onboarding completo → Hoje
                                                   ├─ ok + incompleto → Onboarding/{next_step}
                                                   ├─ erro 422 → mensagem, fica
                                                   └─ "Esqueci minha senha" → Esqueci → e-mail → Redefinir → Login
Usuário → Perfil → Configurações ─┬─ Trocar senha → sucesso → Configurações
                                  ├─ Sair → Boas-vindas
                                  └─ Apagar conta → confirmação + senha → Boas-vindas
Qualquer tela autenticada + 401 → Login?voltar=rota → após login volta à rota
```

## 4. Telas ↔ backend

### S01 — Boas-vindas (`/`) 🔵 existente
- **Objetivo:** apresentar o app e levar a cadastro ou login.
- **Dados exibidos:** textos estáticos; régua de horários ilustrativa.
- **Ações:** "Montar meu plano" → `/cadastro` (antes: `/onboarding/objetivo`); "Já tenho conta" → `/entrar` (antes: `/hoje`).
- **Mudança:** remover o link `sr-only` "Pular para o app".
- **Endpoints:** nenhum. Se houver sessão (cookie), `proxy.ts` redireciona para `/hoje`.
- **Estados:** só sucesso.

### N01 — Criar conta (`/cadastro`) ✅ nova
- **Objetivo:** criar a conta antes do onboarding.
- **Layout (wireframe):**
  ```
  ← (volta à Boas-vindas)                 Steps 0/7 oculto
  [título] Vamos começar pela sua conta
  [texto]  Assim seu plano fica salvo e você entra de qualquer celular.
  Field  Nome completo            (autocomplete=name)
  Field  E-mail                   (type=email, autocomplete=email)
  Field  Senha  [mostrar]         (autocomplete=new-password) ajuda: "8 ou mais, com letra e número"
  OptionRow(quadrado) "Li e aceito o termo de uso dos meus dados de saúde" + link "Ler o termo" (Sheet)
  Button primária grande  "Criar conta"   (carregando: "Criando…")
  Link texto  "Já tenho conta"
  ```
- **Dados necessários:** nenhum prévio. Texto do termo: estático versionado no front (`terms_version`).
- **Endpoint:** `POST /register`.
- **Estados:** inicial; enviando (botão `carregando`, campos desabilitados); erro de campo (422 → `Field.erro`); erro geral (rede/500 → `FormError` com "Tentar de novo"); sucesso → navegação para `/onboarding/objetivo` (transição do template do onboarding).
- **Validações (front, espelhando o back):** nome 2–120; e-mail válido; senha RN02; aceite obrigatório.

### N02 — Entrar (`/entrar`) ✅ nova
- **Wireframe:** título "Que bom te ver de novo" · Field E-mail · Field Senha [mostrar] · Link "Esqueci minha senha" · Button "Entrar" · Link "Criar conta".
- **Endpoint:** `POST /login`; em seguida decide destino por `onboarding_completed`/`next_step`.
- **Estados:** enviando; erro `INVALID_CREDENTIALS` (mensagem acima do botão, foco volta ao e-mail); erro 429 (mensagem com segundos); sucesso.
- **Query `?voltar=`**: aceita só caminhos internos (começam com `/`, sem `//`) — evita *open redirect*.

### N03 — Esqueci minha senha (`/senha/esqueci`) ✅ nova
- **Wireframe:** TopBar voltar · título "Vamos recuperar seu acesso" · Field E-mail · Button "Enviar link" · após envio: `EmptyState` de sucesso "Confira seu e-mail" com dica de olhar o spam e botão "Voltar para entrar".
- **Endpoint:** `POST /password/forgot`. **Estados:** enviando; enviado (sempre, RN04); erro 429.

### N04 — Redefinir senha (`/senha/redefinir?token&email`) ✅ nova
- **Wireframe:** título "Crie uma senha nova" · Field Nova senha · Field Confirmar · Button "Salvar senha".
- **Endpoint:** `POST /password/reset`. **Estados:** enviando; erro `INVALID_RESET_TOKEN` (`ErrorState` com botão "Pedir outro link"); 422 por campo; sucesso → `/entrar` com toast "Senha nova salva. Entre com ela."
- Sem `token`/`email` na URL → mesmo estado de link inválido.

### N06 — Trocar senha (`/perfil/configuracoes/senha`) ✅ nova
- **Wireframe:** TopBar "Voltar para configurações" · título "Trocar senha" · Field Senha atual · Field Nova senha · Field Confirmar · Button "Salvar".
- **Endpoint:** `PUT /me/password`. **Estados:** enviando; 422 (senha atual errada → no campo "Senha atual"); sucesso → Configurações + toast.

### N07 — Apagar conta (Sheet em `/perfil/configuracoes`) ✅ nova
- **Conteúdo:** título "Apagar sua conta?" · lista do que será apagado (perfil, plano, pesagens, conversas com o Nutri, avaliações) · aviso "Não dá para desfazer." em `alerta` · Field Senha · Button variante **destrutiva** "Apagar tudo" · "Cancelar".
- **Endpoint:** `DELETE /me`. **Estados:** enviando; 422 senha incorreta; sucesso → `/` + toast.

### S19 — Configurações, bloco "Sua conta" 🔵 (resto da tela na spec 06)
- "E-mail" (de `GET /me`), "Trocar senha" → N06, "Sair desta conta" → `POST /logout`, "Apagar minha conta e meus dados" → N07.

## 5. API

### `POST /api/v1/register`
- **Objetivo:** criar conta e iniciar sessão. **Autenticação:** pública (exige cookie CSRF). **Permissão:** visitante. **Throttle:** `register`.
- **Request:**
  ```json
  { "name": "Camila Réus", "email": "Camila.Reus@gmail.com", "password": "senha1234", "password_confirmation": "senha1234", "terms_accepted": true, "terms_version": "2026-09" }
  ```
- **Response 201:**
  ```json
  { "data": { "id": 7, "name": "Camila Réus", "email": "camila.reus@gmail.com", "preferred_name": "Camila", "onboarding_completed": false, "next_step": "objetivo", "created_at": "2026-09-23T10:00:00-03:00" } }
  ```
- **Validações:** `name` required|string|min:2|max:120; `email` required|email:rfc|max:255|unique (RN01, após normalização); `password` required|confirmed|Password::defaults() (RN02); `terms_accepted` accepted; `terms_version` required|in:{versão vigente}.
- **Erros:** 422 `VALIDATION_ERROR`; 429.

### `POST /api/v1/login`
- **Request:** `{ "email": "...", "password": "..." }`
- **Response 200:** mesmo corpo de `/register`.
- **Erros:** 422 `INVALID_CREDENTIALS`; 422 `VALIDATION_ERROR` (campos vazios); 429.

### `POST /api/v1/logout`
- **Autenticação:** sessão. **Response:** 204.

### `GET /api/v1/me`
- **Response 200:** corpo do `/register` + `settings: { unit_system }`.
- **Erros:** 401.

### `POST /api/v1/password/forgot`
- **Request:** `{ "email": "..." }` · **Response 200:** `{ "message": "Se houver uma conta com esse e-mail, enviamos um link." }` · **Erros:** 422 (formato), 429.

### `POST /api/v1/password/reset`
- **Request:** `{ "token": "...", "email": "...", "password": "...", "password_confirmation": "..." }`
- **Response 200:** `{ "message": "Senha redefinida." }`
- **Erros:** 422 `INVALID_RESET_TOKEN`; 422 `VALIDATION_ERROR`; 429.

### `PUT /api/v1/me/password`
- **Request:** `{ "current_password": "...", "password": "...", "password_confirmation": "..." }`
- **Response 200:** `{ "message": "Senha trocada." }` · **Erros:** 422 (`current_password` incorreta, RN02), 401.

### `DELETE /api/v1/me`
- **Request:** `{ "password": "..." }` · **Response:** 204 · **Erros:** 422 senha incorreta, 401.

## 6. Validações (resumo)
| Campo | Regra | Mensagem |
|---|---|---|
| name | 2–120 | "Escreva seu nome." |
| email | RFC, ≤ 255, único | "Esse e-mail já tem conta." / "Confira o e-mail." |
| password | ≥ 8, letra + número, ≤ 72, confirmada | "Use 8 ou mais caracteres, com letra e número." |
| terms_accepted | aceito | "Para continuar, aceite o termo." |
| current_password | confere | "A senha atual não confere." |

## 7. Critérios de aceitação
- **CA01** Dado um visitante, quando ele se cadastra com dados válidos, então fica autenticado e vê `/onboarding/objetivo`.
- **CA02** Dado um e-mail já cadastrado (em qualquer caixa), quando alguém tenta cadastrar, então vê "Esse e-mail já tem conta." e nenhuma conta é criada.
- **CA03** Dado um usuário com onboarding completo, quando faz login, então vê `/hoje`; com onboarding parado em `atividade`, vê `/onboarding/atividade`.
- **CA04** Dado login com senha errada, então a mensagem não revela se o e-mail existe.
- **CA05** Dado qualquer e-mail, quando pede recuperação, então a resposta é a mesma; só e-mails cadastrados recebem link.
- **CA06** Dado um link de redefinição usado ou com mais de 60 min, então a redefinição falha com "Esse link expirou".
- **CA07** Dado um usuário logado em dois navegadores, quando troca a senha no primeiro, então o segundo recebe 401 na próxima requisição.
- **CA08** Dado um usuário que apaga a conta, então não existe mais nenhuma linha dele em nenhuma tabela e o e-mail pode ser usado de novo.
- **CA09** Dado um visitante sem sessão, quando abre `/hoje`, então vai para `/entrar?voltar=/hoje` e, após login, volta para `/hoje`.

## 8. Test Strategy

### Component Tests (stories com `play`)
- `Field` — Senha: alterna mostrar/ocultar; `aria-describedby` aponta para ajuda e erro.
- `RegisterForm` — Vazio, Preenchido, EnviandoCarregando (botão `aria-busy`, campos desabilitados), ErroDeCampo (e-mail duplicado), ErroGeral.
- `LoginForm` — Vazio, CredenciaisInvalidas, MuitasTentativas, Enviando.
- `PasswordResetForm` — Válido, LinkInvalido.
- `DeleteAccountSheet` — Aberta, SenhaIncorreta, Apagando; foco preso na folha; `Esc` fecha.

### Unit Tests
- Front: `schemas.ts` (validação de e-mail, senha RN02, aceite); `safeRedirect(voltar)` (bloqueia `//evil`, `https://…`).
- Back: nenhum serviço puro relevante (a lógica é do framework).

### Integration Tests
- Back (Pest Feature): `RegisterTest` (201 + perfil e settings criados + sessão ativa; 422 por campo; e-mail normalizado; unicidade case-insensitive), `LoginTest` (200, 422 genérico, 429 na 6ª), `LogoutTest` (sessão invalidada → `/me` 401), `PasswordResetTest` (`Notification::fake` — link com URL do front; resposta igual para e-mail inexistente; token expirado; sessões encerradas), `UpdatePasswordTest` (outras sessões encerradas), `DeleteAccountTest` (cascade em todas as tabelas — asserção por tabela).
- Front (Vitest + MSW): `LoginForm.integration` (422 → mensagem; 200 → `router.replace` conforme `next_step`); `RegisterForm.integration` (422 mapeado para os campos); `client.ts` (CSRF renovado e requisição repetida em 419).

### E2E Tests
- E2E-01 (início: cadastro), E2E-02 (login/logout/proteção de rota), E2E-10 (recuperação de senha via Mailpit).

## 9. Definition of Done
```
[x] CA01–CA09 atendidos (CA01, CA03, CA09 também pelo front)
[x] Endpoints, Form Requests, rate limiters, ResetPasswordNotification pt-BR   (Plano 01)
[x] Feature tests: sucesso, 422, 401, 429, cascade de exclusão                 (Plano 01)
[x] Telas N01–N04, N06, N07 geradas com frontend-design (animadas, identidade do mock)
[x] Stories + play dos formulários; integração com MSW
[x] proxy.ts + layout autenticado redirecionando corretamente
[x] Link "Pular para o app" removido; "Já tenho conta" → /entrar
[x] E2E-01 (parte de cadastro), E2E-02, E2E-10 verdes — o resto do E2E-01 é dos Planos 03/04
[x] axe limpo; formulários navegáveis por teclado; autocomplete correto
Pendente fora do Plano 02: remover a inscrição Web Push no logout (Plano 07); texto final do termo (P3).
E2E: o E2ESeeder tem uma conta concluída e uma de senha por navegador (limite de 5 logins/min por e-mail).
```
[ ] CA01–CA09 atendidos
[ ] Endpoints, Form Requests, rate limiters, ResetPasswordNotification pt-BR
[ ] Feature tests: sucesso, 422, 401, 429, cascade de exclusão
[ ] Telas N01–N04, N06, N07 geradas com frontend-design (animadas, identidade do mock)
[ ] Stories + play dos formulários; integração com MSW
[ ] proxy.ts + layout autenticado redirecionando corretamente
[ ] Link "Pular para o app" removido; "Já tenho conta" → /entrar
[ ] E2E-01 (parte de cadastro), E2E-02, E2E-10 verdes
[ ] axe limpo; formulários navegáveis por teclado; autocomplete correto
```
