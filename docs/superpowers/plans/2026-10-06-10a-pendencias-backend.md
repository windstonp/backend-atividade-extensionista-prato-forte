# Plano 10A — Pendências do backend (minors adiados dos Planos 05A–09) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Fechar os "minor (deferred)" do backend registrados nas revisões finais dos Planos 05A–09, cada um com um teste que falha antes.

**Architecture:** Correções pontuais nos serviços existentes; nenhuma tabela nova. Notificações vão para uma fila própria (`notifications`), ouvida antes da `default` pelo worker.

**Tech Stack:** Laravel 12, Pest, Larastan 6, Pint (Docker).

**Spec:** as mesmas dos planos de origem (`specs/04-nutri`, `05-evolucao`, `06-configuracoes-notificacoes`, `07-validacao-feedback`), `specs/00-fundacao/estrategia-de-testes.md`.

**Onde rodar:** backend, branch `plano-10a-pendencias-backend` saindo de `plano-09-demonstracao`.

## Decisões deste plano (rulings)

1. **Não entram** (custo > ganho, registrado): memoizar `FoodFilter::allowedFor` (05A — a lista muda no meio da requisição quando a pessoa salva restrições; cache por requisição daria alimento proibido); "ids de tipo errado" (05A — as rotas já usam `whereNumber`, não há caminho que chegue com tipo errado); `weight_kg` inteiro serializado como `60` (06A — JSON numérico igual para o cliente); "minuto perdido" no lembrete (07A — a spec pede o minuto exato; o `withoutOverlapping` + cron mantém); "rotina atual julga dias passados" (06A — sem histórico de rotina no modelo); "contas com termo 2026-09" (08A — não há usuário real ainda); "DemoSeeder só sobre banco limpo" (09 — documentado; é o uso pretendido).
2. **RN30 (seleção do alvo do resumo, 05A):** mantida — resume a conversa anterior mais recente com mensagens novas, como a regra diz; o que muda é evitar job duplicado e limitar a entrada.

## Global Constraints

- Nenhuma mudança de contrato de API além de 422 onde antes havia 500.
- Toda correção com teste que falha antes (TDD).

## Tasks

### Task 1: Nutri (05A)
- **Resumo sem duplicata:** `SummarizeConversationJob implements ShouldBeUnique`, `uniqueId()` = id da conversa, `uniqueFor` 300 s. Teste: despachar duas vezes com `Queue::fake()` ⇒ um job.
- **Entrada do resumo limitada:** no máximo as 40 mensagens mais recentes depois do último resumo (em ordem); `summarized_message_id` = a última. Teste: 60 mensagens novas ⇒ a IA falsa recebe 40 + system (+ resumo anterior).
- **`content` não-texto ⇒ 422:** `MessageController::store` só faz `trim` se for string. Teste: `content: ['x']` ⇒ 422 `content`.
- **Conversa apagada durante a resposta ⇒ 404:** dentro da transação, `NutriConversation::whereKey(id)->lockForUpdate()->first()`; nula ⇒ `ModelNotFoundException` (404). Teste: IA falsa que apaga a conversa durante o `chat()` ⇒ 404 e nada gravado.
- **N+1 na lista de conversas:** `ConversationController::index` com `withCount('messages')` e `addSelect` da última mensagem (subconsulta); `ConversationResource` usa os atributos carregados quando presentes. Teste: 15 conversas ⇒ número de consultas constante (`DB::enableQueryLog`, ≤ 6).

### Task 2: Evolução (06A)
- `ProgressService`: `where('date', '>=', …)` em vez de `whereDate` nas colunas `DATE` (índice). Teste existente cobre o comportamento; acrescentar fronteira do `3m`: pesagem em hoje − 90 entra, hoje − 91 não.
- `WeighInRequest`: `bail` e `attributes()` (`weight_kg` ⇒ "peso", `date` ⇒ "data"). Teste: `weight_kg: "abc"` ⇒ uma mensagem só, sem "weight kg".
- Teste de constância com dia de um plano antigo (não ativo) ⇒ conta como os outros.

### Task 3: Notificações (07A)
- Fila própria: `MealReminder`, `WeeklySummary`, `NutriTip` com `public $queue = 'notifications'` (via `onQueue` no construtor) e `deleteWhenMissingModels = true`; `compose.yaml` e `docs/implantacao.md`: `queue:work --queue=notifications,default`. Teste: `Notification::fake()` não serve — usar `Queue::fake()` e conferir a fila do `SendQueuedNotifications`.
- `TipSelector::streak()` olha no máximo 400 dias. Teste: dia completo há 500 dias não muda o resultado (sequência 0).
- Teste do comando de lembrete com quem dorme 01:00 e janta 23:30 ⇒ aviso às 23:15.

### Task 4: Validação e IA (08A, 09)
- `uso.csv`: `conversas`, `planos_gerados`, `planos_falhos` respeitam `--de/--ate` (pelo `created_at`). Teste com plano criado fora do período.
- `UsabilityService::dismiss` com `updateOrCreate` na linha de `user_settings`. Teste: sem a linha ⇒ cria e dispensa.
- Exportação ignora `purpose = 'smoke'` no `ia.csv`. Teste.
- `ai:smoke` com `maxTokens` 16.
- Testes que faltavam: 404 ao avaliar plano de outra pessoa (com corpo `code: NOT_FOUND`); `ia.csv` com dados (chamadas, falhas); `--ate` inclusivo no `avaliacoes.csv`.

### Task 5: Suíte, lint e commit por task
Run: `docker compose run --rm api bash -c "php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G"` ⇒ verde. Um commit por task: `fix(<area>): pendências da revisão (…)`.
