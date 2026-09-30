# 03 — Plano alimentar: metas, geração, dia, semana, refeição, trocas

## 1. Contexto e fontes
- 🟢 "gerar recomendações e cardápios personalizados a partir do perfil e das restrições"; IA "processa os registros alimentares" (✅ D1: refeições feitas + trocas).
- 🔵 Telas Gerando, Pronto, Hoje (linha do dia `DayRail` + metas `Rail`), Dieta (semana), Detalhe da refeição com folha de troca, toast com "Desfazer". Estado no cliente em `plan-store.tsx` (será substituído pela API).
- 🟤 Nada no Node.
- ✅ D6 (plano híbrido validado).
- Regras: RN13–RN27, RN17 em destaque. IA: `00-fundacao/integracao-ia.md` §3.

## 2. Requisitos funcionais

### RF09 — Gerar plano com IA
**Atores:** usuário; sistema (fila).
**Pré-condições:** onboarding concluído (ou sendo concluído); nenhum plano gerando.
**Fluxo principal:**
1. Disparo: concluir onboarding (spec 02), "Refazer meu plano", ou mudança de restrição (RN21).
2. Sistema cria plano `pending` e responde 202; o app vai para "Gerando".
3. Job: calcula metas (RN13), horários (RN14), filtra alimentos (RN16), pede o cardápio à IA, ajusta porções e valida (RN18).
4. Plano `ready` vira ativo (RN20); o app mostra "Pronto".
**Alternativos:** IA falha 2× ou fora do ar → `failed` → "Não deu para montar agora" + "Tentar de novo" (novo `POST /plans`); passou de 3 min → `failed` (`TIMEOUT`).
**Regras:** RN13–RN20.

### RF10 — Ver o dia (Hoje)
**Fluxo:** abre `/hoje` → vê data, saudação, linha do dia com as 5 refeições (feitas, próxima aberta), metas de hoje (kcal consumidas de planejadas, proteína, carboidrato, gordura), régua de peso, atalho do Nutri.
**Regras:** RN15, RN22, RN24.

### RF11 — Ver a semana (Dieta)
**Fluxo:** `/dieta` → faixa de 7 dias (segunda a domingo), hoje selecionado → toca outro dia → vê as refeições daquele dia (futuro: prévia; passado: o que foi feito), "dia de treino"/"dia de descanso", total de kcal.
**Regras:** RN15, RN22, RN23 (só hoje é clicável/editável).

### RF12 — Detalhe da refeição
**Fluxo:** Hoje/Dieta → toca refeição de hoje → `/dieta/{slot}` → nome, horário, nota, kcal, % do dia, barras de macro em relação à meta, itens com porção caseira e macros, "Trocar" por item, "Marcar como feita".

### RF13 — Marcar/desmarcar refeição feita
**Fluxo:** toque no marcador da linha do dia ou botão "Marcar como feita" → estado muda na hora (otimista) → metas recalculam. Desmarcar é o mesmo gesto.
**Regras:** RN23, RN24. **Erro:** falha de rede → volta ao estado anterior + toast "Não foi possível salvar. Tente de novo."

### RF14 — Trocar alimento
**Fluxo:** Detalhe → "Trocar {item}" → folha com até 4 opções (porção, Δ kcal, carboidrato, nota), primeira pré-selecionada, garantia "Nenhuma dessas opções tem {restrição}" → "Usar {opção}" → item trocado com selo "Trocado" e "No lugar de {original}" → toast com "Desfazer".
**Alternativo:** sem opções → "Ainda não temos trocas cadastradas para este alimento. O Nutri consegue sugerir uma a partir do que você tem em casa." + "Perguntar ao Nutri" (abre `/nutri?pergunta=Não tenho {item} em casa. O que uso no lugar?` — o usuário escolhe conversa nova ou existente, spec 04).
**Regras:** RN17, RN25, RN26.

### RF15 — Desfazer
**Fluxo:** toast após troca (ou após aplicar ação do Nutri) → "Desfazer" → itens anteriores voltam. O toast some em ~6 s 🔵; depois disso o "Desfazer" some da interface (a API aceita até 15 min — RN27).

### RF18 — Refazer plano
**Fluxo:** Perfil → "Refazer meu plano" → confirmação ("Vamos montar um plano novo com suas respostas atuais. As refeições que você já marcou hoje ficam.") → Gerando → Pronto.
**Regras:** RN19, RN20.

## 3. Fluxos

```
[Gerar]  Resumo/Perfil/Preferências ─▶ POST (complete | plans | preferences) ─▶ 202 {plan_id}
         ─▶ /onboarding/gerando?plano={id}  ── polling GET /plans/{id} a cada 1,5 s ──┬─ ready  ─▶ /onboarding/pronto?plano={id} (ou ?voltar=)
                                                                                      └─ failed ─▶ erro + "Tentar de novo" ─▶ POST /plans ─▶ novo id

[Dia]    /hoje ─▶ GET /days/today ─┬─ 200 ─▶ DayRail + metas
                                   └─ 409 NO_ACTIVE_PLAN ─┬─ generating ─▶ estado "Seu plano está quase pronto" + link para Gerando
                                                          └─ failed    ─▶ estado "Não conseguimos montar seu plano" + "Tentar de novo"
         marcar ─▶ PATCH /days/{hoje}/meals/{slot} {done} (otimista)

[Troca]  /dieta/{slot} ─▶ "Trocar" ─▶ GET …/items/{id}/substitutions ─▶ escolher ─▶ POST …/items/{id}/swap ─▶ toast ─▶ (Desfazer ─▶ POST /days/{hoje}/undo)

[Semana] /dieta ─▶ GET /days/{data selecionada} (prévia se futuro; histórico se passado)
```

## 4. Telas ↔ backend

### S09 — Gerando (`/onboarding/gerando?plano={id}[&voltar=]`) 🔵
- **Objetivo:** acompanhar a geração.
- **Exibe:** 5 passos ("Lendo seu perfil", "Calculando calorias e proteína", "Escolhendo alimentos da sua lista", "Encaixando nos seus horários", "Conferindo suas restrições") avançando por tempo (520 ms 🔵) até o último, que só conclui quando o status for `ready`; barra de progresso; "Costuma levar uns 10 segundos."
- **Endpoint:** `GET /plans/{id}` (polling 1,5 s enquanto `pending`/`generating`; para ao sair da tela).
- **Estados:** gerando; erro (`failed` ou rede) — "Não deu para montar agora" / "Seus dados estão salvos. Foi a conexão com o Nutri que falhou no meio do caminho." / "Tentar de novo" → `POST /plans` e troca o `?plano=`; sucesso → `router.replace` para Pronto (ou `voltar`).
- **Sem `plano` na URL:** busca o plano mais recente via `GET /plans/active` ou volta ao Hoje.

### S10 — Pronto (`/onboarding/pronto?plano={id}`) 🔵
- **Exibe:** "Seu plano está pronto, {nome}"; "Cinco refeições… encaixadas entre o trabalho e o treino das {hora}"; "Um dia comum" com horário, nome e kcal por refeição; total; macros-meta; dica do Nutri; **avaliação 👍/👎 do plano** (spec 07).
- **Endpoint:** `GET /plans/{id}` (com `meals` quando `ready`).
- **Ações:** "Ver o dia de hoje" → `/hoje`; "Ajustar alguma coisa com o Nutri" → `/nutri` (lista de conversas).
- **Estados:** carregando (`Skeleton`); erro (`ErrorState`); plano não `ready` → volta a Gerando.

### S11 — Hoje (`/hoje`) 🔵
- **Exibe:** data por extenso, saudação por horário + nome preferido, iniciais (link Perfil), `Toast` da última alteração, `DayRail`, "Metas de hoje" (`CountUp` kcal consumidas de planejadas; `Rail` proteína/carboidrato/gordura consumidos × meta), card de peso com `ReguaPeso` (oculto sem meta) → `/evolucao`, `NutriBar` "Perguntar ao Nutri sobre o {próxima refeição}", convite do questionário (spec 07), `BottomNav`.
- **Dados:** `GET /days/today`, `GET /profile`, `GET /usability-responses/status`.
- **Ações:** marcar/desmarcar (RF13); abrir refeição (RF12); desfazer (RF15); Nutri (abre `/nutri?pergunta=…` — escolhe conversa nova ou existente; a pergunta chega pré-preenchida no campo, sem enviar — spec 04).
- **Estados:** carregando (`EsqueletoDoDia` 🔵); erro (`ErrorState` "Não foi possível carregar seu dia" 🔵); sem plano (`NO_ACTIVE_PLAN`: gerando/falhou, ver fluxo); todas feitas (a linha do dia mostra tudo ✓ e a `NutriBar` vira "Perguntar alguma coisa ao Nutri" 🔵); sucesso.

### S12 — Dieta (`/dieta`) 🔵
- **Exibe:** "Sua dieta", atalho Nutri, `WeekDayPicker` (seg–dom, hoje destacado), "{Dia da semana}, dia de treino/descanso", total de kcal do dia, lista `MealRow` (horário, nome, kcal, resumo, próxima/nota), rodapé "Toda refeição pode ser trocada…".
- **Dados:** `GET /days/{data}`; `training_days` do perfil já vem no dia (`is_training_day`).
- **Regras de interação:** só hoje é clicável (RN23); futuro mostra prévia com "Lanche da tarde" em dia sem treino (RN15); passado mostra ✓ reais (difere do mock, que zerava — ver `99-inconsistencias.md`).
- **Texto a ajustar** 🔵: "O plano se reequilibra sozinho no fim do dia" — não há reequilíbrio automático no MVP; trocar por "Toda refeição de hoje pode ser trocada. O Nutri ajuda quando o dia sair do plano." (ver `99-inconsistencias.md`).
- **Estados:** carregando (3 `Skeleton` 🔵); erro; dia passado sem registro (`EmptyState` "Nada registrado neste dia"); sucesso.

### S13 — Detalhe da refeição (`/dieta/[slot]`) 🔵
- **Exibe:** TopBar (voltar, selo "Refeição feita"/"Próxima refeição"), nome, "Hoje às {hora}, {nota}", kcal (`CountUp`) e "% do seu dia", `RailSimples` × 3 (macro da refeição / meta do dia), "O que vai no prato" (`FoodItemRow`: nome, selo "Trocado", porção, kcal/macros, "No lugar de …", botão "Trocar"), `NutriBar` "Não tenho {item proteico} em casa", botão "Marcar como feita"/"Desmarcar refeição".
- **Dados:** `GET /days/today` (a refeição é filtrada por `slot`); folha: `GET /days/{hoje}/items/{id}/substitutions`.
- **Folha de troca (`SubstitutionSheet`)**: título "Trocar {item}", descrição "{porção} trazem {carbo} de carboidrato e {proteína} de proteína. Estas opções chegam perto e mantêm o resto do prato.", `radiogroup` de opções (nome, Δ kcal colorido, porção, carboidrato + nota), garantia "Nenhuma dessas opções tem {restrições do usuário}" (**todas** as restrições, não só a primeira como no mock), "Usar {opção}" / "Cancelar".
- **Estados da tela:** carregando; slot inexistente/inválido → "Refeição não encontrada" 🔵; dia sem plano → estado de Hoje; sucesso.
- **Estados da folha:** carregando (3 `Skeleton` 🔵); vazio (texto + "Perguntar ao Nutri" 🔵); erro (mensagem + "Tentar de novo"); trocando (botão `carregando`); erro 409 `SUBSTITUTION_NOT_ALLOWED` → recarrega opções.

## 5. API

### `GET /api/v1/plans/preview-targets`
- **Auth:** sessão (não exige onboarding concluído); exige etapas `objetivo`, `dados`, `atividade` salvas.
- **Response 200:** `{ "data": { "kcal": 2250, "protein_g": 115, "carbs_g": 285, "fat_g": 60, "meals": 5 } }`
- **Erros:** 422 `VALIDATION_ERROR` com `details.missing_steps`.

### `POST /api/v1/plans`
- **Objetivo:** pedir (re)geração. **Auth:** sessão. **Throttle:** `plans`.
- **Request:** `{}`
- **Response 202:** `{ "data": { "id": 43, "status": "pending" } }`
- **Erros:** 409 `ONBOARDING_INCOMPLETE`; 409 `PLAN_ALREADY_GENERATING` (`details.plan_id`); 429.

### `GET /api/v1/plans/{plan}`
- **Permissão:** dono (`MealPlanPolicy`, 404 se não).
- **Response 200 (gerando):** `{ "data": { "id": 43, "status": "generating", "is_active": false } }`
- **Response 200 (pronto):**
```json
{ "data": {
  "id": 43, "status": "ready", "is_active": true, "ready_at": "2026-09-23T10:05:12-03:00",
  "targets": { "kcal": 2250, "protein_g": 115, "carbs_g": 285, "fat_g": 60 },
  "meals": [ { "slot": "cafe", "name": "Café da manhã", "time": "07:00", "calories": 430, "summary": "Ovos mexidos, pão francês, mamão e café" } ],
  "rating": null
} }
```
- **Response 200 (falhou):** `{ "data": { "id": 43, "status": "failed", "failure_reason": "AI_UNAVAILABLE" } }`

### `GET /api/v1/plans/active`
- **Response 200:** mesmo formato do `ready`, com `meals[].items` completos. **Erros:** 409 `NO_ACTIVE_PLAN`.

### `GET /api/v1/days/{date}`
- **`{date}`:** `today` ou `YYYY-MM-DD` entre hoje − 90 e hoje + 6 (fora: 422).
- **Response 200:**
```json
{ "data": {
  "date": "2026-09-21", "is_today": true, "editable": true, "materialized": true, "is_training_day": true,
  "targets": { "kcal": 2250, "protein_g": 115, "carbs_g": 285, "fat_g": 60 },
  "totals": {
    "planned":  { "calories": 2230, "protein": 116.2, "carbs": 281.0, "fat": 58.9 },
    "consumed": { "calories": 700,  "protein": 30.3,  "carbs": 90.0,  "fat": 23.9 },
    "remaining":{ "calories": 1530, "protein": 85.9,  "carbs": 191.0, "fat": 35.0 }
  },
  "meals": [
    { "id": 901, "slot": "almoco", "name": "Almoço", "time": "12:30", "note": null, "position": 3, "done": false, "is_next": true,
      "summary": "Batata-doce cozida, feijão carioca, frango grelhado, salada e azeite",
      "calories": 522, "macros": { "protein": 55.5, "carbs": 49.0, "fat": 17.8 },
      "items": [
        { "id": 5501, "food_id": 57, "name": "Batata-doce cozida", "grams": 180.0, "amount": "180 g, dois pedaços médios",
          "calories": 140, "macros": { "protein": 2.0, "carbs": 33.0, "fat": 0.2 },
          "source": "manual", "replaced_from": "Arroz branco cozido" }
      ] }
  ],
  "last_change": { "id": 77, "text": "Arroz branco trocado por batata-doce", "undo_until": "2026-09-21T11:15:00-03:00" }
} }
```
- `last_change` só quando há alteração desfazível (RN27).
- **Erros:** 409 `NO_ACTIVE_PLAN` (`details.plan_status`, `details.plan_id`); 422 data fora do intervalo.

### `PATCH /api/v1/days/{date}/meals/{slot}`
- **Request:** `{ "done": true }` · **Response 200:** o dia completo (mesmo formato).
- **Erros:** 409 `DAY_NOT_EDITABLE`; 404 slot inválido; 409 `NO_ACTIVE_PLAN`.

### `GET /api/v1/days/{date}/items/{item}/substitutions`
- **Response 200:**
```json
{ "data": {
  "item": { "id": 5500, "name": "Arroz branco cozido", "amount": "150 g, mais ou menos 5 colheres de sopa", "calories": 195, "macros": { "protein": 3.5, "carbs": 42.0, "fat": 0.4 } },
  "options": [
    { "food_id": 57, "name": "Batata-doce cozida", "grams": 180.0, "amount": "180 g, dois pedaços médios", "calories": 140,
      "macros": { "protein": 2.0, "carbs": 33.0, "fat": 0.2 }, "calorie_delta": -55, "note": "Mais fibra, segura a fome até o treino", "in_pantry": true }
  ],
  "guarantee": { "restrictions": ["Amendoim e castanhas"] }
} }
```
- **Erros:** 404 (item não é do usuário/da data); 409 `DAY_NOT_EDITABLE`.

### `POST /api/v1/days/{date}/items/{item}/swap`
- **Request:** `{ "food_id": 57 }`
- **Response 200:** o dia completo (com `last_change`).
- **Efeitos:** RN26; grava `day_meal_changes`. As gramas são as calculadas por RN25 (o cliente não envia gramas).
- **Erros:** 409 `DAY_NOT_EDITABLE`; 409 `SUBSTITUTION_NOT_ALLOWED` (alimento não está entre as opções válidas agora); 404.

### `POST /api/v1/days/{date}/undo`
- **Response 200:** o dia completo (sem `last_change` ou com o anterior, se ainda desfazível).
- **Erros:** 409 `NOTHING_TO_UNDO`; 409 `DAY_NOT_EDITABLE`.

## 6. Validações
| Item | Regra |
|---|---|
| `{date}` | `today` ou data válida em [hoje − 90, hoje + 6]; escrita só hoje (RN23) |
| `{slot}` | `MealSlot` |
| `done` | boolean obrigatório |
| `food_id` (swap) | inteiro; precisa estar na lista atual de `SubstitutionFinder` para o item (RN17, RN25) |
| Plano da IA | RN18 (JSON, 5 slots, 1–6 itens, 5–600 g, alimentos permitidos, kcal ±10%, proteína ≥ 90%) |

## 7. Critérios de aceitação
- **CA01** Dado onboarding concluído, quando a IA (falsa) responde um plano válido, então o plano fica `ready`, ativo, com 5 refeições nos horários do RN14 e totais a ±10% da meta.
- **CA02** Dado que a IA devolve um alimento proibido na 1ª tentativa e um válido na 2ª, então o plano fica `ready` e o alimento proibido não aparece em lugar nenhum.
- **CA03** Dado que a IA falha duas vezes, então o plano fica `failed`, a tela Gerando mostra o erro, e "Tentar de novo" cria um novo plano.
- **CA04** Dado acorda 06:20 e treino 19:00, então as refeições ficam 07:00, 10:00, 12:30, 17:30, 20:30 e o jantar tem a nota "Depois do treino das 19h" nos dias de treino.
- **CA05** Dado uma segunda-feira de treino e uma terça sem treino, então na Dieta a terça mostra "Lanche da tarde" no lugar de "Pré-treino".
- **CA06** Dado o almoço não feito, quando marca como feito, então as kcal consumidas aumentam exatamente o total do almoço, e o estado persiste ao recarregar.
- **CA07** Dado um dia de ontem, quando tenta marcar/trocar, então recebe `DAY_NOT_EDITABLE` e a interface nem oferece a ação.
- **CA08** Dado alergia a castanhas, então a folha de troca de qualquer item nunca lista alimento ligado a castanhas e mostra "Nenhuma dessas opções tem amendoim e castanhas".
- **CA09** Dado arroz trocado por batata-doce, quando toca "Desfazer" no toast, então o arroz volta com a porção original e sem selo "Trocado".
- **CA10** Dado uma troca feita há 16 min, então `POST /undo` responde `NOTHING_TO_UNDO`.
- **CA11** Dado um novo plano ficando ativo às 15:00 com café, lanche e almoço feitos, então esses três continuam iguais e pré-treino e jantar vêm do novo plano.
- **CA12** Dado um usuário B, quando pede `GET /plans/{id do A}` ou `GET /days/today/items/{item do A}/substitutions`, então recebe 404.

## 8. Test Strategy

### Component Tests
- `DayRail` 🔵 — ManhaNadaFeito, MeioDoDia (próxima aberta), TudoFeito, UmaTrocada; clique no marcador chama `aoAlternar(slot)`; `aria-pressed`/rótulo "Marcar {refeição} como feita"; reduced-motion.
- `MealRow` — Feita, Próxima, ComNota, NaoClicavel (futuro/passado), DiaSemTreino ("Lanche da tarde").
- `FoodItemRow` — Normal, Trocado ("No lugar de…"), SemBotaoTrocar (dia não editável).
- `SubstitutionSheet` — Carregando, ComOpcoes (1ª selecionada, setas navegam no radiogroup), Vazia (link Nutri), Erro, Trocando; foco preso; `Esc` fecha; garantia lista todas as restrições.
- `WeekDayPicker` — HojeSelecionado, OutroDia; `role=tab`, setas.
- `MacroSummary`/`Rail`/`RailSimples` — valor 0, parcial, acima da meta (barra limitada a 100% com texto do excesso), reduced-motion (sem animação de preenchimento).
- `Toast` — ComDesfazer (chama ação), Expira (chama `aoExpirar`), pausa com foco/hover.
- `PlanGenerating` — Passos, Erro (botão "Tentar de novo"), Sucesso.
- `NoPlanState` (novo) — Gerando, Falhou.

### Unit Tests
- Back: `NutritionCalculatorTest` (tabela: Camila do mock; homem intenso peso pesado; piso de 1.200/1.500; "não dizer"; arredondamentos; fator máximo 1,9); `MealSchedulerTest` (caso do mock; treino cedo; sem dias de treino; colisões; dorme depois da meia-noite); `PortionAdjusterTest` (escala dentro de 25%; não escala acima; limites 5–600); `PlanValidatorTest` (cada regra de RN18 com fixture); `SubstitutionFinderTest` (grupo, macro principal, limites 0,5–2×, ±35% kcal, ordenação cozinha → Δ, máx. 4, lista vazia, exclui proibidos); `DayTotalsTest` (feitas × planejadas, arredondamento); `PortionFormatterTest` ("150 g, mais ou menos 5 colheres de sopa"; singular/plural; sem medida caseira → só gramas).
- Front: `lib/nutrition.ts` (totais, próxima refeição) — já existe, ganha testes; `resumoDaRefeicao`.

### Integration Tests
- Back: `GeneratePlanJobTest` (FakeAiClient: válido → ready/ativo; inválido+válido → ready com 2 tentativas; inválido 2× → failed; exceção de rede → failed `AI_UNAVAILABLE`; timeout via `FailStalePlans`; RN20 preserva refeições feitas); `GeneratePlanTest` (202; 409 gerando; 429 na 6ª do dia; 409 onboarding incompleto); `PlanStatusTest` (dono vê; outro 404); `DayTest` (materializa hoje uma vez só — chamada dupla não duplica; futuro não grava; passado sem registro vem vazio; `NO_ACTIVE_PLAN` com detalhes; nomes/notas por dia de treino); `ToggleMealTest`; `SubstitutionsTest`; `SwapTest` (RN26; `replaced_food_id` mantém o original após trocas sucessivas; 409 `SUBSTITUTION_NOT_ALLOWED`); `UndoTest` (desfaz a última; 15 min; não desfaz 2×); `AllergyInvariantTest`.
- Front: `useToggleMeal` otimista (sucesso mantém; erro reverte + toast); página Hoje com MSW (carregando → sucesso; 409 `NO_ACTIVE_PLAN` gerando/falhou; 500 → `ErrorState` → "Tentar de novo"); Detalhe: abrir folha → escolher → trocar → toast → desfazer; Gerando: polling até `ready` → navega; `failed` → "Tentar de novo" → novo id.

### E2E Tests
- E2E-01 (fim: Hoje com 5 refeições), E2E-04 (marcar e persistir), E2E-05 (trocar e desfazer), E2E-06 (alergia), E2E-07 (falha e nova tentativa).

## 9. Definition of Done
```
[ ] CA01–CA12 atendidos — API no 04A (CA01–CA12 por feature test); telas no 04B
[x] Catálogo semeado (FoodSeeder + foods.csv) cobrindo mock e itens de cozinha
[x] NutritionCalculator, MealScheduler, PortionAdjuster, PlanValidator, SubstitutionFinder, DayTotals, PortionFormatter com unit tests
[x] GeneratePlanJob com FakeAiClient e fixtures; FailStalePlans agendado
[x] Endpoints de plano e dia; Policies; feature tests incluindo posse e alergia
[ ] plan-store.tsx substituído por hooks (TanStack Query) — sem localStorage para o plano
[ ] Hoje, Dieta, Detalhe, Gerando, Pronto ligados à API com todos os estados
[ ] DayRail, MealRow, FoodItemRow, SubstitutionSheet, WeekDayPicker com stories e play
[ ] Textos do mock ajustados conforme 99-inconsistencias (reequilíbrio, dias passados)
[ ] E2E-04, E2E-05, E2E-06, E2E-07 verdes
[ ] axe limpo; folha acessível por teclado; movimento preservado
```
