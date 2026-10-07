# 09 — Registro alimentar: o que eu comi × a sugestão da refeição

> Substitui o modelo "marcar refeição como feita" da spec 03 (RF12 em parte e RF13 inteiro). Decisão D13 (2026-10-07), revisa D1.

## 1. Contexto e fontes
- 🟢 O documento pede que a IA "processe os **registros alimentares** dos usuários". Em D1 isso foi lido como "marcar refeição feita + trocas"; registro livre ficou para depois (`99-inconsistencias.md` I02).
- ✅ **D13 (autores, 2026-10-07):** isso estava errado. O "O que vai no prato" de hoje diz ao usuário o que ele **deve** comer e o "Marcar como feita" assume que ele comeu exatamente aquilo. O certo:
  1. **O que eu comi** é o registro: o usuário adiciona, com a quantidade em **g** (sólido) ou **ml** (líquido), os alimentos que de fato comeu, buscando no catálogo.
  2. O app soma e mostra se ele **bateu a meta** da refeição em **calorias, proteína e gordura**.
  3. O plano vira **sugestão**: o prato que montamos para bater a meta, com um **"+" pequeno** em cada alimento para registrá-lo sem digitar nada. É um atalho, não uma ordem.
  4. **Não existe mais "Marcar como feita".** A refeição conta como feita quando tem pelo menos um alimento registrado.
  5. Registro vale para **hoje e ontem**.
  6. Alimento ligado a alergia/restrição **aparece na busca, com aviso** (registro é o que aconteceu, não recomendação).
  7. Alimento fora do catálogo: o usuário **cadastra manualmente** (só para ele). E o **catálogo precisa crescer** — 62 itens é pouco.
- 🔵 Identidade visual do mock e design system da spec 08 (D11): tokens de `globals.css`, Bricolage + Instrument Sans, movimento generoso.
- Regras novas: RN46–RN52. Regras revisadas: RN22, RN23, RN24, RN26, RN29 (ver `00-fundacao/regras-de-negocio.md`).

## 2. Requisitos funcionais

### RF31 — Ver a refeição: registro, meta e sugestão
**Ator:** usuário. **Pré-condição:** plano ativo; data = hoje ou ontem.
**Fluxo:** Hoje/Dieta → toca a refeição → `/dieta/{slot}` (hoje) ou `/dieta/{slot}?data=AAAA-MM-DD` (a data de ontem, que a Dieta conhece) → vê:
1. **Meta da refeição** — kcal registradas de {meta} kcal, barras de proteína, gordura e carboidrato (registrado × meta da refeição) e uma frase de situação (RN48).
2. **O que você comeu** — os alimentos registrados (nome, quantidade em g/ml com medida caseira, kcal e macros), cada um editável; botão **"Adicionar alimento"**.
3. **Sugestão para bater a meta** — os itens sugeridos (o antigo "O que vai no prato"): nome, quantidade, kcal; botão **"+"** por item; "Trocar" por item (só hoje e só nos itens ainda não registrados, RF14/RN26); atalho **"Adicionar os {n}"** (registra de uma vez todos os itens ainda não registrados) sempre que restarem 2 ou mais — os dois jeitos convivem: tudo de uma vez ou item a item ✅ D13.
**Regras:** RN46, RN47, RN48.

### RF32 — Registrar um alimento da sugestão ("+")
**Fluxo:** toca "+" no item sugerido → o alimento entra em "O que você comeu" com a quantidade sugerida (otimista) → a meta recalcula → o "+" do item vira um ✓ ("Já registrado"). Tocar no ✓ não registra de novo; para mudar a quantidade, edita o registro (RF34).
**Alternativo:** "Adicionar os {n}" registra todos os itens sugeridos ainda não registrados, numa única requisição.
**Erro:** falha de rede → o item some de "O que você comeu", o "+" volta e o toast "Não foi possível salvar. Tente de novo." aparece.

### RF33 — Registrar um alimento buscando no catálogo
**Fluxo:** "Adicionar alimento" → folha **Adicionar alimento** com campo de busca focado.
1. Campo vazio: mostra "Você costuma comer" (até 8 alimentos mais registrados pelo usuário nos últimos 30 dias, RN50) e, se não houver, os itens da sugestão.
2. Digita (≥ 2 letras) → resultados do catálogo **e** dos alimentos cadastrados pelo usuário (RN50), com kcal por 100 g/ml e selo "Alergia"/"Restrição" quando for o caso.
3. Toca um resultado → passo **Quanto você comeu?**: número em g ou ml conforme o alimento (RN47), atalhos de medida caseira ("1 concha · 80 g", "½ porção", "1 porção", "2 porções"), prévia ao vivo de kcal/proteína/carboidrato/gordura, e, se for alimento ligado a uma restrição do usuário, o aviso "Este alimento tem {restrição}, que está nas suas restrições." (não bloqueia).
4. "Adicionar" → registro salvo → folha fecha → toast "{Alimento} registrado".
**Alternativos:** nenhum resultado → "Não achamos "{termo}"." + "Cadastrar alimento" (RF35) + "Perguntar ao Nutri o que mais se parece" (`/nutri?pergunta=…`).
**Regras:** RN47, RN49, RN50.

### RF34 — Editar ou remover um registro
**Fluxo:** toca um alimento em "O que você comeu" → a mesma folha, no passo "Quanto você comeu?", com a quantidade atual → "Salvar" (muda a quantidade) ou "Remover" → a meta recalcula → ao remover, toast "{Alimento} removido" com **"Desfazer"** (o cliente registra de novo com o mesmo alimento — ou o mesmo `suggestion_item_id` — e a mesma quantidade; não usa RN27).

### RF35 — Cadastrar alimento próprio
**Fluxo:** "Cadastrar alimento" (na busca sem resultado, ou no fim da lista) → formulário: nome (pré-preenchido com o termo buscado), "Sólido (g)" / "Líquido (ml)", e por 100 g/ml: kcal, proteína, carboidrato, gordura; dica "Copie da tabela nutricional da embalagem." → "Salvar e continuar" → vai direto para "Quanto você comeu?" com esse alimento.
**Validações:** §6. O alimento fica só para esse usuário, aparece na busca dele com o selo "Seu" e **nunca** entra no plano, em trocas ou em ações do Nutri (RN50).
**Sem limite de quantidade** ✅ D13.

### RF37 — Editar ou apagar alimento próprio ✅ D13
**Fluxo:** na busca, o alimento com selo "Seu" tem a ação "Editar" (no passo "Quanto você comeu?", link "Editar alimento") → mesmo formulário do RF35 → "Salvar" ou "Apagar alimento" (confirmação: "Apagar {nome}? O que você já registrou com ele continua no histórico.").
**Efeitos:** editar muda só os **próximos** registros e as próximas edições de quantidade (RN49: registros já feitos guardam os números do momento); apagar tira o alimento da busca e dos recentes, e os registros já feitos ficam intactos (exclusão lógica).

### RF36 — Registrar ontem
**Fluxo:** Dieta → toca "ontem" na faixa da semana → as refeições de ontem ficam tocáveis → abre `/dieta/{slot}?data=AAAA-MM-DD` (a data de ontem, que a Dieta conhece) → mesma tela (título "Ontem às {hora}"), sem "Trocar".
**Regras:** RN23 (revisada), RN22 (revisada).

### RF13 (spec 03) — removido
O marcador da linha do dia (Hoje) e o botão "Marcar como feita"/"Desmarcar refeição" deixam de existir. `PATCH /days/{date}/meals/{slot}` é removido.

## 3. Fluxos

```
[Ver]       /dieta/{slot}[?data=AAAA-MM-DD] ─▶ GET /days/{today|AAAA-MM-DD} ─▶ meta + registros + sugestão
[+]         "+" ─▶ POST /days/{d}/meals/{slot}/entries {entries:[{suggestion_item_id}]} (otimista) ─▶ dia completo
[Todos]     "Adicionar os n" ─▶ POST …/entries {entries:[{suggestion_item_id}…]} ─▶ dia completo
[Buscar]    "Adicionar alimento" ─▶ GET /foods/recent ─▶ digita ─▶ GET /foods?q= (debounce 250 ms)
            ─▶ escolhe ─▶ quantidade ─▶ POST …/entries {entries:[{food_id|custom_food_id, amount}]} ─▶ dia completo
[Cadastrar] sem resultado ─▶ "Cadastrar alimento" ─▶ POST /custom-foods ─▶ quantidade ─▶ POST …/entries
[Próprio]   "Editar alimento" ─▶ PATCH /custom-foods/{id} | "Apagar alimento" ─▶ DELETE /custom-foods/{id}
[Editar]    toca registro ─▶ PATCH /days/{d}/entries/{id} {amount} ─▶ dia completo
[Remover]   ─▶ DELETE /days/{d}/entries/{id} ─▶ dia completo ─▶ toast "Desfazer" ─▶ POST …/entries (mesmo alimento e quantidade)
```

## 4. Telas ↔ backend

### S13 — Detalhe da refeição (`/dieta/[slot]` · `?data=AAAA-MM-DD`) — reescrita
Desenho (mobile, 390 px; coluna única, alinhada à esquerda):

```
‹                                   [Próxima refeição]
Pré-treino                                        ← Bricolage 34/700
Hoje às 17:30 · antes do treino das 19h

┌─ papel-branco, raio 24 ───────────────────────┐
│  288 kcal                       meta 450       │ ← CountUp grande + meta à direita
│  ▕██████████████░░░░░░▒▒▒▒░░▏                  │ ← "régua da refeição": faixa ▒ = ±10% da meta
│  Faltam 162 kcal e 25 g de proteína.           │ ← frase de situação (RN48)
│  Proteína    0 / 25 g  ░░░░░░░░░ │            │
│  Gordura     0 / 12 g  ░░░░░░░░░ │            │ ← traço vertical = meta
│  Carboidr.  72 / 60 g  ▬▬▬▬▬▬▬▬▬▬▬│▬         │
└────────────────────────────────────────────────┘

O que você comeu
┌───────────────────────────────────────────────┐
│ Tapioca                          288 kcal   › │
│ 120 g, mais ou menos 2 tapiocas médias        │
└───────────────────────────────────────────────┘
[ ＋ Adicionar alimento ]                         ← botão contornado, largura total

Sugestão para bater a meta                        ← fundo papel, sem cartão branco
Montamos com o que você tem em casa.
 Tapioca     60 g · 144 kcal         ✓            ← já registrado
 Maçã       130 g ·  68 kcal   Trocar  (+)
 Frango desfiado 60 g · 95 kcal Trocar  (+)
 [ Adicionar os 2 ]                                ← botão secundário (aparece com ≥ 2 itens não registrados)

( • Não tenho frango desfiado em casa        › )   ← NutriBar (como hoje)
```

- **O elemento memorável é a "régua da refeição":** uma barra larga em que a faixa de acerto (±10% da meta, RN48) aparece como uma zona hachurada; o preenchimento cresce com mola a cada registro e muda de cor pela situação: `gema` abaixo da faixa, `mata` quando a meta foi batida; o que passar de 110% aparece em `mata-media` depois da faixa (mostra o excesso sem tom de erro — passar das calorias **não** desfaz a meta batida, RN48). Todo o resto da tela fica quieto: sem cartões repetidos, a sugestão é tipograficamente secundária (texto `fumo`, sem fundo branco), o registro é o cartão branco.
- **Movimento:** o item registrado entra em "O que você comeu" deslizando de onde estava o "+" (FLIP com `transform`); o "+" vira ✓ com `pop`; a régua anima a largura (`transition-[width]` 600 ms, mola). Com `prefers-reduced-motion`, tudo muda sem animação.
- **Estados:** carregando (`Skeleton` da régua + 3 linhas); erro (`ErrorState`); slot inválido ("Refeição não encontrada"); nada registrado (`EmptyState` compacto dentro de "O que você comeu": "Nada registrado ainda. Toque em + numa sugestão ou adicione o que você comeu." — sem ilustração); refeição sem sugestão (plano sem itens para o slot — não deve ocorrer) → esconde a seção; data fora de hoje/ontem → somente leitura (sem "+", sem "Adicionar", sem "›").
- **Acessibilidade:** "+" é `button` com nome "Registrar {alimento}, {quantidade}" e alvo de 44 px (o círculo visível tem 30 px); ✓ é `button` desabilitado com nome "{alimento} já registrado"; a régua é `role="meter"` com `aria-valuenow/min/max` e `aria-valuetext` = frase de situação; mudanças da frase vão para uma região `aria-live="polite"`.
- **Dados:** `GET /days/{today|data}`; a refeição é filtrada por `slot`.

### S13a — Folha "Adicionar alimento" (novo, `Sheet` da spec 08)
- **Passo 1 — Buscar:** `input type="search"` "Buscar alimento" (foco ao abrir), lista `listbox` de resultados (nome, "{kcal} kcal em 100 g|ml", selos "Alergia" `alerta`/"Restrição" `gema`/"Seu" `mata`); vazio: "Você costuma comer" (`GET /foods/recent`); sem resultado: texto + "Cadastrar alimento" + "Perguntar ao Nutri". Setas navegam, `Enter` escolhe.
- **Passo 2 — Quanto você comeu?:** nome do alimento; campo numérico grande (`inputmode="decimal"`, sufixo "g" ou "ml"); atalhos (`Chip`) de medida caseira e porções; prévia de kcal e macros (atualiza ao digitar); aviso de restrição (`Aviso tom="alerta"`) quando houver; "Adicionar" (ou "Salvar"/"Remover" na edição); "Voltar" para a busca.
- **Passo 3 — Cadastrar alimento:** formulário (§6), "Salvar e continuar".
- **Erros:** 422 → mensagens no campo; rede → `FormError` na folha, nada fecha.

### S11 — Hoje (ajustes)
- **Linha do dia (`DayRail`):** sai o botão ✓ "Marcar … como feita". A refeição da vez mostra "{registrado} de {meta} kcal" e o botão "Registrar refeição" (→ `/dieta/{slot}`); linhas compactas mostram ✓ quando a refeição tem registro (RN46) e "{registrado} kcal" no lugar da meta.
- **Metas de hoje:** consumido = soma dos registros (RN24 revisada). Sem outra mudança.

### S12 — Dieta (ajustes)
- Hoje e **ontem** tocáveis (RN23 revisada); `MealRow` mostra "{registrado} de {meta} kcal" em hoje/ontem e só a meta no futuro.
- Rodapé: "Registre o que você comeu. A sugestão de cada refeição é um atalho para bater a meta."

## 5. API

Mudanças em relação à spec 03: **removido** `PATCH /days/{date}/meals/{slot}`; **alterado** o formato do dia; **novos** endpoints de registro, busca e alimento próprio. Todos exigem sessão e onboarding concluído; posse por `user_id` (404 se não for do usuário).

### `GET /api/v1/days/{date}` — formato novo
```json
{ "data": {
  "date": "2026-10-07", "is_today": true, "editable": true, "materialized": true, "is_training_day": true,
  "targets": { "kcal": 2200, "protein_g": 115, "carbs_g": 300, "fat_g": 60 },
  "totals": {
    "planned":  { "calories": 2198, "protein": 139.0, "carbs": 297.0, "fat": 49.6 },
    "consumed": { "calories": 761,  "protein": 45.2,  "carbs": 73.1,  "fat": 33.0 },
    "remaining":{ "calories": 1437, "protein": 93.8,  "carbs": 223.9, "fat": 16.6 }
  },
  "meals": [
    { "id": 901, "slot": "pre-treino", "name": "Pré-treino", "time": "17:30", "note": null, "position": 4,
      "done": true, "is_next": true,
      "summary": "Tapioca, maçã e frango desfiado",
      "calories": 450, "macros": { "protein": 25.0, "carbs": 60.0, "fat": 12.0 },
      "consumed": { "calories": 288, "protein": 0.0, "carbs": 72.0, "fat": 0.0 },
      "status": { "calories": "below", "protein": "below", "fat": "ok" }, "goal_met": false,
      "items": [
        { "id": 5501, "food_id": 31, "name": "Tapioca", "grams": 60.0, "measure": "g", "amount": "60 g, mais ou menos 1 tapioca média",
          "calories": 144, "macros": { "protein": 0.0, "carbs": 36.0, "fat": 0.0 },
          "source": "plan", "replaced_from": null, "registered": true }
      ],
      "entries": [
        { "id": 7001, "food_id": 31, "custom_food_id": null, "suggestion_item_id": 5501, "name": "Tapioca",
          "amount": 120.0, "measure": "g", "amount_text": "120 g, mais ou menos 2 tapiocas médias",
          "calories": 288, "macros": { "protein": 0.0, "carbs": 72.0, "fat": 0.0 },
          "conflicts": [] }
      ] }
  ],
  "last_change": null
} }
```
- **Compatível com o formato da spec 03:** nenhum campo some; os nomes ficam e o sentido muda (D13):
  - `items` = a **sugestão** da refeição; cada item ganha `registered` (existe registro com `suggestion_item_id` desse item) e `measure`;
  - `calories`/`macros` da refeição = a **meta** da refeição (soma da sugestão, RN48);
  - `done` = a refeição tem pelo menos um registro (RN46);
  - novos: `entries` (o que foi comido), `consumed` (soma de `entries`, RN24), `status` e `goal_met` (RN48).
- `editable` = data é hoje ou ontem (RN23). Ontem não materializado vem com `materialized: false` e a sugestão em prévia; a primeira escrita materializa (RN22).
- `status`: por RN48 — `calories`: `below` · `ok` · `above`; `protein`: `below` · `ok`; `fat`: `ok` · `above`; `null` quando nada foi registrado. `goal_met` (bool) acompanha `status`.

### `POST /api/v1/days/{date}/meals/{slot}/entries`
- **Request:** `{ "entries": [ { "suggestion_item_id": 5501 } | { "food_id": 31, "amount": 120 } | { "custom_food_id": 4, "amount": 200 } ] }` — 1 a 10 itens.
- **Response 201:** o dia completo.
- **Efeitos:** grava `meal_entries` com o retrato dos números (RN49); `suggestion_item_id` copia alimento e gramas do item sugerido; atualiza `day_meals.done_at` (RN46).
- **Erros:** 409 `DAY_NOT_EDITABLE`; 404 slot/sugestão/alimento próprio de outro usuário; 422 `VALIDATION_ERROR` (quantidade, alimento inativo, item sugerido de outra refeição); 409 `ALREADY_REGISTERED` (item sugerido já registrado nessa refeição).

### `PATCH /api/v1/days/{date}/entries/{entry}`
- **Request:** `{ "amount": 150 }` · **Response 200:** o dia completo. Recalcula o retrato com os valores atuais do alimento.
- **Erros:** 409 `DAY_NOT_EDITABLE`; 404; 422.

### `DELETE /api/v1/days/{date}/entries/{entry}`
- **Response 200:** o dia completo. Último registro removido ⇒ `done_at = null`.
- **Erros:** 409 `DAY_NOT_EDITABLE`; 404.

### `GET /api/v1/foods?q={termo}&limit=20`
- **Busca (RN50):** catálogo ativo + alimentos próprios do usuário; `q` ≥ 2 caracteres, sem diferenciar maiúsculas/acentos, em `name` e `aliases`; ordem: começa com o termo → contém o termo; dentro disso, os que o usuário mais registrou, depois alfabética.
- **Response 200:**
```json
{ "data": [
  { "id": 12, "kind": "catalog", "name": "Leite integral", "measure": "ml", "group": "laticinio",
    "per_100": { "calories": 61, "protein": 2.9, "carbs": 4.3, "fat": 3.2 },
    "portion": { "amount": 200, "text": "1 copo · 200 ml" }, "household": { "label": "copo", "label_plural": "copos", "amount": 200 },
    "conflicts": ["Intolerância à lactose"] },
  { "id": 4, "kind": "custom", "name": "Barra de proteína da academia", "measure": "g", "group": null,
    "per_100": { "calories": 380, "protein": 30.0, "carbs": 35.0, "fat": 12.0 },
    "portion": null, "household": null, "conflicts": [] }
] }
```
- `conflicts`: rótulos das restrições/alergias do usuário ligadas ao alimento (mesma lógica do RN16); vazio para alimento próprio.
- **Erros:** 422 `q` curto. **Throttle:** `search` (60/min).

### `GET /api/v1/foods/recent`
- Até 8 alimentos (catálogo ou próprios) mais registrados pelo usuário nos últimos 30 dias, mesmo formato de `/foods`, com `"last_amount"` (a última quantidade registrada).

### `POST /api/v1/custom-foods`
- **Request:** `{ "name": "Barra de proteína da academia", "measure": "g", "per_100": { "calories": 380, "protein": 30, "carbs": 35, "fat": 12 } }`
- **Response 201:** o alimento no formato de `/foods` (`kind: "custom"`).
- **Erros:** 422 (§6).

### `PATCH /api/v1/custom-foods/{customFood}`
- **Request:** mesmos campos do `POST` (todos opcionais). **Response 200:** o alimento. **Erros:** 404 (outro usuário ou apagado); 422.

### `DELETE /api/v1/custom-foods/{customFood}`
- **Response 204.** Exclusão lógica (`deleted_at`): some da busca e dos recentes; registros já feitos ficam. **Erros:** 404.

## 6. Validações
| Item | Regra |
|---|---|
| `{date}` (escrita) | hoje ou ontem no fuso `America/Sao_Paulo` (RN23); senão 409 `DAY_NOT_EDITABLE` |
| `entries` | array 1–10; cada item tem **exatamente um** de `suggestion_item_id`, `food_id`, `custom_food_id` |
| `amount` | número > 0 e ≤ 2000, até 1 casa; obrigatório com `food_id`/`custom_food_id`; opcional com `suggestion_item_id` (padrão = gramas sugeridas — o "Desfazer" da remoção usa isso para devolver o ✓) |
| `food_id` | alimento do catálogo **ativo** (pode ter conflito com restrição — RN51) |
| `custom_food_id` | alimento próprio do usuário |
| `suggestion_item_id` | item da sugestão **da mesma refeição**, ainda não registrado |
| `name` (alimento próprio) | 2–60 caracteres, sem só espaços; único por usuário sem diferenciar maiúsculas/acentos |
| `measure` | `g` ou `ml` |
| `per_100.calories` | 0–900 |
| `per_100.protein/carbs/fat` | 0–100 cada; soma ≤ 100 |
| coerência | 4·proteína + 4·carboidrato + 9·gordura ≤ kcal × 1,25 + 20 (pega vírgula no lugar errado: "Os números não batem: confira as calorias.") |

## 7. Critérios de aceitação
- **CA31** Dado o pré-treino com sugestão de 450 kcal e nada registrado, quando toca "+" na tapioca (60 g), então "O que você comeu" mostra a tapioca com 60 g, a régua mostra 144 de 450 kcal, o "+" vira ✓ e o estado persiste ao recarregar.
- **CA32** Dado nada registrado, quando toca "Adicionar os 3", então os 3 itens sugeridos entram com as gramas sugeridas em uma requisição e a refeição aparece com ✓ na linha do dia.
- **CA33** Dado a busca "leite", quando escolhe "Leite integral" e informa 200 ml, então o registro mostra "200 ml" e 122 kcal.
- **CA34** Dado intolerância à lactose, quando busca "leite", então o resultado aparece com o selo "Restrição", o passo de quantidade mostra o aviso, e o registro é aceito; a sugestão e as trocas continuam sem leite.
- **CA35** Dado a busca "barra de cereal caseira" sem resultado, quando cadastra o alimento (g; 380/30/35/12) e informa 40 g, então o registro mostra 152 kcal, e o alimento aparece na próxima busca do mesmo usuário com o selo "Seu" e nunca na de outro usuário.
- **CA36** Dado kcal 38 e proteína 30, carboidrato 35, gordura 12 no cadastro, então a API responde 422 "Os números não batem: confira as calorias."
- **CA37** Dado registros que somam 430 kcal numa refeição de meta 450 e proteína 24 de 25 g, e gordura 11 de 12 g, então a situação é "Meta batida." e a régua fica `mata`.
- **CA37b** Dado 560 kcal registradas numa meta de 450 (124%) e proteína ≥ 90%, então a situação continua "Meta batida." com a nota "110 kcal acima da sugestão." e a régua mostra o excesso em `mata-media`, sem cor de erro.
- **CA45** Dado um alimento próprio, quando o usuário muda as kcal dele, então os registros antigos mantêm os números e o próximo registro usa os novos; quando apaga, ele some da busca e os registros antigos continuam.
- **CA38** Dado um registro de 120 g, quando muda para 150 g, então kcal e macros da refeição e do dia recalculam; quando remove e toca "Desfazer", o registro volta com 150 g.
- **CA39** Dado ontem, quando abre a Dieta e registra o jantar de ontem, então o registro é salvo em ontem, e antes de ontem continua somente leitura (`DAY_NOT_EDITABLE`).
- **CA40** Dado uma refeição com registro e outra sem, então a constância (spec 05), o lembrete (spec 06) e o resumo semanal tratam a primeira como feita e a segunda como não feita.
- **CA41** Dado o almoço registrado com 300 kcal diferentes da sugestão, então "Metas de hoje" e o contexto do Nutri usam os 300 kcal registrados, não a sugestão.
- **CA42** Dado um usuário B, quando tenta `PATCH /days/today/entries/{registro do A}` ou registrar com `custom_food_id` do A, então recebe 404.
- **CA43** Dado o catálogo ampliado, então há pelo menos 700 alimentos ativos, todos com `measure`, porção de referência e fonte; os 62 atuais continuam com o mesmo `slug`.
- **CA44** Dado dados antigos com refeições marcadas como feitas antes da mudança, quando a migração roda, então cada refeição feita ganha registros iguais aos itens que tinha, e os totais consumidos do passado não mudam.

## 8. Test Strategy

### Component Tests (Storybook + play)
- `MealGoal` (régua) — Vazia, Abaixo, NaFaixa, Acima, SemMeta; `role="meter"` com `aria-valuetext`; reduced-motion.
- `EntryList` / `EntryRow` — Vazia (EmptyState compacto), ComItens, SomenteLeitura (sem "›"); clique abre edição.
- `SuggestionList` / `SuggestionRow` — Nenhum registrado ("Adicionar os n"), UmRegistrado (✓ e "Adicionar os n" com o restante), FaltaUm (sem "Adicionar os n"), Ontem (sem "Trocar"), SomenteLeitura; "+" chama `aoRegistrar(item)`; nome acessível.
- `AddFoodSheet` — Recentes, Buscando, Resultados (selos), SemResultado, Quantidade (g e ml, atalhos, prévia), AvisoRestricao, Edicao (Salvar/Remover), Cadastro (erros por campo), EditarAlimentoProprio (Salvar/Apagar com confirmação); foco e teclado no `listbox`.
- `DayRail` — sem botão ✓; "Registrar refeição"; linhas com ✓ por registro.

### Unit Tests
- Back: `MealGoalStatusTest` (RN48 nas bordas: 405/450 batida, 404 below, 496 batida com excesso; proteína 90%; gordura acima só gera nota); `EntryNutritionTest` (g e ml; arredondamento; retrato); `FoodSearchTest` (acentos, sinônimos, ordem começa/contém, recentes primeiro, alimento próprio só do dono, inativo fora); `CustomFoodRulesTest` (§6, coerência); `DayTotals` com registros.
- Front: `lib/registro.ts` (status RN48 espelhado para otimismo; texto da situação; atalhos de medida caseira a partir de `household`/`portion`).

### Integration Tests
- Back: `EntriesTest` (criar por sugestão/catálogo/próprio; lote; `ALREADY_REGISTERED`; editar; remover; `done_at`; hoje e ontem; anteontem 409; posse 404; ontem não materializado materializa na primeira escrita); `FoodSearchEndpointTest`; `CustomFoodsTest` (criar, editar, apagar lógico; nomes iguais; posse; edição não muda registros antigos); `DayResourceShapeTest` (formato novo); `ConsumedFromEntriesTest` (Progress, WeeklySummary, TipSelector, NutriContext e exportação usam registros); `MigrateDoneMealsToEntriesTest` (CA44); `CatalogSeederTest` (≥ 700, `measure`, slugs antigos preservados, `in_plans`).
- Front (MSW): Detalhe — "+" otimista e reversão em erro; "Adicionar os n"; busca → quantidade → adicionar; sem resultado → cadastrar → quantidade; editar e remover com "Desfazer"; ontem sem "Trocar"; Hoje sem botão ✓; Dieta com ontem tocável.

### E2E Tests
- **E2E-04 (reescrito):** registrar pela sugestão e pela busca; recarregar mantém.
- **E2E-08 (novo):** cadastrar alimento próprio e registrar; outro usuário não vê.
- **E2E-09 (novo):** registrar o jantar de ontem.
- E2E-05/E2E-06 continuam (troca e alergia na **sugestão**).

## 9. Definition of Done
```
[ ] CA31–CA44 atendidos
[x] Migrations: meal_entries, custom_foods (com deleted_at), foods.measure/in_plans, day_meals.done_at com o novo sentido (dados antigos convertidos)
[x] Catálogo ≥ 700 alimentos (TACO 4ª ed. + Tabela de Composição Nutricional do IBGE/POF 2008–2009), P5 atualizado para conferência
[x] Endpoints de registro, busca, recentes e alimento próprio; PATCH meals removido; Policies; feature tests com posse
[x] Consumido vem dos registros em Hoje, Evolução, Nutri, notificações e exportação
[ ] Detalhe reescrito (régua, registros, sugestão com "+"), folha Adicionar alimento, Hoje e Dieta ajustados
[ ] Componentes novos com stories e play; axe limpo; teclado na folha
[ ] E2E-04 reescrito, E2E-08 e E2E-09 verdes em Chromium e WebKit
[x] Specs 03, 04, 05, 06 e 07 atualizadas onde citam "feita"
```
