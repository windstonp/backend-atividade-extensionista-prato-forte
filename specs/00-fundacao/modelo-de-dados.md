# Modelo de dados (MySQL 8 + Eloquent)

Convenções 🟡:
- Tabelas em inglês no plural, `snake_case`; colunas em inglês. Valores de enum **iguais aos do frontend** (`ganhar-massa`, `pre-treino`…) para não haver camada de tradução.
- PK `id` `BIGINT UNSIGNED AUTO_INCREMENT`. (O Node usava UUID 🟤; aqui a proteção contra enumeração vem das Policies — RN43 —, e IDs inteiros simplificam índices.)
- `created_at`/`updated_at` em todas as tabelas de domínio, salvo indicação.
- Todas as FKs para `users` com `ON DELETE CASCADE` (RN06). FKs para `foods` com `RESTRICT` (alimento em uso não é apagado; é desativado com `is_active = false`).
- Charset `utf8mb4`, collation `utf8mb4_0900_ai_ci` (comparação sem acento/maiúscula, útil para e-mail e nomes).
- Enums como `VARCHAR` + PHP Backed Enum com cast no Model (evita `ALTER` de `ENUM` do MySQL).
- Pesos: `DECIMAL(5,1)`; gramas: `DECIMAL(6,1)`; macros por 100 g: `DECIMAL(5,1)`; kcal por 100 g: `DECIMAL(6,1)`.

Legenda: **R** = obrigatório · **O** = opcional (nullable).

---

## 1. Contas

### `users`
Conta de acesso. Origem 🟤 (id, name, email, password) + ✅🟡.

| Campo | Tipo | R/O | Valores / regra |
|---|---|---|---|
| id | bigint PK | R | |
| name | varchar(120) | R | nome completo, 2–120 |
| email | varchar(255) | R | **único**, minúsculas (RN01) |
| password | varchar(255) | R | hash bcrypt (cast `hashed`) |
| consented_at | timestamp | R | RN03 |
| terms_version | varchar(20) | R | ex.: `2026-09` |
| remember_token | varchar(100) | O | |
| timestamps | | R | `created_at` alimenta "No Prato Forte desde {mês}" |

Índices: `UNIQUE(email)`.
Relacionamentos: 1:1 `profiles`, 1:1 `user_settings`, 1:N `meal_plans`, `day_meals`, `weigh_ins`, `nutri_conversations`, `ratings`, `usability_responses`, `sent_notifications`, `ai_requests`; N:N `restrictions`, `pantry_items`, `foods` (não curto); morph 1:N `push_subscriptions`.

### Tabelas padrão do Laravel
`password_reset_tokens`, `sessions` (driver `database`), `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` — criadas pelas migrations padrão.

---

## 2. Perfil

### `profiles`
Respostas do onboarding e dados do perfil. Origem 🔵 (`OnboardingAnswers`, `Profile` em `lib/types.ts`) + ✅.

| Campo | Tipo | R/O | Valores / regra |
|---|---|---|---|
| id | bigint PK | R | |
| user_id | FK users | R | **único** (1:1), cascade |
| preferred_name | varchar(40) | O | "Como podemos te chamar"; padrão = primeiro nome de `users.name` |
| goal | varchar(20) | O* | `ganhar-massa` · `perder-gordura` · `manter-peso` · `mais-disposicao` |
| sex | varchar(10) | O* | `feminino` · `masculino` · `nao-dizer` |
| age | tinyint unsigned | O* | 18–100 (RN09) |
| height_cm | smallint unsigned | O* | 120–230 |
| start_weight_kg | decimal(5,1) | O* | peso informado no onboarding (RN34) |
| goal_weight_kg | decimal(5,1) | O | RN10 |
| goal_weight_source | varchar(10) | O | `user` · `suggested` · `auto` |
| activity_level | varchar(10) | O* | `parado` · `leve` · `moderado` · `intenso` |
| work_posture | varchar(12) | O* | `sentada` · `em-pe` · `peso-pesado` |
| wake_time | time | O* | |
| training_time | time | O* | |
| sleep_time | time | O* | |
| training_days | json | R | array de 0–6, padrão `[]` |
| lunch_place | varchar(12) | O* | `casa` · `marmita` · `restaurante` |
| other_restrictions | json | R | array de strings (≤ 10 itens, ≤ 60 chars cada), padrão `[]` |
| completed_steps | json | R | slugs de etapas salvas, padrão `[]` |
| onboarding_completed_at | timestamp | O | não nulo ⇒ onboarding concluído |

\* Nulos só enquanto o onboarding não foi concluído; `OnboardingService::complete` garante todos preenchidos.
Criado junto com o usuário no cadastro (vazio).

### `user_settings`
| Campo | Tipo | R/O | Padrão |
|---|---|---|---|
| id | bigint PK | R | |
| user_id | FK users | R | único, cascade |
| unit_system | varchar(10) | R | `metric` (ou `imperial`) |
| notify_meal_reminders | boolean | R | `true` |
| notify_weekly_summary | boolean | R | `true` |
| notify_tips | boolean | R | `false` |
| usability_invite_dismissed_at | timestamp | O | banner do questionário dispensado |

---

## 3. Catálogo (dados de referência, semeados)

### `foods`
Catálogo de alimentos com composição nutricional. 🟡 (base TACO 4ª ed. — NEPA/UNICAMP, pública; complementado por rótulo quando faltar).

| Campo | Tipo | R/O | Regra |
|---|---|---|---|
| id | bigint PK | R | |
| slug | varchar(80) | R | único (`arroz-branco-cozido`) |
| name | varchar(120) | R | "Arroz branco cozido" |
| aliases | json | R | sinônimos para busca e RN16, padrão `[]` |
| group | varchar(20) | R | `proteina` · `carboidrato` · `leguminosa` · `laticinio` · `fruta` · `vegetal` · `gordura` · `bebida` · `outros` |
| kcal_per_100g | decimal(6,1) | R | ≥ 0 |
| protein_per_100g | decimal(5,1) | R | ≥ 0 |
| carbs_per_100g | decimal(5,1) | R | ≥ 0 |
| fat_per_100g | decimal(5,1) | R | ≥ 0 |
| typical_portion_g | decimal(6,1) | R | porção de referência (RN25) |
| unit_label | varchar(40) | O | medida caseira singular ("colher de sopa", "unidade") |
| unit_label_plural | varchar(40) | O | "colheres de sopa" |
| unit_grams | decimal(6,1) | O | gramas por medida caseira |
| substitution_note | varchar(120) | O | nota mostrada na folha de troca ("Mais fibra, segura a fome até o treino") |
| common_dislike | boolean | R | aparece na lista "prefiro não ver" (padrão `false`) |
| is_staple | boolean | R | básico sempre disponível (salada, azeite, café), padrão `false` |
| is_active | boolean | R | padrão `true` |
| source | varchar(40) | R | `TACO 4ª ed.` / `Rótulo` |

Índices: `UNIQUE(slug)`, `INDEX(group, is_active)`.
Integridade: `CHECK (kcal_per_100g >= 0 AND protein_per_100g >= 0 AND carbs_per_100g >= 0 AND fat_per_100g >= 0)`.

### `restrictions`
🔵 (`lib/labels.ts` → `RESTRICOES`, `ALERGIAS`).

| Campo | Tipo | R/O | Regra |
|---|---|---|---|
| id | bigint PK | R | |
| slug | varchar(30) | R | único: `lactose`, `gluten`, `castanhas`, `frutos-do-mar`, `sem-carne`, `sem-animal` |
| label | varchar(60) | R | "Intolerância a lactose"… |
| is_allergy | boolean | R | `castanhas`, `frutos-do-mar` = `true` |
| position | tinyint | R | ordem de exibição |

### `food_restriction` (pivô)
Quais alimentos cada restrição **exclui**. PK (`food_id`, `restriction_id`), ambas FK cascade.
Ex.: `sem-carne` exclui carnes bovina, suína, frango, peixes e frutos do mar; `sem-animal` exclui tudo de origem animal (incl. ovos, laticínios, mel); `lactose` exclui laticínios com lactose; `gluten` exclui trigo, cevada, centeio e aveia não certificada.

### `pantry_items`
Itens de "O que costuma ter na sua cozinha" 🔵.

| Campo | Tipo | R/O | Regra |
|---|---|---|---|
| id | bigint PK | R | |
| slug | varchar(40) | R | único |
| label | varchar(60) | R | "Ovos", "Arroz e feijão"… |
| category | varchar(20) | R | `proteinas` · `carboidratos` · `frutas` (títulos "Proteínas", "Carboidratos", "Frutas") |
| position | tinyint | R | |

Semeados (17, união das listas do onboarding e do perfil 🔵): Ovos, Frango, Carne moída, Peixe, Iogurte, Queijo, Arroz e feijão, Batata-doce, Tapioca, Macarrão, Cuscuz, Pão francês, Aveia, Banana, Mamão, Maçã, Laranja.

### `food_pantry_item` (pivô)
PK (`food_id`, `pantry_item_id`). Ex.: "Arroz e feijão" → arroz branco, arroz integral, feijão carioca, feijão preto.

### Pivôs do usuário
| Tabela | PK | Significado |
|---|---|---|
| `restriction_user` | (`user_id`, `restriction_id`) | restrições/alergias marcadas |
| `pantry_item_user` | (`user_id`, `pantry_item_id`) | itens da cozinha |
| `disliked_food_user` | (`user_id`, `food_id`) | "prefiro não ver no cardápio" (só alimentos `common_dislike`) |
Todas com FKs cascade.

---

## 4. Plano alimentar

### `meal_plans`
| Campo | Tipo | R/O | Valores / regra |
|---|---|---|---|
| id | bigint PK | R | |
| user_id | FK users | R | cascade |
| status | varchar(12) | R | `pending` · `generating` · `ready` · `failed` |
| is_active | boolean | R | padrão `false` |
| active_user_id | bigint | O | `user_id` quando `is_active`, senão `NULL` — `UNIQUE` garante 1 plano ativo por usuário. Coluna comum mantida pelo model (o MySQL não aceita `CASCADE` na FK da coluna-base de uma coluna gerada) |
| target_kcal | smallint unsigned | R | RN13 |
| target_protein_g | smallint unsigned | R | |
| target_carbs_g | smallint unsigned | R | |
| target_fat_g | smallint unsigned | R | |
| inputs | json | R | snapshot do perfil e da lista permitida usados (auditoria/regeneração) |
| attempts | tinyint unsigned | R | tentativas com a IA, padrão 0 |
| failure_reason | varchar(255) | O | `AI_UNAVAILABLE`, `AI_INVALID_RESPONSE`, `TIMEOUT`, `SUPERSEDED` (plano mais antigo que terminou depois de um mais novo, ou ainda na fila quando outro foi forçado — ver RN21), `RESTRICTIONS_CHANGED` (ficou pronto com alimento que a restrição atual proíbe — RN17) |
| ready_at | timestamp | O | |

Índices: `INDEX(user_id, status)`, `UNIQUE(active_user_id)`.

### `plan_meals`
| Campo | Tipo | R/O | Regra |
|---|---|---|---|
| id | bigint PK | R | |
| meal_plan_id | FK meal_plans | R | cascade |
| slot | varchar(12) | R | `cafe` · `lanche` · `almoco` · `pre-treino` · `jantar` |
| name | varchar(40) | R | RN15 |
| time | time | R | RN14 |
| position | tinyint | R | ordem por horário |

Índice: `UNIQUE(meal_plan_id, slot)`.

### `plan_meal_items`
| Campo | Tipo | R/O | Regra |
|---|---|---|---|
| id | bigint PK | R | |
| plan_meal_id | FK plan_meals | R | cascade |
| food_id | FK foods | R | restrict |
| grams | decimal(6,1) | R | 5–600 |
| position | tinyint | R | |

---

## 5. Dia

### `day_meals`
Refeições de uma data concreta (RN22).

| Campo | Tipo | R/O | Regra |
|---|---|---|---|
| id | bigint PK | R | |
| user_id | FK users | R | cascade |
| date | date | R | |
| meal_plan_id | FK meal_plans | R | plano de origem (cascade) |
| slot | varchar(12) | R | |
| name | varchar(40) | R | nome do dia (RN15: "Lanche da tarde" em dia sem treino) |
| time | time | R | |
| note | varchar(80) | O | "Depois do treino das 19h" |
| position | tinyint | R | |
| done_at | timestamp | O | não nulo ⇒ feita |

Índices: `UNIQUE(user_id, date, slot)`, `INDEX(user_id, date)`.

### `day_meal_items`
| Campo | Tipo | R/O | Regra |
|---|---|---|---|
| id | bigint PK | R | |
| day_meal_id | FK day_meals | R | cascade |
| food_id | FK foods | R | restrict |
| grams | decimal(6,1) | R | 5–600 |
| replaced_food_id | FK foods | O | alimento original da refeição-modelo (RN26) |
| source | varchar(8) | R | `plan` · `manual` · `nutri` |
| position | tinyint | R | |

### `day_meal_changes`
Histórico para "Desfazer" (RN27).

| Campo | Tipo | R/O | Regra |
|---|---|---|---|
| id | bigint PK | R | |
| user_id | FK users | R | cascade |
| date | date | R | |
| day_meal_id | FK day_meals | R | cascade |
| type | varchar(12) | R | `swap` · `apply_meal` |
| description | varchar(160) | R | texto do toast ("Arroz branco trocado por batata-doce") |
| items_before | json | R | snapshot `[{food_id, grams, replaced_food_id, source, position}]` |
| undone_at | timestamp | O | |
| created_at | timestamp | R | |

Índice: `INDEX(user_id, date, created_at)`.

---

## 6. Evolução

### `weigh_ins`
| Campo | Tipo | R/O | Regra |
|---|---|---|---|
| id | bigint PK | R | |
| user_id | FK users | R | cascade |
| date | date | R | ≤ hoje, ≥ hoje − 30 (RN34) |
| weight_kg | decimal(5,1) | R | 30,0–250,0 |

Índice: `UNIQUE(user_id, date)`.

---

## 7. Nutri

### `nutri_conversations`
| Campo | Tipo | R/O | Regra |
|---|---|---|---|
| id | bigint PK | R | |
| user_id | FK users | R | cascade |
| title | varchar(80) | O | RN28; nulo até a 1ª mensagem |
| summary | text | O | RN30, ≤ 600 chars |
| summarized_message_id | bigint | O | última mensagem coberta pelo resumo |
| last_message_at | timestamp | O | |

Índice: `INDEX(user_id, last_message_at)`.

### `nutri_messages`
| Campo | Tipo | R/O | Regra |
|---|---|---|---|
| id | bigint PK | R | |
| conversation_id | FK nutri_conversations | R | cascade |
| role | varchar(10) | R | `user` · `assistant` (o `system` do Node 🟤 não é gravado — RN29) |
| content | text | R | 1–1.000 chars quando `user` |
| follow_up | text | O | segundo parágrafo 🔵 |
| follow_up_suggestions | json | O | 0–3 perguntas de continuação (RN45); só em `assistant` |
| card | json | O | `{type: "swap"|"meal", ...}` calculado pelo backend (RN31) |
| actions | json | O | lista de ações validadas |
| actions_resolved_at | timestamp | O | RN31 — ação aplicada **ou** dispensada (uma vez) |
| resolved_action_index | tinyint unsigned | O | índice da ação escolhida |
| ai_request_id | FK ai_requests | O | null on delete |
| created_at | timestamp | R | |

Índice: `INDEX(conversation_id, id)`.

---

## 8. Validação com a comunidade

### `ratings`
| Campo | Tipo | R/O | Regra |
|---|---|---|---|
| id | bigint PK | R | |
| user_id | FK users | R | cascade |
| rateable_type | varchar(30) | R | morph map: `nutri_message`, `meal_plan` |
| rateable_id | bigint | R | |
| value | varchar(4) | R | `up` · `down` |
| comment | varchar(500) | O | |

Índice: `UNIQUE(user_id, rateable_type, rateable_id)`.
Integridade: morph não tem FK; ao apagar conversa/plano, o serviço apaga as avaliações ligadas.

### `usability_responses`
| Campo | Tipo | R/O | Regra |
|---|---|---|---|
| id | bigint PK | R | |
| user_id | FK users | R | cascade |
| round | varchar(20) | R | `config('validacao.rodada')` |
| sus_answers | json | R | 10 inteiros 1–5 |
| sus_score | decimal(4,1) | R | 0–100 (RN41) |
| usefulness | tinyint unsigned | R | 1–5 |
| liked | text | O | ≤ 1.000 |
| disliked | text | O | ≤ 1.000 |
| created_at | timestamp | R | |

Índice: `UNIQUE(user_id, round)`.

---

## 9. Notificações e operação

### `push_subscriptions`
Tabela do pacote `laravel-notification-channels/webpush` (migration publicada): `id`, `subscribable_type`, `subscribable_id`, `endpoint` (varchar 500, único), `public_key`, `auth_token`, `content_encoding`, timestamps.

### `sent_notifications`
| Campo | Tipo | R/O | Regra |
|---|---|---|---|
| id | bigint PK | R | |
| user_id | FK users | R | cascade |
| type | varchar(20) | R | `meal_reminder` · `weekly_summary` · `tip` |
| reference | varchar(60) | R | `2026-09-23:almoco`, `2026-W39`, `2026-W39:tue` |
| sent_at | timestamp | R | |

Índice: `UNIQUE(user_id, type, reference)` (RN38 — sem duplicata).

### `ai_requests`
Log de chamadas à IA, sem conteúdo (RN44). Serve para custo e para o relatório de validação.

| Campo | Tipo | R/O | Regra |
|---|---|---|---|
| id | bigint PK | R | |
| user_id | FK users | O | cascade |
| purpose | varchar(10) | R | `plan` · `chat` · `summary` |
| model | varchar(80) | R | |
| prompt_tokens | int unsigned | O | |
| completion_tokens | int unsigned | O | |
| duration_ms | int unsigned | R | |
| status | varchar(10) | R | `ok` · `invalid` · `error` · `timeout` (`invalid` reservado; o log registra ok/error/timeout) |
| error_code | varchar(40) | O | |
| created_at | timestamp | R | |

Índices: `INDEX(created_at)`, `INDEX(user_id, purpose)`.

---

## 10. Relacionamento geral (ER textual)

```
users 1───1 profiles
users 1───1 user_settings
users N───N restrictions        (restriction_user)
users N───N pantry_items        (pantry_item_user)
users N───N foods               (disliked_food_user)
users 1───N meal_plans 1───N plan_meals 1───N plan_meal_items N───1 foods
users 1───N day_meals  1───N day_meal_items N───1 foods (food_id, replaced_food_id)
             day_meals N───1 meal_plans
             day_meals 1───N day_meal_changes
users 1───N weigh_ins
users 1───N nutri_conversations 1───N nutri_messages N───1 ai_requests (opcional)
users 1───N ratings ───morph──▶ nutri_messages | meal_plans
users 1───N usability_responses
users 1───N push_subscriptions (morph subscribable)
users 1───N sent_notifications
users 1───N ai_requests
foods N───N restrictions        (food_restriction)
foods N───N pantry_items        (food_pantry_item)
```

---

## 11. Ordem das migrations

1. Padrão Laravel: `users` (com `consented_at`, `terms_version`), `password_reset_tokens`, `sessions`, `cache`, `jobs`
2. `profiles`, `user_settings`
3. `foods`, `restrictions`, `pantry_items`
4. `food_restriction`, `food_pantry_item`
5. `restriction_user`, `pantry_item_user`, `disliked_food_user`
6. `meal_plans` (com `active_user_id` único)
7. `plan_meals`, `plan_meal_items`
8. `day_meals`, `day_meal_items`, `day_meal_changes`
9. `weigh_ins`
10. `ai_requests`
11. `nutri_conversations`, `nutri_messages`
12. `ratings`, `usability_responses`
13. `push_subscriptions` (publicada pelo pacote), `sent_notifications`

---

## 12. Seeders e dados iniciais

### Referência (todos os ambientes, inclusive produção)
| Seeder | Conteúdo |
|---|---|
| `RestrictionSeeder` | as 6 restrições 🔵 com `is_allergy` |
| `PantryItemSeeder` | os 17 itens de cozinha 🔵 |
| `FoodSeeder` | lê `database/data/foods.csv` (≈ 60 alimentos no Plano 03, ampliado quando precisar: nome, grupo, macros/100 g, porção típica, medida caseira, nota de troca, `common_dislike`, `is_staple`, fonte, **slugs de restrições que o excluem**, **slugs de itens de cozinha**). Idempotente (upsert por `slug`). Cobertura mínima: todos os alimentos do `mock-data.ts` 🔵, todos os itens de cozinha, a lista "não curto" do mock (fígado, jiló, beterraba, peixe, berinjela), e ≥ 3 opções de troca por grupo principal. Fonte por linha: `TACO 4ª ed.`, `Rótulo`, `Tabela USDA` ou `Receita caseira (TACO)` — conferência pendente (P5). |

### Desenvolvimento e demonstração (`DemoSeeder`, só `local`/`staging`)
- **Camila Réus** (`camila@demo.pratoforte.test` / `demo1234`) reproduzindo o `mockProfile` 🔵: 27 anos, 164 cm, ganhar massa, meta 62 kg, moderado, sentada, treina 19:00 seg/qua/sex, marmita, cozinha do mock, alergia a castanhas, não curte fígado e jiló.
- Plano `ready` montado a partir do `mockDayPlan` (sem chamar IA), 6 pesagens do `mockWeighIns`, 28 dias de `day_meals` seguindo o padrão de constância do `mockAdherence`, 2 conversas com resumo.
- **Novo usuário** (`novo@demo.pratoforte.test`) com conta criada e onboarding parado na etapa `atividade` — para demonstrar a retomada.
- Uso: apresentação da validação na Zfit sem depender da IA (`AI_DRIVER=fake`).

### Testes
- **Factories** para todos os models, com *states* úteis: `User::factory()->onboarded()`, `->withAllergy('castanhas')`, `MealPlan::factory()->ready()`, `->failed()`, `DayMeal::factory()->done()`.
- Testes de feature usam `RestrictionSeeder`, `PantryItemSeeder` e um **catálogo reduzido fixo** (`TestFoodSeeder`, ~25 alimentos) para resultados determinísticos.
- **E2E**: `E2ESeeder` (acionado por `php artisan migrate:fresh --seed --seeder=E2ESeeder`) cria: usuário concluído sem alergia, usuário concluído com alergia a castanhas, usuário sem onboarding; e configura `AI_DRIVER=fake` com respostas roteirizadas (ver `integracao-ia.md` §6).
