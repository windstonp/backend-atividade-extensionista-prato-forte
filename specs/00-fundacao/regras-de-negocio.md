# Regras de negócio

Fonte única das regras do Prato Forte. As specs de feature **referenciam** estes identificadores; não redefinem.
Legenda de origem: 🟢 DOC · 🔵 MOCK · 🟤 NODE · ✅ DECISÃO · 🟡 SUGESTÃO TÉCNICA.

"Onde aplicar" usa os nomes de classes de `arquitetura-backend.md`. "FE" = também refletida no frontend (feedback imediato), mas a **autoridade é sempre o backend**.

---

## Contas

**RN01 — E-mail único** 🟤🟡
O e-mail é único entre usuários, comparado sem diferenciar maiúsculas; é gravado em minúsculas e sem espaços nas pontas.
Onde: `RegisterRequest` (`unique:users,email`), mutator em `User`, índice único no banco.

**RN02 — Força da senha** 🟡
Mínimo 8 caracteres, ao menos uma letra e um número; confirmação obrigatória. Máximo 72 (limite do bcrypt).
Onde: `Password::min(8)->letters()->numbers()` em `RegisterRequest`, `ResetPasswordRequest`, `UpdatePasswordRequest`. FE: dica sob o campo.

**RN03 — Consentimento de dados de saúde (LGPD)** 🟡 *(pendência P3)*
Dados de peso, altura, idade, restrições e alergias são dados pessoais sensíveis de saúde (LGPD art. 5º, II). O cadastro exige aceite explícito do termo; gravam-se `consented_at` e `terms_version`.
Onde: `RegisterRequest` (`accepted`), `users.consented_at`.

**RN04 — Recuperação de senha não revela contas** 🟡 (corrige 🟤)
`POST /password/forgot` responde sempre `200` com a mesma mensagem, exista ou não o e-mail. O link expira em 60 minutos e vale uma vez.
Onde: `PasswordResetController` usando `Password::sendResetLink` e ignorando o status `INVALID_USER` na resposta.

**RN05 — Troca de senha** 🔵✅
Exige a senha atual. Depois da troca, as outras sessões do usuário são encerradas; a sessão atual continua.
Onde: `UpdatePasswordRequest` (`current_password`), `Auth::logoutOtherDevices()`.

**RN06 — Exclusão de conta** 🔵✅
Exige a senha. Remove o usuário e **todos** os dados dele (perfil, planos, dias, pesagens, conversas, avaliações, questionários, inscrições push, logs de IA) por `ON DELETE CASCADE`. Irreversível. A sessão é encerrada.
Onde: `AccountController@destroy`, FKs com cascade.

## Acesso

**RN07 — Onboarding obrigatório** ✅
Com onboarding incompleto, o usuário só acessa: `/me`, `/logout`, senha, exclusão de conta, `/onboarding/*`, `/profile/steps/*`, `/catalog/*`, `/plans` (prévia, status e geração — a geração em si recusa com 409), `/settings` e `/push-subscriptions`. Qualquer outra rota responde `409 ONBOARDING_INCOMPLETE` com `next_step`.
Onde: middleware `EnsureOnboardingCompleted` (alias `onboarded`). FE: `proxy.ts` e guarda do layout redirecionam.

**RN43 — Posse de recursos** 🟡 (corrige 🟤 — no Node qualquer usuário lia/apagava chats de outro)
Todo recurso pertence a um usuário. Acessar recurso de outro usuário responde **404** (não 403), para não revelar existência.
Onde: Policies com `Response::denyAsNotFound()`; consultas sempre partindo de `$request->user()->...`.

## Perfil e onboarding

**RN08 — Onboarding por etapas** ✅🔵
Etapas, em ordem: `objetivo`, `dados`, `atividade`, `preferencias`, `restricoes`, `rotina`, `resumo`. Cada etapa é salva no servidor ao avançar. O usuário pode sair e retomar; `GET /onboarding` informa `next_step` (primeira etapa ainda não salva). A conclusão (`POST /onboarding/complete`) exige todas as etapas válidas.
Onde: `OnboardingService`, `profiles.completed_steps` (json).

**RN09 — Limites dos dados corporais** 🟡 *(idade mínima: pendência P2)*
Idade 18–100 anos (inteiro); altura 120–230 cm (inteiro); peso 30,0–250,0 kg (1 casa decimal). Sexo biológico: `feminino`, `masculino`, `nao-dizer` 🔵.
Onde: `ProfileStepRequest` (etapa `dados`). FE: máscara e mensagem inline.

**RN10 — Meta de peso** ✅
- Campo **opcional**, pedido na etapa `dados` apenas se objetivo ∈ {`ganhar-massa`, `perder-gordura`}.
- `ganhar-massa` ⇒ meta **>** peso atual; `perder-gordura` ⇒ meta **<** peso atual (erro 422 se incoerente).
- `manter-peso` ⇒ meta = peso atual (gravada automaticamente, `goal_weight_source = auto`).
- `mais-disposicao` ⇒ sem meta (`null`); telas escondem a régua de peso.
- Vazio em ganhar/perder ⇒ meta sugerida = peso atual × 1,05 (ganhar) ou × 0,95 (perder), arredondada a 0,5 kg e limitada à faixa de IMC 18,5–24,9 quando o peso atual estiver dentro dela; `goal_weight_source = suggested`.
- Limites físicos 30–250 kg. Fora do IMC 18,5–24,9 para a altura informada: **aceita e avisa** (`warnings[]` na resposta), **nunca bloqueia**.
- O campo mostra a faixa saudável como ajuda: "Para 1,64 m, a faixa saudável vai de 49,8 a 67,0 kg" (IMC × altura², 1 casa).
Onde: `GoalWeightResolver` (usado por `ProfileStepRequest` + `ProfileService`). FE: ajuda e aviso.

**RN11 — Troca de objetivo reavalia a meta** ✅
Ao mudar o objetivo, aplica-se RN10 de novo: se a meta atual ficar incoerente com o novo objetivo (ou o objetivo virar manter/disposição), ela é recalculada (`auto`/`suggested`/`null`) e a resposta inclui aviso `GOAL_WEIGHT_RESET`.
Onde: `ProfileService::updateStep('objetivo')`.

**RN12 — Rotina coerente** 🟡
`training_time` precisa estar dentro da janela acordado (`wake_time` → `sleep_time`; se `sleep_time` < `wake_time`, dorme depois da meia-noite). Janela acordado mínima de 12 h. Dias de treino: valores 0–6 (0 = domingo) sem repetição; lista pode ser vazia.
Onde: `ProfileStepRequest` (etapa `rotina`).

**RN21 — Efeito de mudanças do perfil sobre o plano** ✅🟡
| Mudou | Efeito | `plan_effect` na resposta |
|---|---|---|
| restrições, alergias ou "outras restrições" | **regeneração automática** do plano (segurança) | `regeneration_started` |
| objetivo, dados corporais, atividade, meta, cozinha, "não curto" | plano atual mantido; app oferece "Refazer meu plano" | `regeneration_suggested` |
| horários da rotina ou dias de treino | horários das refeições-modelo recalculados na hora, sem IA (RN14) | `times_updated` |
| nome preferido, sexo sem mudar metas | nada | `none` |
Durante o onboarding (ainda sem plano) o efeito é sempre `none`.
Pedido por restrição nunca espera o que já está gerando: cria outro plano, e ao ficar pronto só o mais novo é ativado (um mais antigo que termine depois vira `failed` `SUPERSEDED`, e os que ainda esperam na fila também). Enquanto o plano novo não fica pronto — ou se ele falhar —, o alimento restrito sai na hora das refeições de hoje não feitas e não aparece nos próximos dias nem volta por "Desfazer". Um plano que termine com alimento proibido pela restrição atual não é ativado (`failed` `RESTRICTIONS_CHANGED`) e outro é pedido na hora. Mudança de horário sempre reprograma, mesmo quando outra mudança da mesma etapa sugere refazer.
Onde: `ProfileService`, `PlanService::regenerate`, `MealScheduler`.

## Cálculo e plano

**RN13 — Cálculo das metas diárias** 🟡 (o mock promete "Calculando calorias e proteína" 🔵)
1. **TMB** (Mifflin-St Jeor): `10×peso + 6,25×altura − 5×idade + s`, com `s = +5` (masculino), `−161` (feminino), `−78` (não dizer — média).
2. **Fator de atividade**: parado 1,2 · leve 1,375 · moderado 1,55 · intenso 1,725; **+ ajuste do trabalho**: sentada +0 · em pé +0,05 · peso pesado +0,1 (fator final máx. 1,9).
3. **GET** = TMB × fator.
4. **Ajuste do objetivo**: ganhar +10% · perder −15% · manter 0 · disposição 0.
5. **Piso de segurança**: 1.200 kcal (feminino / não dizer) ou 1.500 kcal (masculino).
6. **Proteína**: 2,0 g/kg (ganhar, perder) · 1,6 g/kg (manter, disposição).
7. **Gordura**: 25% das kcal ÷ 9, mínimo 0,8 g/kg.
8. **Carboidrato**: kcal restantes ÷ 4 (mínimo 100 g; se não couber, reduz-se a gordura até o mínimo).
9. **Arredondamento**: kcal ao múltiplo de 50; macros ao múltiplo de 5 g.
Os números do mock (1.950 kcal / 120 g) são ilustrativos, não normativos.
Onde: `NutritionCalculator` (puro, testado por tabela de casos). Usado por `/plans/preview-targets` e `GeneratePlanJob`.

**RN14 — Horários das refeições** 🔵🟡 (o mock: acorda 06:20, treina 19:00 → 07:00, 10:00, 12:30, 17:30, 20:30)
- `cafe` = acorda + 40 min.
- `almoco` = 12:30 (fixo).
- `lanche` = ponto médio entre café e almoço, arredondado **para cima** à meia hora (07:00–12:30 → 09:45 → 10:00).
- `pre-treino` = treino − 1h30; se isso cair antes de café + 2h, vira treino − 45 min. Sem dias de treino cadastrados: 16:00.
- `jantar` = treino + 1h30 se o treino **começa** às 16:00 ou depois; senão dorme − 2h30.
- **Treino cedo** (começa até 2h depois de acordar): `pre-treino` = acorda + 10 min e `cafe` = treino + 1h15 (o café vira pós-treino); os demais seguem as regras acima a partir do novo café.
- Nenhuma refeição antes de acorda nem depois de dorme − 1h; horários colidindo (< 1h30 de distância) são empurrados para frente em passos de 30 min.
- Todos os horários arredondados a 5 min.
Onde: `MealScheduler` (puro).

**RN15 — Estrutura do plano** 🔵
- Sempre 5 refeições, uma por slot, **exibidas em ordem de horário** (normalmente cafe, lanche, almoco, pre-treino, jantar; com treino cedo o pré-treino vem primeiro). Nomes: "Café da manhã", "Lanche da manhã", "Almoço", "Pré-treino", "Jantar".
- O mesmo modelo vale para todos os dias da semana (MVP).
- Em dia **sem treino**, o slot `pre-treino` é exibido como "Lanche da tarde" (mesmos alimentos e horário).
- Em dia de treino, se o jantar vem depois do treino, o jantar recebe a nota "Depois do treino das {HH}h" 🔵.
- `summary` da refeição = nomes dos itens unidos por vírgula e "e" 🔵.
Onde: `PlanGenerator` (persistência), `DayMaterializer` (nome/nota por data).

**RN16 — Alimentos permitidos** ✅
Permitidos = catálogo ativo **com `in_plans`** (RN52) − alimentos ligados a qualquer restrição do usuário (via `food_restriction`) − alimentos cujo nome/sinônimo normalizado (minúsculas, sem acento) contém qualquer termo de "outras restrições" − alimentos marcados como "não curto". Os alimentos ligados aos itens de cozinha marcados recebem prioridade (`pantry: true`) — **não** exclusividade.
Onde: `FoodFilter::allowedFor(User)`. Única porta de entrada de alimentos para geração, trocas e ações do Nutri.

**RN17 — Alergias e restrições nunca aparecem** 🔵✅ ("O Nutri nunca sugere um alimento marcado aqui, nem nas substituições")
Nenhum alimento fora de RN16 pode ser persistido em plano, sugestão do dia, troca ou ação do Nutri. Registros do que o usuário comeu seguem RN51. Toda escrita de item passa por verificação de `FoodFilter`; violação é erro de programação/IA — o item é rejeitado, nunca salvo.
Onde: `PlanValidator`, `SubstitutionFinder`, `NutriActionValidator`, `DayService`. Teste E2E dedicado.

**RN18 — Validação do plano gerado pela IA** ✅🟡
A resposta da IA é aceita somente se:
1. JSON válido no contrato (`integracao-ia.md`);
2. exatamente os 5 slots, sem repetição;
3. cada refeição com 1 a 6 itens; cada item com `food_id` permitido (RN16) e 5–600 g;
4. depois do `PortionAdjuster` (escala uniforme das porções do dia para bater a meta de kcal, se o desvio for ≤ 25%; porções arredondadas a 5 g; ao aumentar, nenhuma porção passa de 2,5× a de costume, `typical_portion_g` — 🟡 Plano 10C), total de kcal a ±10% da meta e proteína ≥ 90% da meta.
Falhou: **1 nova tentativa**, enviando à IA a lista de erros. Falhou de novo: plano `failed` com `failure_reason`.
Onde: `GeneratePlanJob` → `PlanGenerator` → `PortionAdjuster` → `PlanValidator`.

**RN19 — Limites de geração** 🟡
Só um plano em `pending`/`generating` por usuário (`409 PLAN_ALREADY_GENERATING`). Máximo 5 gerações por usuário por dia (`429`). Um plano `generating` há mais de 3 min é marcado `failed` (timeout).
Onde: `PlanService::requestGeneration`, rate limiter `plans`, `GeneratePlanJob` (`timeout`, `failed()`).

**RN20 — Ativação de um novo plano** 🟡
Ao ficar `ready`, o novo plano vira o único ativo (`is_active`); o anterior é mantido como histórico. No dia de hoje, refeições **não feitas** são refeitas a partir do novo plano; refeições **feitas** são preservadas.
Onde: `PlanService::activate` (transação), `DayMaterializer::refreshPending`.

## Dia

**RN22 — Materialização do dia** 🟡
- **Hoje**: na primeira leitura, o dia é copiado do plano ativo para `day_meals`/`day_meal_items`.
- **Futuro** (até +6 dias): retornado como prévia do plano ativo, sem gravar, somente leitura.
- **Passado** (até −90 dias): retorna o que foi gravado; se nada foi gravado, dia vazio. Somente leitura.
- **Ontem** ✅ D13: editável para o registro; se não foi materializado, é gravado na **primeira leitura** a partir do plano ativo (nomes e notas pelo dia da semana de ontem, RN15) — a sugestão precisa de ids para o "+" (Plano 11C).
- Sem plano ativo: `409 NO_ACTIVE_PLAN` (com status do último plano, para a tela mostrar "gerando" ou "tentar de novo").
Onde: `DayMaterializer`.

**RN23 — Dias editáveis** 🔵 revisada ✅ D13
**Registro alimentar** (adicionar, editar, remover — RN46) vale para **hoje e ontem** no fuso `America/Sao_Paulo`. **Trocar, aplicar ação do Nutri e desfazer** mexem na sugestão e só valem para **hoje**. Outras datas: `409 DAY_NOT_EDITABLE`.
Onde: `DayService` (checagem central), `config('app.timezone')`.

**RN24 — Totais do dia** 🔵
Planejado (meta) = soma dos itens **sugeridos** de todas as refeições. Consumido = soma dos **registros** (`meal_entries`, RN49) — ✅ D13; antes era a soma dos itens das refeições feitas. Restante = max(0, planejado − consumido). Macros por item = macros por 100 g do catálogo × gramas ÷ 100; kcal arredondadas ao inteiro, macros a 1 casa.
Onde: `DayTotals` (puro), exposto em `DayResource`. FE: `lib/nutrition.ts` recalcula para atualização otimista.

**RN25 — Opções de troca** 🔵🟡
Para um item do dia:
1. candidatos = alimentos permitidos (RN16) do **mesmo grupo**, exceto o próprio;
2. porção equivalente pelo **macro principal do grupo** (carboidrato → carbs; proteína/laticínio/leguminosa → protein; gordura → fat; fruta/vegetal/bebida/outros → kcal), arredondada a 5 g, limitada a 0,5×–2× a porção típica do alimento;
3. descarta candidatos cuja kcal fique fora de ±35% da original;
4. ordena: primeiro os da cozinha do usuário, depois menor |Δ kcal|;
5. devolve até 4 opções, com medida caseira (`PortionFormatter`) e a nota do catálogo.
Lista vazia é válida (mock: "Ainda não temos trocas cadastradas…").
Onde: `SubstitutionFinder` (puro sobre coleções; testado com catálogo fixo).

**RN26 — Efeito da troca** 🔵
A troca vale só para aquele dia. O item recebe `replaced_food_id` (o original da refeição-modelo, mesmo após trocas sucessivas) e `source = manual`. O resumo da refeição é refeito. Uma troca muda só a **sugestão**: não cria nem altera registro (RN46). ✅ D13: item sugerido **já registrado** não pode ser trocado (`409 SUGGESTION_ALREADY_REGISTERED`; a tela nem mostra "Trocar"); a ação "trocar a refeição inteira" do Nutri (RN31) só vale para refeição **sem registro** (`409 MEAL_ALREADY_DONE`, "Você já registrou o que comeu nesse {refeição}.").
Onde: `DayService::swap`.

**RN27 — Desfazer** 🔵
"Desfazer" reverte a **última** alteração de conteúdo do dia (troca ou aplicação de refeição pelo Nutri), se feita há no máximo 15 min e ainda não desfeita. Marcar/desmarcar não entra (já é reversível). Cada alteração grava um snapshot dos itens anteriores. ✅ D13: desfazer devolve cada item **na mesma linha** (mesma `position`), sem apagar e recriar a refeição, para os registros continuarem ligados aos seus itens sugeridos.
Onde: `DayService::undo`, tabela `day_meal_changes`.

## Registro alimentar ✅ D13 (spec 09)

**RN46 — Refeição registrada ("feita")**
Uma refeição está **feita** quando tem pelo menos um registro em `meal_entries`. `day_meals.done_at` (nome mantido) guarda o horário do primeiro registro e volta a `null` quando o último é removido; é mantido só pelo `EntryService` e é a única fonte de "feita" para Hoje, constância (RN36), lembretes (RN38), resumo semanal, dicas e exportação. Não há mais marcar/desmarcar.
Onde: `EntryService`, migration que converte `done_at` em registros (CA44).

**RN47 — Gramas ou mililitros**
Cada alimento tem `measure` = `g` (sólido) ou `ml` (líquido); os valores nutricionais são por 100 g ou por 100 ml. Para líquidos da TACO (dada por 100 g), usa-se 1 ml ≈ 1 g (🟡 aproximação declarada; erro < 5% para leite, sucos e café). A quantidade registrada (`amount`) está sempre na medida do alimento; a medida caseira (`unit_label`/`unit_grams`) vira atalho e texto ("200 ml, 1 copo").
Onde: `EntryNutrition`, `PortionFormatter`.

**RN48 — Meta da refeição e situação**
Meta da refeição = soma da sugestão da refeição (RN24). Situação, só quando há registro:
- **calorias:** `below` se < 90% da meta; `ok` em [90%, 110%]; `above` se > 110%;
- **proteína:** `ok` se ≥ 90% da meta; senão `below`;
- **gordura:** `ok` se ≤ 110% da meta; senão `above`.
**Meta batida** (`goal_met`) = calorias ≥ 90% **e** proteína ≥ 90%. Passar das calorias ou da gordura **não** desfaz a meta batida ✅ D13 (os autores: "se passar de 110% ainda deve aceitar como completo") — vira só nota neutra.
Frase: meta batida → "Meta batida." + notas opcionais "{x} kcal acima da sugestão." / "{z} g de gordura acima da sugestão."; senão "Faltam {x} kcal" e/ou "{y} g de proteína" ("Faltam 162 kcal e 25 g de proteína."). Números inteiros. Carboidrato aparece na barra, sem julgamento.
Onde: `MealGoalStatus` (puro, back); `lib/registro.ts` espelha para a atualização otimista.

**RN49 — Retrato do registro**
Ao gravar (ou mudar a quantidade de) um registro, o backend guarda nome, medida, `amount` e os números calculados (kcal inteira, macros com 1 casa — mesmo arredondamento de RN24). Correções futuras do catálogo ou do alimento próprio **não** mudam registros já feitos.
Onde: `EntryService`, `EntryNutrition`.

**RN50 — Busca e alimentos próprios**
Busca = catálogo ativo (todos, inclusive fora de `in_plans`, RN52) + alimentos próprios do usuário; nome e sinônimos, sem maiúsculas/acentos; ordem: começa com o termo, contém o termo; empate: mais registrados pelo usuário em 30 dias, depois alfabética; até 20. "Recentes" = até 8 mais registrados em 30 dias. Alimento próprio (`custom_foods`) é privado, sem limite de quantidade, editável e apagável (exclusão lógica; registros já feitos ficam — RN49), e **nunca** entra em `FoodFilter` (plano, troca, Nutri).
Onde: `FoodSearch`, `CustomFoodService`.

**RN51 — Restrições no registro**
Registro é o que o usuário comeu: alimento ligado a restrição/alergia **pode** ser registrado; a busca e a folha mostram o rótulo da restrição e um aviso, nunca bloqueiam. RN17 continua valendo para tudo o que o app **sugere** (plano, troca, ação e sugestões do Nutri).
Onde: `FoodSearch` (`conflicts`), `EntryService` (não passa por `FoodFilter`).

**RN52 — Catálogo ampliado e `in_plans`**
O catálogo tem ≥ 700 alimentos: TACO 4ª ed. (NEPA/UNICAMP, ~600 itens) completada pela Tabela de Composição Nutricional dos Alimentos Consumidos no Brasil (IBGE, POF 2008–2009) e, para produtos industrializados comuns em academia, rótulo — sempre marcado em `source`. `foods.in_plans` diz quais entram em `FoodFilter` (geração, trocas, Nutri): `true` para os 62 atuais, `false` por padrão para os novos — o prompt do plano não cresce. Os autores podem promover alimentos para `in_plans` no CSV. Valores dos novos itens entram na conferência P5.
Onde: `foods.csv`, `FoodSeeder`, `FoodFilter`.

## Nutri

**RN28 — Conversas** ✅
- Antes de conversar, o usuário escolhe **continuar** uma conversa anterior ou **começar uma nova**.
- Ao pedir uma nova, se já existir uma conversa **sem mensagens**, ela é reaproveitada (evita lixo).
- Título = primeira pergunta, cortada em 60 caracteres na fronteira de palavra (+ "…").
- Lista ordenada por `last_message_at` desc; conversas sem mensagens não aparecem.
- Apagar remove a conversa, suas mensagens e seu resumo (sai da memória da IA).
Onde: `ConversationService`, `ConversationPolicy`.

**RN29 — Contexto enviado à IA** ✅🔵
Em toda resposta do Nutri, a IA recebe, nesta ordem:
1. *system prompt* versionado em código (nunca gravado como mensagem — corrige 🟤);
2. contexto do momento: nome preferido, objetivo e meta, metas e restantes do dia, próxima refeição com a sugestão, **o que foi registrado em cada refeição** (RN46; ✅ D13 — antes "refeições feitas"), restrições e alergias (alergias em destaque), itens da cozinha, lugar do almoço, horário de treino e se hoje é dia de treino;
3. memória: resumos das **3** conversas anteriores mais recentes que tenham resumo;
4. as **20** últimas mensagens da conversa atual.
O endpoint `/nutri/context` expõe a versão legível do item 2 para o card "O que estou olhando agora" 🔵.
Onde: `NutriContextBuilder`, `NutriChatService`.

**RN30 — Resumo das conversas (memória)** ✅
Quando o usuário abre uma **nova** conversa, é enfileirado o resumo da conversa anterior mais recente que tenha mensagens posteriores ao último resumo. Resumo: até 600 caracteres, em português, com fatos úteis (preferências declaradas, dificuldades, trocas feitas). Falha no resumo não afeta o usuário (tentado de novo na próxima vez).
Onde: `SummarizeConversationJob`, `nutri_conversations.summarized_message_id`.

**RN31 — Ações do Nutri** 🔵✅
- Tipos executáveis pelo backend: `substituir` (um alimento de uma refeição de hoje) e `aplicar-refeicao` (troca todos os itens de uma refeição de hoje). Tipos só de interface: `outra-opcao`, `ver-refeicao`, `dispensar`.
- O backend valida toda ação vinda da IA antes de exibi-la: slot existe hoje, alimento de origem está na refeição, alimentos de destino permitidos (RN16), porções recalculadas pelo backend (troca: RN25; refeição: 5–600 g/item, 1–6 itens). Ação inválida é **descartada** (a resposta fica só com texto); o descarte é registrado em log.
- Números dos cartões (kcal, carboidrato antes/depois, Δ kcal, macros da refeição) são **calculados pelo backend** a partir do catálogo — nunca pela IA (corrige o mock, que dividia macros igualmente entre itens).
- Refeição proposta com proteína mais de 5 g abaixo da original gera o aviso "Fica X g de proteína abaixo do {refeição} original." 🔵
- Uma ação só pode ser **resolvida** (aplicada ou dispensada) **uma vez** por mensagem (`409 ACTION_ALREADY_APPLIED`) e só **no mesmo dia** em que a mensagem foi criada (`409 ACTION_EXPIRED`); a refeição-alvo não pode estar feita (`409 MEAL_ALREADY_DONE`).
- Aplicar usa `DayService` (RN23, RN26, RN27 valem; `source = nutri`).
Onde: `NutriActionValidator`, `NutriActionService`.

**RN32 — Limites de conteúdo do Nutri** 🟡
Responde em português do Brasil, sobre alimentação dentro do plano. Não faz diagnóstico nem prescrição para doenças; em perguntas sobre condição de saúde (diabetes, gestação, transtorno alimentar, remédios) orienta procurar profissional de saúde. Não recomenda suplementos específicos nem dietas abaixo do piso do RN13. Mensagem do usuário: 1–1.000 caracteres.
Onde: system prompt (`integracao-ia.md`), `SendMessageRequest`.

**RN33 — Limites de uso de IA** 🟡 (controle de custo)
Chat: 20 mensagens/min e 100/dia por usuário. Geração de plano: RN19. Excedeu: `429` com `retry_after`.
Onde: `RateLimiter::for('nutri')`, `RateLimiter::for('plans')`.

**RN45 — Sugestões de continuação dinâmicas** ✅ D12 (substitui os chips fixos do mock 🔵)
- Toda resposta do Nutri traz de **0 a 3** sugestões de próxima pergunta, geradas pela IA **junto com a resposta** (mesma chamada, sem custo extra de requisição).
- São escritas como o **usuário** falaria ("E no jantar, o que como?"), em português, com 3 a 60 caracteres cada, ligadas ao assunto da resposta e ao plano de hoje.
- O backend filtra: remove vazias, duplicadas (sem diferenciar maiúsculas/acentos), iguais à pergunta que acabou de ser feita, acima de 60 caracteres, e qualquer uma que cite alimento **proibido** para o usuário (mesma normalização do RN16). Mantém no máximo 3.
- Sobrou nenhuma (IA não mandou, JSON inválido ou tudo filtrado) → usa **reserva determinística** do `SuggestionBuilder` a partir da próxima refeição de hoje (ex.: "E no {próxima refeição}?", "O que como antes do treino das {hora}?" em dia de treino, "Como fecho a proteína de hoje?"), também até 3.
- As sugestões ficam gravadas na mensagem; os chips mostrados são **sempre os da última resposta** do Nutri na conversa. Tocar num chip envia a pergunta como mensagem normal (conta no RN33).
- Não aparecem enquanto o Nutri está respondendo, nem depois de uma falha de envio.
Onde: `NutriResponse` (parse), `NutriChatService::sanitizeFollowUps`, `SuggestionBuilder::continuations`. FE: `SuggestionChips`.

## Evolução

**RN34 — Pesagens** 🔵
Uma pesagem por data por usuário: registrar de novo na mesma data **substitui** (upsert). Data padrão = hoje; não pode ser futura nem anterior a 30 dias. Peso 30,0–250,0 kg, 1 casa. Ao concluir o onboarding, o peso informado vira a primeira pesagem e `start_weight_kg`. **Peso atual** = pesagem mais recente.
Onde: `WeighInService`, índice único (`user_id`, `date`).

**RN35 — Previsão de chegada na meta** 🔵🟡 ("você chega na meta por volta do fim de janeiro")
Só com meta definida, ≥ 3 pesagens cobrindo ≥ 14 dias. Regressão linear (peso × dia) sobre as pesagens do período; se a inclinação aponta para a meta, data estimada = interseção, exibida como "início/meados/fim de {mês}"; horizonte máximo 52 semanas. Caso contrário, sem previsão (tela mostra "Registre mais uma pesagem…" ou mensagem neutra).
Onde: `WeightForecast` (puro).

**RN36 — Constância** 🔵
Status por dia: `completo` (todas as refeições do dia feitas — "feita" = com registro, RN46), `parcial` (≥ 1 feita), `vazio` (nenhuma feita ou dia não materializado), `hoje` (data corrente, qualquer estado). Janela: últimos 28 dias, incluindo hoje. **Sequência** = dias `completo` consecutivos terminando ontem (hoje é ignorado).
Onde: `AdherenceCalculator` (puro).

**RN37 — Médias do período** 🔵🟡
Período `6w` (42 dias), `3m` (91 dias), `all`. Médias diárias de kcal e proteína **consumidas** sobre os dias com ≥ 1 refeição feita, comparadas às metas do plano ativo. Observação automática quando a média de proteína nos dias sem treino for < 90% da meta e ao menos 10 pontos percentuais menor que nos dias de treino: "Você fica um pouco abaixo da meta de proteína nos dias sem treino." Sem dias com refeição feita: bloco em estado vazio.
Onde: `ProgressService`.

## Configurações e notificações

**RN38 — Notificações** 🔵✅
- Só são enviadas se o tipo estiver ligado **e** houver ao menos uma inscrição Web Push válida.
- **Lembrete de refeição**: 15 min antes de cada refeição de hoje **não feita** 🔵.
- **Resumo da semana**: domingo às 20:00 🔵 ("Todo domingo à noite").
- **Dicas do Nutri**: no máximo 2 por semana 🔵 (terça e sexta, 18:00), apenas quando uma regra de dica se aplica (ver spec 06).
- Nada é enviado antes de `wake_time` nem depois de `sleep_time`; nada antes do onboarding concluído; nunca a mesma notificação duas vezes (registro em `sent_notifications`).
- Inscrição que o serviço de push responde 404/410 é apagada.
- Padrão: lembrete ligado, resumo ligado, dicas desligadas 🔵.
Onde: comandos agendados + `Notifications/*` (canal WebPush).

**RN39 — Unidades** 🔵✅
A API sempre recebe e devolve unidades métricas (kg, cm, g, kcal). A preferência `unit_system` (`metric`/`imperial`) afeta só a exibição e a entrada no frontend (1 kg = 2,20462 lb; 1 in = 2,54 cm), com conversão de volta antes de enviar.
Onde: FE `lib/format.ts` + `lib/units.ts`; backend só armazena a preferência.

## Validação com a comunidade

**RN40 — Avaliações 👍/👎** 🟢✅
Avaliáveis: mensagens do **assistente** e planos, sempre do próprio usuário. Uma avaliação por usuário por item; avaliar de novo substitui (upsert); valor `up`/`down`; comentário opcional até 500 caracteres. Remover = `DELETE`.
Onde: `RatingController`, `RatingPolicy`, índice único (`user_id`, `rateable_type`, `rateable_id`).

**RN41 — Questionário de usabilidade** 🟢✅🟡
Uma resposta por usuário por **rodada** (`config('validacao.rodada')`). Convite (banner dispensável em Hoje) quando: onboarding concluído há ≥ 7 dias **ou** ≥ 10 refeições marcadas, e ainda não respondeu na rodada. Sempre acessível pelo Perfil. SUS: 10 itens Likert 1–5; escore = ((Σ ímpares − 5) + (25 − Σ pares)) × 2,5, calculado no servidor. Mais: utilidade das recomendações (1–5) e dois campos abertos (até 1.000 caracteres).
Onde: `UsabilityResponseController`, `SusScore` (puro).

**RN42 — Exportação anonimizada** ✅
`php artisan validacao:exportar` gera CSVs sem nome, e-mail ou texto de conversa; usuários identificados por `hash = substr(sha256(user_id . APP_KEY), 0, 12)`. Comentários livres das avaliações e do questionário vão como escritos pelo usuário (avisado no consentimento).
Onde: `ExportValidationData` (comando) + `ValidationExporter`.

## Segurança e dados

**RN44 — Segredos e prompts** 🟡 (corrige 🟤 — chave da IA e segredos JWT estavam no código)
Chave da IA, `APP_KEY` e chaves VAPID só em variáveis de ambiente. O system prompt e a resposta crua da IA nunca são devolvidos ao cliente. `ai_requests` registra propósito, modelo, tokens, duração e status — **não** o conteúdo.
Onde: `config/services.php`, `AiClient`, `AiRequestLogger`.
