# Convenções da API e tratamento de erros

## 1. Convenções gerais

- Base: `https://api.{dominio}/api/v1`. Versão no caminho 🟡.
- JSON UTF-8. `Accept: application/json` obrigatório no cliente (garante respostas de erro em JSON).
- Chaves em `snake_case` 🟡. (O frontend usa `camelCase`; a conversão fica **só** em `src/lib/api/` — ver `arquitetura-frontend.md`.)
- Datas: `YYYY-MM-DD`; horários: `HH:MM` (24 h); instantes: ISO 8601 com fuso (`2026-09-23T12:30:00-03:00`).
- Números: kcal inteiras; macros e gramas com 1 casa; peso com 1 casa. Unidades sempre métricas (RN39).
- IDs numéricos.
- Autenticação por cookie de sessão (Sanctum SPA). Requisições de escrita exigem o header `X-XSRF-TOKEN` (ver `autenticacao-autorizacao.md`).

## 2. Formato de sucesso

Recurso único ou coleção via **API Resources** do Laravel, sempre dentro de `data`:
```json
{ "data": { "id": 1, "name": "Camila Réus" } }
```
Coleção paginada (cursor, para listas que crescem — conversas e mensagens):
```json
{ "data": [ ... ], "meta": { "next_cursor": "eyJpZCI6MTJ9", "per_page": 15 } }
```
Respostas com efeitos colaterais relevantes para a UI trazem `meta`:
```json
{ "data": { ... }, "meta": { "plan_effect": "regeneration_started", "plan_id": 42, "warnings": [ { "code": "GOAL_WEIGHT_OUTSIDE_HEALTHY_RANGE", "message": "…" } ] } }
```

| Situação | Status |
|---|---|
| Leitura | 200 |
| Criação síncrona | 201 |
| Processamento assíncrono aceito (geração de plano) | 202 |
| Sem corpo (logout, apagar) | 204 |

## 3. Formato de erro (único para toda a API)

```json
{
  "message": "Mensagem legível em português, pronta para exibir.",
  "code": "PLAN_ALREADY_GENERATING",
  "errors": { "campo": ["mensagem"] },
  "details": { }
}
```
- `message`: sempre presente, em pt-BR, sem detalhe técnico.
- `code`: sempre presente; o frontend decide comportamento por `code`, **nunca** pelo texto.
- `errors`: só em `422` de validação (formato padrão do Laravel).
- `details`: opcional, dados estruturados do erro (ex.: `next_step`, `retry_after`, `plan_status`).

Implementação: `bootstrap/app.php` → `withExceptions()` renderiza `ValidationException`, `AuthenticationException`, `AuthorizationException`, `ModelNotFoundException`/`NotFoundHttpException`, `ThrottleRequestsException`, `DomainException` e `Throwable` nesse formato. Em produção, `500` nunca expõe mensagem da exceção (corrige 🟤, que devolvia `Internal server error - ${err.message}`).

## 4. Tabela de códigos

| HTTP | `code` | Quando | O front faz |
|---|---|---|---|
| 401 | `UNAUTHENTICATED` | sem sessão / sessão expirada | vai para `/entrar?voltar={rota}` |
| 403 | `FORBIDDEN` | CSRF inválido (419 do Laravel é convertido) ou ação proibida sem recurso | recarrega o cookie CSRF e repete 1×; senão mensagem |
| 404 | `NOT_FOUND` | rota/recurso inexistente **ou de outro usuário** (RN43) | estado "não encontrado" da tela |
| 409 | `ONBOARDING_INCOMPLETE` | RN07; `details.next_step` | vai para `/onboarding/{next_step}` |
| 409 | `NO_ACTIVE_PLAN` | RN22; `details.plan_status`, `details.plan_id` | "gerando" ou "tentar de novo" |
| 409 | `PLAN_ALREADY_GENERATING` | RN19 | acompanha o plano existente |
| 409 | `DAY_NOT_EDITABLE` | RN23 | desabilita ações; mensagem |
| 409 | `NOTHING_TO_UNDO` | RN27 (expirou ou já desfeito) | esconde o "Desfazer" |
| 409 | `MEAL_ALREADY_DONE` | RN31, RN26 (D13: refeição com registro — "Você já registrou o que comeu nessa refeição.") | mensagem na resposta do Nutri |
| 409 | `ALREADY_REGISTERED` | spec 09 (item sugerido já registrado na refeição) | volta o ✓ do item |
| 409 | `SUGGESTION_ALREADY_REGISTERED` | RN26 (trocar item já registrado) | esconde "Trocar" e recarrega |
| 409 | `ACTION_ALREADY_APPLIED` | RN31 | marca ação como aplicada |
| 409 | `ACTION_EXPIRED` | RN31 | "Essa sugestão era para {data}." |
| 409 | `SUBSTITUTION_NOT_ALLOWED` | troca pedida fora das opções válidas (RN17/RN25) | recarrega opções |
| 409 | `ALREADY_RESPONDED` | RN41 | mostra agradecimento |
| 422 | `VALIDATION_ERROR` | Form Request | mensagem por campo (`errors`) |
| 422 | `INVALID_CREDENTIALS` | login incorreto | "E-mail ou senha incorretos." |
| 422 | `INVALID_RESET_TOKEN` | link inválido/expirado | "Esse link expirou. Peça outro." |
| 429 | `TOO_MANY_REQUESTS` | throttle; `details.retry_after` (s) | mensagem com tempo |
| 503 | `AI_UNAVAILABLE` | IA fora do ar/timeout | "Não enviada · Tentar de novo" 🔵 |
| 500 | `SERVER_ERROR` | inesperado | `ErrorState` com "Tentar de novo" 🔵 |

Avisos que **não** são erro (vão em `meta.warnings`): `GOAL_WEIGHT_OUTSIDE_HEALTHY_RANGE`, `GOAL_WEIGHT_RESET`, `GOAL_WEIGHT_SUGGESTED`.

## 5. Exceções de domínio

```php
throw new DomainException(ErrorCode::DayNotEditable);               // status e message vêm do enum
throw new DomainException(ErrorCode::NoActivePlan, details: ['plan_status' => 'failed', 'plan_id' => 7]);
```
`ErrorCode` concentra, por caso, `status()` e `message()` (pt-BR). Services lançam; controllers não fazem `try/catch` de regra.

## 6. Exemplos

**422 de validação**
```json
{
  "message": "Confira os campos destacados.",
  "code": "VALIDATION_ERROR",
  "errors": { "height_cm": ["A altura deve estar entre 120 e 230 cm."] }
}
```
**409 regra de negócio**
```json
{ "message": "Seu plano ainda não está pronto.", "code": "NO_ACTIVE_PLAN", "details": { "plan_status": "generating", "plan_id": 42 } }
```
**429**
```json
{ "message": "Muitas perguntas seguidas. Tente de novo em 40 segundos.", "code": "TOO_MANY_REQUESTS", "details": { "retry_after": 40 } }
```
