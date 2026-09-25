# 04 — Nutri: assistente de IA com conversas, contexto, memória e ações

## 1. Contexto e fontes
- 🟢 "IA generativa para gerar recomendações… processar os registros alimentares dos usuários e produzir sugestões de melhoria personalizadas".
- 🟤 Node: `chats` (vários por usuário) + `messages` (`role`, `content`); no cadastro cria chat "Boas vindas" com mensagem `system` ("You are a nutricionist called NutriTodos…") e uma saudação; `POST /messages/:chat_id` grava a pergunta, envia **todo** o histórico à IA (`gpt-4-turbo`, 512 tokens), grava a resposta e devolve `201` **sem corpo**. Sem verificação de dono.
- 🔵 Tela `/nutri`: estado inicial com "O que estou olhando agora" (contexto) e "Perguntas que cabem agora"; bolhas; indicador "O Nutri está montando a resposta"; cartões de troca e de refeição; ações (substituir, aplicar, outra opção, ver refeição, dispensar); falha offline com "Tentar de novo"; chips de continuação.
- ✅ D9: antes de abrir o chat, o usuário escolhe **continuar** uma conversa ou **começar nova**; pode voltar ao histórico das últimas conversas; a IA recebe contexto das conversas passadas.
- ✅ D12: sugestões de continuação geradas pela IA a cada resposta, em vez dos chips fixos do mock.
- Regras: RN28–RN33, RN45, RN17, RN23, RN26, RN27. IA: `00-fundacao/integracao-ia.md` §4–5.

## 2. Requisitos funcionais

### RF19 — Conversas
**Atores:** usuário com onboarding concluído.
**Fluxo principal:**
1. O usuário toca num atalho do Nutri (NutriBar em Hoje/Detalhe/Perfil, botão "Nutri" na Dieta, "Ajustar alguma coisa com o Nutri" no Pronto, "Perguntar ao Nutri" na folha de troca vazia).
2. Abre a tela **Conversas** (`/nutri`), com "Nova conversa" em destaque e a lista das conversas anteriores (título, data relativa, prévia da última mensagem).
3. Escolhe "Nova conversa" ou uma conversa da lista.
4. Abre o chat (`/nutri/{id}`); se veio de um atalho com pergunta, ela chega **pré-preenchida no campo** (não é enviada sozinha).
**Alternativos:**
- Nenhuma conversa anterior → pula a lista e abre direto uma conversa nova.
- Apagar conversa: na lista, botão "Apagar" da linha → confirmação → some da lista e da memória.
- Lista longa → carrega mais ao rolar (cursor).
**Regras:** RN28.

### RF20 — Perguntar ao Nutri
**Fluxo principal:**
1. No chat, digita a pergunta (ou toca numa sugestão) e envia.
2. A pergunta aparece na hora (`enviando`); o indicador de "montando a resposta" aparece.
3. O backend monta o contexto (RN29), chama a IA, valida a ação (RN31) e devolve a resposta.
4. A resposta aparece com texto, cartão (troca ou refeição) e ações, quando houver; botões 👍/👎 (spec 07).
5. Abaixo da conversa surgem até 3 **sugestões de continuação** criadas para aquela resposta (RN45); tocar numa delas envia a pergunta.
**Alternativos:**
- Sem internet (navegador `offline`) → pergunta marcada "Não enviada · Tentar de novo" e aviso "Sua pergunta não saiu daqui…" 🔵.
- IA indisponível (503) → mesmo tratamento de "Não enviada".
- 429 → "Muitas perguntas seguidas. Tente de novo em {n} segundos."
**Regras:** RN29, RN32, RN33.

### RF21 — Aplicar ação sugerida
**Fluxo principal:**
1. Na resposta com cartão de troca, toca "Substituir no {refeição} de hoje" (ou, no cartão de refeição, "Aplicar no {refeição} de hoje").
2. O backend valida e aplica no dia de hoje (mesmas regras da troca manual).
3. As ações da mensagem somem; aparece a confirmação do Nutri "Feito. Seu {refeição} de hoje vai com {alimento}." com "Ver a refeição" 🔵; toast com "Desfazer" (RN27).
**Outras ações:** "Ver outras opções"/"Gerar outra opção" enviam uma nova pergunta ("Quero ver outras opções" / "Monte outra opção de {refeição}") 🔵; "Ver o {refeição}" navega para `/dieta/{slot}`; "Agora não" dispensa (as ações somem e continuam sumidas ao recarregar).
**Alternativos:** refeição já feita → "Esse {refeição} já está marcado como feito. Desmarque para trocar."; ação de outro dia → "Essa sugestão era para {data}."; já aplicada → ações já não aparecem.
**Regras:** RN17, RN23, RN26, RN27, RN31.

### RF22 — Memória entre conversas
**Descrição:** ao começar uma conversa nova, a conversa anterior é resumida em segundo plano; as próximas respostas do Nutri recebem os resumos das 3 conversas anteriores mais recentes.
**Regras:** RN29, RN30.

## 3. Fluxos

```
Atalho (NutriBar / Dieta / Pronto / folha vazia) ─▶ /nutri?pergunta={texto opcional}
    ├─ sem conversas anteriores ─▶ POST /conversations ─▶ /nutri/{id}?pergunta=…
    └─ com conversas ─▶ lista
          ├─ "Nova conversa" ─▶ POST /conversations (reaproveita vazia; enfileira resumo da anterior) ─▶ /nutri/{id}?pergunta=…
          ├─ toca conversa ─▶ /nutri/{id}?pergunta=…  (GET messages, mais recentes primeiro)
          └─ "Apagar" ─▶ confirmação ─▶ DELETE /conversations/{id}

/nutri/{id} ─▶ enviar ─▶ POST /conversations/{id}/messages ─┬─ 201 ─▶ resposta (+ cartão + ações)
                                                            ├─ 503/offline ─▶ "Não enviada · Tentar de novo"
                                                            └─ 429 ─▶ mensagem com tempo
             ação executável ─▶ POST /messages/{id}/actions/{i} ─▶ confirmação + toast Desfazer (POST /days/today/undo)
             ação "outra opção" ─▶ nova pergunta
             ação "ver refeição" ─▶ /dieta/{slot}
             ação "dispensar" ─▶ POST /messages/{id}/actions/{i} (registra dispensa)
             voltar ─▶ /nutri (lista)
```

## 4. Telas ↔ backend

### N05 — Conversas (`/nutri`) ✅ nova
- **Objetivo:** escolher entre continuar uma conversa ou começar outra.
- **Wireframe:**
  ```
  TopBar ← Voltar                              MarcaNutri
  [título]   Conversas com o Nutri
  [texto]    Continue de onde parou ou comece um assunto novo. O Nutri lembra do que vocês já conversaram.
  Button primária grande  [+] Nova conversa
  ─ "Recentes" ─
  ConversationListItem  "Posso trocar o arroz por batata?"   ontem · 6 mensagens
                        Prévia: "Pode. No seu almoço os 150 g…"          [Apagar]
  ConversationListItem  …
  (carregar mais ao rolar)
  ```
  Se chegou com `?pergunta=`, mostra acima da lista um cartão "Sua pergunta: '{pergunta}'. Escolha onde perguntar." 🟡.
- **Endpoints:** `GET /conversations`, `POST /conversations`, `DELETE /conversations/{id}`.
- **Estados:** carregando (`Skeleton` × 3); vazio (não é exibido — redireciona para conversa nova); erro (`ErrorState` + "Tentar de novo"; "Nova conversa" continua disponível); apagando (linha some com animação; erro → volta e toast).
- **Confirmação de apagar:** `Sheet` "Apagar esta conversa?" / "O Nutri também esquece o que foi dito nela." / "Apagar" (destrutiva) / "Cancelar".

### S14 — Chat do Nutri (`/nutri/[conversa]`) 🔵
- **Exibe:** TopBar (← Conversas; marca Nutri apagada quando offline; subtítulo "Sem conexão" / "Olhando seu {próxima refeição} de hoje" / "Conhece seu plano e suas restrições" 🔵); aviso offline; **estado inicial** (conversa sem mensagens): "No que posso ajudar, {nome}?", "Pergunte como se estivesse falando com a nutricionista da academia.", card "O que estou olhando agora" (linhas coloridas gema/alerta/mata), "Perguntas que cabem agora"; **conversa**: bolhas do usuário (com status "Não enviada · Tentar de novo"), respostas do Nutri com cartão de troca (de → para, carboidrato antes/depois, Δ kcal no dia), cartão de refeição (título, horário, kcal, itens, macros, aviso), segundo parágrafo, ações, 👍/👎; indicador "O Nutri está montando a resposta"; **chips de continuação dinâmicos** (RN45 — os da última resposta do Nutri; no mock eram fixos: "E no jantar?", "Por que mais fibra?", "O que como antes do treino?" 🔵); campo "Escreva sua pergunta" + enviar.
- **Endpoints:** `GET /conversations/{id}` (cabeçalho), `GET /conversations/{id}/messages` (paginação para cima), `POST /conversations/{id}/messages`, `POST /messages/{id}/actions/{i}`, `GET /nutri/context`, `GET /nutri/suggestions`, `GET /days/today` (para o subtítulo e para atualizar após ação), `PUT /ratings` (spec 07), `POST /days/today/undo`.
- **Estados:** carregando histórico (`Skeleton` de bolhas); erro ao carregar (`ErrorState`); inicial (conversa vazia); enviando; resposta; falha de envio; offline; 404 (conversa apagada/de outro usuário → "Conversa não encontrada" + "Ver conversas").
- **Validações:** pergunta 1–1.000 caracteres, sem só espaços; botão desabilitado vazio ou durante envio 🔵; contador aparece acima de 900 caracteres.
- **Rolagem:** abre no fim; ao carregar mensagens antigas, mantém a posição; nova resposta rola suavemente até ela (reduced-motion: sem suavização).

## 5. API

### `GET /api/v1/conversations`
- **Query:** `cursor` (opcional). 15 por página, `last_message_at` desc, só com mensagens (RN28).
- **Response 200:**
```json
{ "data": [ { "id": 12, "title": "Posso trocar o arroz por batata?", "preview": "Pode. No seu almoço os 150 g de arroz…", "message_count": 6, "last_message_at": "2026-09-22T12:10:00-03:00" } ],
  "meta": { "next_cursor": null, "per_page": 15 } }
```

### `POST /api/v1/conversations`
- **Request:** `{}`
- **Response 201** (nova) ou **200** (reaproveitou uma vazia): `{ "data": { "id": 13, "title": null, "message_count": 0, "last_message_at": null } }`
- **Efeitos:** RN30 — enfileira `SummarizeConversationJob` para a conversa anterior mais recente com mensagens novas desde o último resumo.

### `GET /api/v1/conversations/{conversation}`
- **Permissão:** dono (404). **Response 200:** mesmo item da lista.

### `DELETE /api/v1/conversations/{conversation}`
- **Permissão:** dono. **Response:** 204. **Efeitos:** apaga mensagens, resumo e avaliações das mensagens.

### `GET /api/v1/conversations/{conversation}/messages`
- **Query:** `cursor` (opcional). 30 por página, **mais recentes primeiro** (o front inverte para exibir).
- **Response 200:**
```json
{ "data": [
  { "id": 301, "role": "user", "content": "Posso trocar o arroz por batata?", "created_at": "2026-09-21T11:02:00-03:00" },
  { "id": 302, "role": "assistant", "content": "Pode. No seu almoço os 150 g de arroz entram com 42 g de carboidrato…",
    "follow_up": "A batata-doce tem mais fibra…",
    "follow_up_suggestions": ["E no jantar, o que como?", "Por que a batata segura mais a fome?"],
    "card": { "type": "swap", "slot": "almoco", "from": { "name": "Arroz branco cozido", "amount": "150 g", "calories": 195 },
              "to": { "name": "Batata-doce cozida", "amount": "180 g", "calories": 140 },
              "carbs_before": 42.0, "carbs_after": 33.0, "calorie_delta": -55 },
    "actions": [ { "index": 0, "kind": "substituir", "label": "Substituir no almoço de hoje", "slot": "almoco" },
                 { "index": 1, "kind": "outra-opcao", "label": "Ver outras opções" },
                 { "index": 2, "kind": "dispensar", "label": "Agora não" } ],
    "actions_available": true, "rating": null, "created_at": "2026-09-21T11:02:07-03:00" }
], "meta": { "next_cursor": "eyJpZCI6MzAwfQ", "per_page": 30 } }
```
- `actions_available = false` quando a ação já foi resolvida (aplicada/dispensada) ou a mensagem é de outro dia; nesse caso `actions` vem vazio.
- Cartão de refeição: `{ "type": "meal", "slot": "jantar", "title": "Jantar", "time": "20:30", "calories": 375, "macros": {…}, "items": [ { "name": "Ovos mexidos", "amount": "3 unidades", "calories": 230 } ], "warning": "Fica 8 g de proteína abaixo do jantar original." }`

### `POST /api/v1/conversations/{conversation}/messages`
- **Throttle:** `nutri`. **Permissão:** dono.
- **Request:** `{ "content": "Posso trocar o arroz por batata?" }`
- **Response 201:** `{ "data": { "user_message": { … }, "assistant_message": { … } } }` (formatos acima). Título e `last_message_at` da conversa atualizados.
- **Validações:** `content` required|string|min:1|max:1000 (após `trim`).
- **Erros:** 404; 422; 429 `TOO_MANY_REQUESTS`; 503 `AI_UNAVAILABLE` (nada gravado).

### `POST /api/v1/messages/{message}/actions/{index}`
- **Permissão:** `NutriMessagePolicy::applyAction` (dono; `role = assistant`).
- **Request:** `{}`
- **Response 200 (executável):**
```json
{ "data": {
  "message": { "id": 302, "actions": [], "actions_available": false },
  "confirmation": { "id": 303, "role": "assistant", "content": "Feito. Seu almoço de hoje vai com batata-doce cozida.",
                    "actions": [ { "index": 0, "kind": "ver-refeicao", "label": "Ver a refeição", "slot": "almoco" } ], "actions_available": true },
  "day": { …formato de GET /days/today… }
} }
```
- **Response 200 (dispensar):** `{ "data": { "message": { "id": 302, "actions": [], "actions_available": false } } }`
- `outra-opcao` e `ver-refeicao` **não** chamam este endpoint (são de interface).
- **Erros:** 404 (mensagem ou índice inexistente); 409 `ACTION_ALREADY_APPLIED`; 409 `ACTION_EXPIRED`; 409 `MEAL_ALREADY_DONE`; 409 `SUBSTITUTION_NOT_ALLOWED` (alimento deixou de ser permitido — ex.: restrição nova); 409 `NO_ACTIVE_PLAN`.
- **Efeitos:** `DayService::swap` ou `DayService::applyMeal` (`source = nutri`, `day_meal_changes`), `actions_resolved_at` + `resolved_action_index` na mensagem, mensagem de confirmação gravada (texto de modelo, sem IA).

### `GET /api/v1/nutri/context`
- **Response 200:**
```json
{ "data": { "lines": [
  { "text": "Seu almoço das 12:30, com arroz, feijão, frango grelhado, salada e azeite", "tone": "gema" },
  { "text": "1.250 kcal e 85 g de proteína ainda no plano de hoje", "tone": "gema" },
  { "text": "Sua alergia a amendoim e castanhas", "tone": "alerta" },
  { "text": "Seu objetivo de ganhar massa magra, com meta de 62 kg", "tone": "mata" }
] } }
```
Regras: 1ª linha só se há próxima refeição; uma linha `alerta` por alergia (restrições comuns não entram, para não poluir); objetivo com meta quando houver, e com a previsão ("até janeiro") quando RN35 der resultado.

### `GET /api/v1/nutri/suggestions`
- **Response 200:** `{ "data": [ { "id": "trocar-carbo", "question": "Posso trocar o arroz por outra coisa?" } ] }`
- `SuggestionBuilder` (regras, sem IA), até 4, a partir da próxima refeição de hoje: trocar o principal carboidrato; "Não tenho {principal proteína} em casa. O que uso no lugar?"; "O que comer antes do treino das {hora}?" (só em dia de treino); "Monte um {próxima refeição} com {3 itens da cozinha}". Sem próxima refeição: perguntas gerais ("Como fechar o dia se sobrou proteína?", "O que comer amanhã cedo?").

## 6. Validações
| Item | Regra |
|---|---|
| `content` | 1–1.000 chars após trim |
| `{index}` | inteiro ≥ 0 e < nº de ações da mensagem |
| ação | só `substituir`, `aplicar-refeicao`, `dispensar` pelo endpoint; RN31 |
| pergunta pré-preenchida (`?pergunta=`) | até 200 chars; tratada como texto puro |

## 7. Modelo de dados — ajuste
`nutri_messages` usa `actions_resolved_at` (timestamp) e `resolved_action_index` (tinyint) no lugar de `action_applied_at`, para registrar tanto a aplicação quanto a dispensa (ver `00-fundacao/modelo-de-dados.md`).

## 8. Critérios de aceitação
- **CA01** Dado um usuário sem conversas, quando toca no NutriBar, então abre direto uma conversa nova com a pergunta do atalho no campo.
- **CA02** Dado um usuário com conversas, quando toca no NutriBar, então vê a lista com "Nova conversa" e pode continuar a conversa de ontem com todo o histórico visível.
- **CA03** Dado duas conversas, quando o usuário começa uma terceira, então a segunda mais recente com mensagens novas ganha resumo (job) e a próxima pergunta envia à IA os resumos existentes (verificável com `FakeAiClient::assertSent`).
- **CA04** Dado uma conversa vazia já existente, quando toca "Nova conversa", então a mesma é reaproveitada (não surgem conversas vazias).
- **CA05** Dado a pergunta "Posso trocar o arroz por batata?", quando o Nutri responde com troca válida, então o cartão mostra números calculados pelo catálogo, e "Substituir no almoço de hoje" troca o item no dia, cria confirmação e oferece "Desfazer".
- **CA06** Dado que a IA propõe um alimento proibido (alergia), então a resposta chega só com texto, sem cartão nem ação.
- **CA07** Dado uma ação já aplicada ou dispensada, quando a conversa é recarregada, então as ações não aparecem.
- **CA08** Dado uma mensagem de ontem com ação, então a ação não aparece hoje e a API responde `ACTION_EXPIRED` se chamada.
- **CA09** Dado a IA fora do ar, quando envia a pergunta, então vê "Não enviada · Tentar de novo" e nada é gravado; "Tentar de novo" reenvia.
- **CA10** Dado o usuário B, quando acessa `/conversations/{id do A}`, suas mensagens ou ações, então recebe 404.
- **CA11** Dado que o usuário apaga uma conversa, então ela some da lista e seu resumo não é mais enviado à IA.
- **CA12** Nenhuma mensagem `system` é gravada; o system prompt nunca aparece em resposta da API.
- **CA13** Dado uma resposta sobre trocar o arroz, então os chips abaixo dela são os `follow_up_suggestions` dessa resposta (não uma lista fixa), no máximo 3, e tocar num chip envia aquela pergunta.
- **CA14** Dado que a IA não manda sugestões (ou manda só duplicadas, longas ou citando alimento proibido), então aparecem as sugestões de reserva do `SuggestionBuilder`, e nenhuma cita alimento proibido.
- **CA15** Dado uma conversa antiga reaberta, então os chips exibidos são os da última resposta gravada nela.

## 9. Test Strategy

### Component Tests
- `ConversationListItem` — Normal, TituloLongo (corte), Apagando.
- `ConversationList` — Carregando, ComItens, CarregandoMais, Erro, ComPerguntaPendente.
- `ChatBubble` — Usuario, UsuarioEnviando, UsuarioFalhou (botão "Tentar de novo" chama callback), Assistente, AssistenteComFollowUp.
- `SwapCard` — Reducao de kcal (verde), Aumento (neutro); leitura por leitor de tela ("de arroz branco para batata-doce").
- `MealSuggestionCard` — ComAviso, SemAviso.
- `NutriActions` — Substituir+Outra+Dispensar, Aplicar, VerRefeicao (link), Aplicando (botão `carregando`), Indisponível (não renderiza).
- `ContextCard` — Carregando, ComAlergia, SemProximaRefeicao.
- `ThinkingIndicator` — `aria-live` anuncia; reduced-motion sem pulsar.
- `ChatComposer` — Vazio (enviar desabilitado), Digitando, Enviando, LimiteDeCaracteres.
- `SuggestionChips` (novo, substitui os chips fixos) — TresSugestoes, UmaSugestao, Vazia (não renderiza), Oculta durante envio, TextoLongo (quebra de linha, sem cortar); entrada em cascata; `play`: tocar chama `aoEscolher(texto)`; reduced-motion.
- `OfflineNotice`.

### Unit Tests
- Back: `NutriResponseParserTest` (JSON válido; JSON com texto em volta; não-JSON → só texto; campos faltando); `NutriActionValidatorTest` (troca válida; slot inexistente; alimento de origem fora da refeição; destino proibido; refeição com item fora de 5–600 g; > 6 itens; porção da troca recalculada; aviso de proteína); `ConversationTitle` (corte em 60 na palavra); `SuggestionBuilderTest` (inclui `continuations()` de reserva); `FollowUpSanitizerTest` (RN45: vazias, duplicadas sem acento/caixa, > 60 chars, igual à pergunta, alimento proibido, corte em 3, reserva quando sobra nada); `NutriContextBuilderTest` (linhas e ordem; alergias em destaque; sem próxima refeição).
- Front: `buildQuestionForAction(action, message)`; inversão/merge de páginas de mensagens.

### Integration Tests
- Back: `ConversationsTest` (lista só com mensagens; ordenação; cursor; reaproveita vazia; job de resumo enfileirado corretamente; delete com cascade e avaliações); `MessagesTest` (201 com as duas mensagens; `FakeAiClient::assertSent` confere system prompt, contexto, 3 resumos, 20 últimas; 503 não grava; 429 no limite; título definido na 1ª mensagem); `ActionsTest` (substituir e aplicar alteram o dia com `source = nutri`; confirmação criada; 409 `ACTION_ALREADY_APPLIED`, `ACTION_EXPIRED`, `MEAL_ALREADY_DONE`; dispensar; undo funciona depois de ação); `ContextTest`; `SummarizeJobTest` (resume só mensagens novas; incorpora resumo anterior; falha não propaga); `OwnershipTest` (dataset).
- Front (MSW): lista → nova conversa → pergunta sugerida → resposta com cartão → aplicar → confirmação + toast; falha 503 → "Não enviada" → reenviar; `?pergunta=` pré-preenche o campo sem enviar; carregar mensagens antigas mantém a rolagem; chips mudam a cada resposta e tocar num chip envia a pergunta; chips somem durante o envio e após falha.
- Back (adicional em `MessagesTest`): `follow_up_suggestions` gravado e devolvido; fixture com sugestão citando castanha para usuário alérgico não devolve essa sugestão.

### E2E Tests
- E2E-08 (conversa nova, ação aplicada, voltar e continuar a anterior), E2E-06 (parte: ação do Nutri com alergia não aparece — `FakeAiClient` roteirizado para propor castanha).

## 10. Definition of Done
```
[ ] CA01–CA15 atendidos
[ ] Sugestões de continuação dinâmicas (RN45) com sanitização e reserva; chips fixos removidos do chat
[ ] AiClient (OpenAiCompatible + Fake), prompts versionados, parser e validador com unit tests
[ ] Endpoints de conversas, mensagens, ações, contexto, sugestões; Policies; rate limiter nutri
[ ] SummarizeConversationJob + memória no prompt
[ ] Tela Conversas (nova) gerada com frontend-design; chat ligado à API (sem askNutri mock)
[ ] Componentes do chat com stories e play; integração com MSW
[ ] E2E-08 verde; alergia coberta em E2E-06
[ ] Nenhum dangerouslySetInnerHTML; resposta da IA tratada como texto
[ ] axe limpo; aria-live no indicador e nas novas mensagens
```
