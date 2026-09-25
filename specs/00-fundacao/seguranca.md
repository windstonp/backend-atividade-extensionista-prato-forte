# Segurança

Riscos identificados e medidas. Os itens marcados 🟤 são falhas encontradas no backend Node que **não podem se repetir**.

## 0. Ação imediata (antes de qualquer implementação) 🟤

- A chave da API de IA está commitada em `backend (legado node)/.env` e repetida como valor padrão em `src/utils/openai-provider.ts` (que também faz `console.log` da chave). Os segredos JWT estão em `src/config/auth.ts`. **Revogar a chave no painel da aimlapi.com e gerar outra**, guardada só no `.env` do servidor. Se o repositório for público, considerar a chave comprometida mesmo depois de apagada (fica no histórico).

## 1. Autenticação
| Risco | Medida |
|---|---|
| Força bruta no login | `throttle:login` — 5 tentativas/min por e-mail+IP; mensagem genérica |
| Enumeração de contas | login e "esqueci a senha" com resposta idêntica exista ou não o e-mail (RN04) 🟤 |
| Senhas fracas | RN02; bcrypt (padrão Laravel, custo 12) |
| Sequestro de sessão | cookie `HttpOnly`, `Secure`, `SameSite=Lax`; `session()->regenerate()` no login; invalidação no logout; troca/redefinição de senha encerra outras sessões |
| Token em URL/body 🟤 | não existe token no cliente (sessão por cookie) |

## 2. Autorização
| Risco | Medida |
|---|---|
| IDOR — acessar dado de outro usuário 🟤 (Node: `GET/DELETE /chats/:id` e `POST /messages/:chat_id` sem checar dono) | Policies em todo recurso com ID; consultas partindo de `$request->user()`; resposta 404 (RN43); **um teste de feature por recurso** garantindo o 404 |
| Acesso ao app sem onboarding | middleware `onboarded` (RN07) |
| Escalada de privilégio | não há papéis; nenhum campo de papel é aceito em request |

## 3. Validação de entrada
- Todo endpoint de escrita tem Form Request com regras explícitas (tipos, limites, enums via `Rule::enum`, listas via `Rule::in`/`exists`).
- Parâmetros de rota restritos por regex/enum (`{date}`, `{slot}`, `{step}`).
- Tamanho máximo do corpo: 64 KB 🟡 (nenhum endpoint precisa de mais).
- **Saída da IA é entrada não confiável**: parse estrito, validação de `food_id` contra `FoodFilter`, limites de gramas, descarte de ação inválida (RN17, RN18, RN31).

## 4. Mass assignment
- `$fillable` explícito em **todos** os models; nunca `$guarded = []`.
- Controllers passam `$request->validated()` (nunca `$request->all()`) aos services.
- Campos sensíveis (`user_id`, `is_active`, `status`, `done_at`, `action_applied_at`, `sus_score`) só são definidos pelo backend.

## 5. SQL Injection
- Somente Eloquent/Query Builder com bindings. Proibido `DB::raw`/`whereRaw` com dado de usuário concatenado (o seed de admin do Node 🟤 interpolava valores em SQL).
- Busca por "outras restrições" (RN16) é feita em PHP sobre a coleção normalizada, não em SQL dinâmico.

## 6. XSS
- A API só devolve JSON; o React escapa por padrão.
- **Proibido `dangerouslySetInnerHTML`** no frontend, inclusive para respostas do Nutri. Se no futuro houver Markdown, usar renderizador sem HTML bruto.
- Conteúdo gerado pela IA e comentários do usuário são tratados como texto puro.
- Cabeçalhos no Next: `Content-Security-Policy` (sem `unsafe-inline` para scripts), `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` mínima.

## 7. CSRF
- Sanctum SPA: cookie `XSRF-TOKEN` + header `X-XSRF-TOKEN` obrigatório em `POST/PUT/PATCH/DELETE`.
- CORS restrito a `FRONTEND_URL` com `supports_credentials`.
- `SameSite=Lax` impede envio do cookie de sessão em requisições cross-site de escrita.

## 8. Rate limiting (definidos em `AppServiceProvider`)
| Limiter | Limite | Chave |
|---|---|---|
| `login` | 5/min | e-mail + IP |
| `register` | 3/min | IP |
| `password` | 3/hora (forgot) · 5/hora (reset) | e-mail + IP |
| `nutri` | 20/min e 100/dia (RN33) | usuário |
| `plans` | 5/dia (RN19) | usuário |
| `api` (padrão) | 120/min | usuário ou IP |

## 9. Exposição de dados e privacidade (LGPD)
- Dados de saúde são **sensíveis** (LGPD art. 5º, II, e art. 11): coleta só com consentimento explícito (RN03), finalidade declarada (montar o plano; pesquisa anonimizada do projeto de extensão), exclusão total a pedido (RN06).
- **Minimização**: a IA recebe perfil numérico e listas de alimentos; **não** recebe nome completo, e-mail nem "outras restrições" em texto livre no prompt de plano. No chat, o nome preferido é enviado (necessário para o tom) — mencionado no termo.
- Resources expõem só o necessário: `UserResource` sem `password`, `remember_token`, `consented_at`. Nenhum endpoint devolve dados de outro usuário.
- Mensagem de onboarding 🔵 "Ficam só no seu perfil. Ninguém da academia vê." — verdadeira: não há papel de academia/admin; exportação é anonimizada (RN42).
- Logs: `ai_requests` sem conteúdo; logs de aplicação sem corpo de request em rotas de auth; `LOG_LEVEL=warning` em produção.
- Backups do MySQL criptografados/restritos (responsabilidade da hospedagem; registrar no relatório).

## 10. Uploads
Não há upload no MVP. O avatar do Node 🟤 (rota nunca montada, coluna inexistente) é descartado — as telas usam iniciais 🔵.

## 11. Web Push
- Chaves VAPID só no ambiente; a pública é exposta ao front via `GET /settings`.
- Payload das notificações sem dado sensível (ex.: "Almoço às 12:30" — sem peso, sem alergia).
- Inscrição vinculada ao usuário autenticado; `DELETE /push-subscriptions` no logout.

## 12. Dependências e operação
- `composer audit` e `npm audit` no CI (falha em severidade alta).
- `APP_DEBUG=false` em produção; `500` sem detalhes (ver `api-convencoes-e-erros.md`).
- HTTPS obrigatório (Let's Encrypt); HSTS no servidor web.
