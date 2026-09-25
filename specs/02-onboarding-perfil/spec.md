# 02 — Onboarding, perfil, meta de peso, preferências e restrições

## 1. Contexto e fontes
- 🟢 "cardápios personalizados a partir do perfil e das restrições de cada usuário".
- 🔵 7 etapas (`onboarding-store.tsx` → `ETAPAS`), com progresso `Steps`, botão voltar e "Continuar"; respostas hoje em `localStorage`. Perfil, Preferências e restrições (`/perfil/preferencias`), edição por links para as telas do onboarding.
- 🟤 Nada no Node (só `name`, `email`).
- ✅ D2 (onboarding salvo no servidor etapa a etapa), ✅ D8 (meta de peso).
- Regras: RN07–RN12, RN16, RN21, RN34, RN39.

## 2. Requisitos funcionais

### RF07 — Onboarding por etapas
**Atores:** usuário autenticado sem onboarding concluído.
**Pré-condições:** conta criada (RF01).
**Fluxo principal:**
1. `objetivo` — escolhe 1 de 4 objetivos.
2. `dados` — nome preferido, idade, altura, peso de hoje, sexo biológico e, se ganhar/perder, meta de peso (opcional).
3. `atividade` — frequência de treino e tipo de trabalho.
4. `preferencias` — itens que tem na cozinha.
5. `restricoes` — restrições/alergias do catálogo + "Mais alguma coisa" (texto).
6. `rotina` — acorda, treina, dorme, dias de treino, onde almoça.
7. `resumo` — confere tudo, com "Editar" por linha e a prévia das metas; "Gerar meu plano".
Cada "Continuar" salva a etapa no servidor e avança. "Voltar" não perde nada.
**Alternativos:**
- Sai no meio e volta (outro dia/celular) → ao logar, cai na primeira etapa não salva, com respostas anteriores preenchidas.
- Erro de rede ao salvar → fica na etapa, mensagem "Não foi possível salvar. Tente de novo."; respostas digitadas não se perdem.
**Regras:** RN08, RN09, RN10, RN12.

### RF08 — Prévia das metas
Na etapa `resumo`, o sistema mostra "Com isso, seu plano começa em {kcal} por dia, com {proteína} g de proteína divididos em 5 refeições." calculado por RN13 (endpoint na spec 03).

### RF16 — Editar perfil
**Atores:** usuário com onboarding concluído.
**Fluxo:** Perfil → item ("Dados pessoais", "Rotina e horários", "Trocar objetivo") → abre a tela da etapa em **modo edição** (`?editar=1`) → "Salvar" → volta ao Perfil com toast descrevendo o efeito no plano (RN21).
**Particularidade:** em modo edição, "Peso de hoje" registra/atualiza a pesagem de hoje (RN34), não altera `start_weight_kg`.
**Regras:** RN10, RN11, RN21, RN34.

### RF17 — Editar preferências e restrições
**Fluxo:** Perfil → "Preferências alimentares" ou "Restrições e alergias" → `/perfil/preferencias` → marca/desmarca restrições, adiciona "outro alimento", marca itens da cozinha e "prefiro não ver" → "Salvar alterações".
**Efeito:** mudança em restrições/alergias/outros → plano refeito automaticamente (vai à tela "Gerando"); demais mudanças → toast "Salvo. Quer refazer seu plano com isso?" com ação "Refazer".
**Regras:** RN16, RN17, RN21.

## 3. Fluxos

```
Cadastro → objetivo → dados → atividade → preferencias → restricoes → rotina → resumo → [Gerar meu plano] → gerando (spec 03)
             ↑ voltar em qualquer etapa; "Editar {linha}" no resumo volta à etapa e o "Continuar" retorna ao resumo
Login com onboarding incompleto → /onboarding/{next_step}

Perfil → Dados pessoais → /onboarding/dados?editar=1 → Salvar ─┬─ plan_effect=regeneration_suggested → Perfil + toast "Refazer"
                                                              └─ plan_effect=none → Perfil + toast "Salvo"
Perfil → Rotina → /onboarding/rotina?editar=1 → Salvar → plan_effect=times_updated → Perfil + toast "Horários das refeições atualizados"
Perfil → Preferências → Salvar ─┬─ restrição mudou → /onboarding/gerando?plano={id}&voltar=/perfil
                                └─ só cozinha/não curto → Perfil + toast "Refazer"
Perfil → "Refazer meu plano" → confirmação → POST /plans → /onboarding/gerando?plano={id}&voltar=/perfil
```

## 4. Telas ↔ backend

Dados comuns às etapas: `GET /catalog/onboarding` (opções) + `GET /onboarding` (respostas salvas). Enquanto carregam: `Skeleton` no lugar do conteúdo, cabeçalho e `Steps` já visíveis. Erro ao carregar: `ErrorState` "Não foi possível carregar suas respostas" + "Tentar de novo".
Salvar: botão "Continuar" em `carregando`; erro 422 → mensagem no campo; erro de rede → `FormError` acima do botão.

### S02 — Objetivo (`/onboarding/objetivo`) 🔵
- **Exibe:** 4 `OptionRow` (título + descrição) — textos do mock 🔵.
- **Ação:** escolher; "Continuar" → `PATCH /profile/steps/objetivo {goal}`.
- **Validação:** obrigatório (botão desabilitado até escolher — hoje o mock pré-seleciona "ganhar-massa"; **manter sem pré-seleção** para não enviesar 🟡).
- **Modo edição** ("Trocar objetivo"): ao salvar, se RN11 zerar/sugerir meta, mostra aviso "Sua meta de peso foi ajustada para o novo objetivo" e oferece ir para Dados.

### S03 — Dados (`/onboarding/dados`) 🔵 + ✅ meta
- **Campos:** Nome preferido ("Como podemos te chamar", preenchido com o primeiro nome do cadastro) · Idade (anos) · Altura (cm) · Peso de hoje (kg) · Sexo biológico (`Segmento` 3 opções) · **Meta de peso (kg, opcional)** — só se objetivo ∈ {ganhar, perder}.
- **Ajuda da meta:** "Para {altura}, a faixa saudável vai de {min} a {max} kg." (atualiza ao digitar a altura; cálculo no front espelhando RN10, confirmado pela API).
- **Aviso não bloqueante** (RN10): meta fora da faixa → texto em `gema-texto`: "Essa meta fica fora da faixa saudável para a sua altura. Tudo bem seguir — vale conversar com um profissional."
- **Unidade imperial** (RN39): se `unit_system = imperial` (só no modo edição — no onboarding ainda é métrico), campos em lb e ft/in; conversão antes de enviar.
- **Endpoint:** `PATCH /profile/steps/dados`.
- **Validação:** RN09, RN10 (direção; limites 30–250).

### S04 — Atividade (`/onboarding/atividade`) 🔵
- `OptionRow` × 4 (frequência) + `Segmento` "E fora da academia, como é seu trabalho?" (sentada, em pé, peso pesado).
- `PATCH /profile/steps/atividade {activity_level, work_posture}`. Ambos obrigatórios.

### S05 — Preferências / cozinha (`/onboarding/preferencias`) 🔵
- Grupos "Proteínas", "Carboidratos", "Frutas" com `Chip`s — **vindos do catálogo** (hoje fixos no código).
- Contador `aria-live` "N alimentos marcados" 🔵.
- `PATCH /profile/steps/preferencias {pantry_items: [slug]}`.
- Nenhum obrigatório; com < 5 marcados, dica não bloqueante "Marque pelo menos uns 5 para o cardápio ficar com a sua cara." 🟡

### S06 — Restrições (`/onboarding/restricoes`) 🔵
- `OptionRow` quadrado compacto por restrição do catálogo, com `EtiquetaAlergia` quando `is_allergy`.
- Field "Mais alguma coisa" (ex.: "camarão, pimenta, leite de vaca") — separado por vírgula em itens.
- `PATCH /profile/steps/restricoes {restrictions: [slug], other_restrictions: [string]}`.
- Texto 🔵 "O Nutri nunca sugere um alimento marcado aqui, nem nas substituições." — garantido por RN17.

### S07 — Rotina (`/onboarding/rotina`) 🔵
- 3 `input type=time` (acorda, treina, dorme) · 7 botões de dia (`aria-pressed`) · `Chip`s do almoço (casa, marmita, restaurante) · dica da marmita 🔵.
- `PATCH /profile/steps/rotina {wake_time, training_time, sleep_time, training_days, lunch_place}`.
- Validação RN12: erro no campo "Treina às": "O treino precisa estar entre a hora que você acorda e a que dorme."

### S08 — Resumo (`/onboarding/resumo`) 🔵
- **Exibe:** linhas Objetivo · Você · Treino · Sua cozinha · Restrições (em `alerta` se há alergia) · Rotina, cada uma com "Editar {linha}" 🔵; bloco de prévia de metas.
- **Dados:** `GET /onboarding` + `GET /catalog/onboarding` (rótulos) + `GET /plans/preview-targets`.
- **Ação:** "Gerar meu plano" → `POST /onboarding/complete` → `/onboarding/gerando?plano={id}`.
- **Estados:** prévia carregando (`Skeleton` na linha de kcal); prévia com erro (esconde o bloco — não impede gerar); 422 no complete (etapa inválida) → vai à etapa indicada em `details.step`.

### S17 — Perfil (`/perfil`) 🔵
- **Exibe:** iniciais (derivadas de `name`), nome, subtítulo (ver pendência P1: hoje "Treina na Zfit desde agosto"), cartão do objetivo com `ReguaPeso` (início, atual, meta) — **oculto** se `goal = mais-disposicao`; meta `suggested` mostra "meta sugerida" 🟡; "Trocar objetivo"; atividade + academia + cidade; lista: Dados pessoais, Preferências alimentares ("N alimentos na sua cozinha"), Restrições e alergias (primeira alergia em `alerta` ou "Nenhuma restrição"), Rotina e horários, Notificações e conta (resumo das notificações ligadas), **Avaliar o app** (spec 07); `NutriBar`/botão "Refazer meu plano".
- **Endpoints:** `GET /profile`, `GET /settings`, `GET /usability-responses/status`.
- **Estados:** carregando (`Skeleton` ×3, já no mock); erro (`ErrorState`); sucesso.

### S18 — Preferências e restrições (`/perfil/preferencias`) 🔵
- **Exibe:** "O que você não pode comer" (todas as restrições do catálogo — o mock lista só 4, ver `99-inconsistencias.md`) + itens de "outras restrições" como `Chip` removível + "Adicionar outro alimento" (abre Field inline); "O que costuma ter na sua cozinha" (catálogo); "O que você prefere não ver no cardápio" (alimentos `common_dislike`).
- **Texto do mock a ajustar** 🔵: "Tudo aqui entra no plano da semana que vem" → "Restrições e alergias refazem seu plano na hora. O resto entra quando você refizer o plano." (ver `99-inconsistencias.md`). E "Os alimentos que você só não gosta podem voltar a ser sugeridos de vez em quando" → remover no MVP (RN16 os exclui sempre).
- **Ação:** "Salvar alterações" → `PUT /profile/preferences`.
- **Estados:** carregando; salvando; erro; sucesso (conforme `plan_effect`). Botão desabilitado se nada mudou.

## 5. API

### `GET /api/v1/catalog/onboarding`
- **Auth:** sessão (não exige onboarding). **Response 200:**
```json
{ "data": {
  "goals": [ { "value": "ganhar-massa", "label": "Ganhar massa magra", "description": "Comer um pouco acima do gasto, com proteína alta todo dia." } ],
  "activity_levels": [ { "value": "moderado", "label": "3 ou 4 vezes na semana", "description": "O ritmo da maior parte do pessoal da Zfit." } ],
  "work_postures": [ { "value": "sentada", "label": "Sentada" } ],
  "restrictions": [ { "slug": "castanhas", "label": "Amendoim e castanhas", "is_allergy": true } ],
  "pantry": [ { "category": "proteinas", "label": "Proteínas", "items": [ { "slug": "ovos", "label": "Ovos" } ] } ],
  "dislike_options": [ { "id": 88, "name": "Fígado" } ],
  "lunch_places": [ { "value": "marmita", "label": "Marmita no trabalho" } ]
} }
```

### `GET /api/v1/onboarding`
- **Response 200:**
```json
{ "data": {
  "completed": false, "completed_steps": ["objetivo", "dados"], "next_step": "atividade",
  "answers": { "goal": "ganhar-massa", "preferred_name": "Camila", "age": 27, "height_cm": 164, "weight_kg": 58.4, "sex": "feminino",
               "goal_weight_kg": 62.0, "goal_weight_source": "user", "activity_level": null, "work_posture": null,
               "pantry_items": [], "restrictions": [], "other_restrictions": [],
               "wake_time": null, "training_time": null, "sleep_time": null, "training_days": [], "lunch_place": null },
  "healthy_weight_range": { "min": 49.8, "max": 67.0 }
} }
```

### `PATCH /api/v1/profile/steps/{step}`
- **Objetivo:** salvar uma etapa (durante o onboarding e na edição). `{step}` ∈ `objetivo|dados|atividade|preferencias|restricoes|rotina`.
- **Request por etapa:**
| step | corpo |
|---|---|
| objetivo | `{ "goal": "perder-gordura" }` |
| dados | `{ "preferred_name": "Camila", "age": 27, "height_cm": 164, "weight_kg": 58.4, "sex": "feminino", "goal_weight_kg": 55.0 }` |
| atividade | `{ "activity_level": "moderado", "work_posture": "sentada" }` |
| preferencias | `{ "pantry_items": ["ovos", "frango", "arroz-e-feijao"] }` |
| restricoes | `{ "restrictions": ["castanhas"], "other_restrictions": ["camarão", "pimenta"] }` |
| rotina | `{ "wake_time": "06:20", "training_time": "19:00", "sleep_time": "23:00", "training_days": [1, 3, 5], "lunch_place": "marmita" }` |
- **Response 200:** `{ "data": <mesmo formato de GET /onboarding>, "meta": { "plan_effect": "none", "plan_id": null, "warnings": [] } }`
- **Validações:** ver §6.
- **Erros:** 401; 404 (step inválido); 422 `VALIDATION_ERROR`.
- **Efeitos:** marca a etapa em `completed_steps`; após onboarding concluído aplica RN21 e, em `dados`, RN34 (pesagem de hoje).

### `POST /api/v1/onboarding/complete`
- **Objetivo:** concluir o onboarding e pedir o primeiro plano.
- **Response 202:** `{ "data": { "plan": { "id": 42, "status": "pending" } } }`
- **Efeitos:** valida todas as etapas; grava `onboarding_completed_at`; cria a primeira pesagem (RN34); aplica meta sugerida/auto (RN10); dispara `GeneratePlanJob`.
- **Erros:** 422 `VALIDATION_ERROR` com `details.step` (primeira etapa incompleta); 409 se já concluído (idempotência: devolve o plano existente com 200) 🟡.

### `GET /api/v1/profile`
- **Response 200:**
```json
{ "data": {
  "name": "Camila Réus", "preferred_name": "Camila", "email": "camila.reus@gmail.com", "created_at": "2026-08-11T09:00:00-03:00",
  "goal": "ganhar-massa", "sex": "feminino", "age": 27, "height_cm": 164,
  "start_weight_kg": 56.8, "current_weight_kg": 58.4, "goal_weight_kg": 62.0, "goal_weight_source": "user",
  "healthy_weight_range": { "min": 49.8, "max": 67.0 },
  "activity_level": "moderado", "work_posture": "sentada",
  "wake_time": "06:20", "training_time": "19:00", "sleep_time": "23:00", "training_days": [1, 3, 5], "lunch_place": "marmita",
  "pantry_items": [ { "slug": "ovos", "label": "Ovos" } ],
  "restrictions": [ { "slug": "castanhas", "label": "Amendoim e castanhas", "is_allergy": true } ],
  "other_restrictions": ["camarão"],
  "disliked_foods": [ { "id": 88, "name": "Fígado" } ],
  "gym": "Zfit", "city": "Capivari de Baixo"
} }
```
`gym`/`city` vêm de `config('app.parceiro')` (constantes do projeto, não do usuário).

### `PUT /api/v1/profile/preferences`
- **Request:** `{ "restrictions": ["castanhas","lactose"], "other_restrictions": ["camarão"], "pantry_items": ["ovos"], "disliked_food_ids": [88, 91] }` (todas as listas obrigatórias, podem ser vazias — substituição completa).
- **Response 200:** `{ "data": <profile>, "meta": { "plan_effect": "regeneration_started", "plan_id": 43 } }`
- **Erros:** 422; 409 `PLAN_ALREADY_GENERATING` **não** ocorre — se já há plano gerando, o novo pedido é enfileirado após ele 🟡 (restrição é segurança; não pode ser perdida).

## 6. Validações
| Campo | Regra | Condição |
|---|---|---|
| goal | `Rule::enum(Goal)` | obrigatório em `objetivo` |
| preferred_name | string 1–40 | obrigatório em `dados` |
| age | inteiro 18–100 (pendência P2) | obrigatório |
| height_cm | inteiro 120–230 | obrigatório |
| weight_kg | decimal 30–250, 1 casa | obrigatório |
| sex | `Rule::enum(Sex)` | obrigatório |
| goal_weight_kg | decimal 30–250, 1 casa; `gt:weight_kg` se ganhar; `lt:weight_kg` se perder; **proibido** se manter/disposição | opcional |
| activity_level, work_posture | enums | obrigatórios em `atividade` |
| pantry_items | array; itens `exists:pantry_items,slug`; distintos | presente (pode ser vazio) |
| restrictions | array; itens `exists:restrictions,slug`; distintos | presente |
| other_restrictions | array ≤ 10; itens string 2–60, sem duplicados (case-insensitive) | presente |
| disliked_food_ids | array; itens `exists:foods,id` com `common_dislike = true` | presente |
| wake_time, training_time, sleep_time | `date_format:H:i` | obrigatórios em `rotina` |
| training_days | array ≤ 7; itens inteiro 0–6 distintos | presente |
| lunch_place | `Rule::enum(LunchPlace)` | obrigatório |
| (rotina) | RN12: treino na janela acordado; janela ≥ 12 h | validação `after` no Form Request |

## 7. Critérios de aceitação
- **CA01** Dado um usuário que salvou até `dados` e fechou o app, quando entra de novo, então abre `atividade` e, ao voltar, `dados` mostra o que ele digitou.
- **CA02** Dado objetivo "ganhar massa" e peso 58,4, quando informa meta 55, então vê erro "Para ganhar massa, a meta precisa ser maior que o peso de hoje."
- **CA03** Dado altura 164 e meta 75 (IMC ≈ 27,9), quando salva, então salva **e** vê o aviso de fora da faixa (não bloqueia).
- **CA04** Dado objetivo "ganhar massa" sem meta informada, quando conclui o onboarding, então a meta é 61,5 kg (58,4 × 1,05 → 61,32 → arredondado a 0,5 = 61,5) com rótulo "meta sugerida".
- **CA05** Dado objetivo "mais disposição", então Perfil, Hoje e Evolução não mostram régua de meta.
- **CA06** Dado acorda 06:20 e dorme 23:00, quando informa treino às 05:00, então vê o erro de RN12.
- **CA07** Dado um usuário com plano ativo, quando marca alergia a frutos do mar em Preferências, então vai à tela "Gerando" e o novo plano não contém nenhum alimento ligado a frutos do mar.
- **CA08** Dado um usuário com plano ativo, quando só adiciona "Maçã" na cozinha, então o plano não muda e aparece o toast com "Refazer".
- **CA09** Dado um usuário que muda o horário de treino para 07:00 no Perfil, então os horários das refeições de hoje não feitas mudam na hora (RN14), sem nova geração.
- **CA10** Dado o resumo, quando carrega, então mostra a prévia de kcal/proteína calculada pelo backend (não o texto fixo "1.950 kcal" do mock).

## 8. Test Strategy

### Component Tests
- `OnboardingStep` — Carregando, Pronto, Salvando (botão `carregando`), ErroAoSalvar, ModoEdicao (rótulo "Salvar"). Teclado: Enter no último campo aciona "Continuar".
- `OptionRow` — Marcado, Desmarcado, Quadrado, ComAlergia; `role=radio`/`checkbox` correto.
- `Chip` — Marcado/desmarcado, Removível (outras restrições).
- `Segmento` — navegação por setas.
- `GoalWeightField` (novo, domínio) — Oculto (manter/disposição), Vazio com faixa, DentroDaFaixa, ForaDaFaixaComAviso, DirecaoErrada (erro).
- `RoutineTimes` — erro RN12 no campo treino.
- `SummaryList` (resumo) — ComAlergia (linha em `alerta`), PreviaCarregando, PreviaIndisponivel.
- `ProfileHeader`/`GoalCard` — ComMeta, MetaSugerida, SemMeta (disposição).

### Unit Tests
- Back: `GoalWeightResolverTest` (direção, sugestão ±5% e arredondamento, faixa saudável, avisos, RN11); `NutritionCalculatorTest` (usado pela prévia — ver spec 03).
- Front: `healthyRange(heightCm)`; `splitOtherRestrictions("camarão, pimenta,,")`; conversões `units.ts` (kg↔lb, cm↔ft/in, ida e volta sem perda além de 0,1).

### Integration Tests
- Back: `OnboardingStepsTest` (cada etapa salva e marca `completed_steps`; `next_step` correto; 422 por campo — *dataset* com um caso por regra do §6), `CompleteOnboardingTest` (202; job disparado com `Queue::fake`; pesagem inicial criada; meta sugerida; 422 com `details.step`; idempotência), `PlanEffectTest` (*dataset* de RN21: cada campo → efeito esperado; `regeneration_started` dispara job; `times_updated` recalcula `plan_meals.time` e `day_meals` de hoje não feitos), `PreferencesTest` (substituição completa das listas; `disliked_food_ids` só `common_dislike`), `ProfileTest` (formato; `current_weight_kg` = última pesagem).
- Front: `useSaveStep` + `OnboardingStep` com MSW (sucesso navega; 422 mostra erro no campo; rede falha mantém dados); Preferências (salvar → `regeneration_started` navega para gerando; `regeneration_suggested` mostra toast).

### E2E Tests
- E2E-01 (onboarding completo), E2E-03 (retomar), E2E-06 (alergia — parte do onboarding), E2E-11 (mudança de restrição refaz plano).

## 9. Definition of Done
```
[ ] CA01–CA10 atendidos
[ ] Endpoints catalog, onboarding, steps, complete, profile, preferences
[ ] GoalWeightResolver + NutritionCalculator com unit tests por tabela de casos
[ ] Feature tests: cada etapa, cada regra do §6, cada linha de RN21
[ ] Telas do onboarding ligadas à API (sem localStorage), com modo edição
[ ] Listas de opções vindas do catálogo (sem arrays fixos nas telas)
[ ] Campo de meta de peso e avisos com stories
[ ] E2E-01, E2E-03, E2E-11 verdes
[ ] axe limpo; progresso anunciado; foco no título ao trocar de etapa
```
