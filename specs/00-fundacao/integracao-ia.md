# Integração com a IA generativa

🟢 O documento pede: "integrar o backend Laravel a uma API de IA generativa para processar os registros alimentares dos usuários e produzir sugestões de melhoria personalizadas" e "IA generativa para gerar recomendações e cardápios personalizados".
🟤 O Node usava o SDK `openai` apontando para `https://api.aimlapi.com/`, modelo `gpt-4-turbo`, `max_tokens: 512`, stream consumido inteiro antes de responder (o cliente não recebia nada).

## 1. Três usos da IA

| Uso | Quando | Síncrono? | Saída | Validação |
|---|---|---|---|---|
| **Gerar plano** | onboarding concluído, "Refazer meu plano", mudança de restrição (RN21) | não — `GeneratePlanJob` | JSON `PlanResponse` | `PortionAdjuster` + `PlanValidator` (RN18) |
| **Responder no Nutri** | cada pergunta | sim — dentro do request (timeout 60 s) | JSON `NutriResponse` | parse + `NutriActionValidator` (RN31) |
| **Resumir conversa** | ao abrir nova conversa (RN30) | não — `SummarizeConversationJob` | texto ≤ 600 chars | tamanho, não vazio |

"Registros alimentares" (✅ D1, revista em D13) chegam à IA como: o que foi registrado em cada refeição de hoje (`comido`) e a sugestão (`sugestao`), trocas aplicadas, restantes do dia (contexto do Nutri — RN29) e, no resumo semanal/dicas, a constância (spec 06 — regras sem IA no MVP).

## 2. Interface

```php
interface AiClient
{
    /** @param list<array{role: 'system'|'user'|'assistant', content: string}> $messages */
    public function chat(array $messages, AiOptions $options): AiResult;
}

final class AiOptions   { public function __construct(public string $purpose, public string $model, public int $maxTokens, public bool $json = false, public float $temperature = 0.4) {} }
final class AiResult    { public function __construct(public string $content, public ?int $promptTokens, public ?int $completionTokens, public int $durationMs) {} }
```

- `OpenAiCompatibleClient`: `POST {AI_BASE_URL}/chat/completions` com `Http::withToken()->timeout()->retry(2, 500, fn ($e) => $e instanceof ConnectionException)`; quando `json = true`, envia `response_format: {type: "json_object"}` (suportado pela aimlapi/OpenAI; se o modelo não suportar, o parser ainda extrai o primeiro objeto JSON do texto). Sem streaming no MVP.
- Erros de rede/5xx/timeout ⇒ `AiUnavailableException` ⇒ `503 AI_UNAVAILABLE` no chat; plano `failed` com `AI_UNAVAILABLE`.
- Todo chamado passa por `AiRequestLogger` (RN44: sem conteúdo).
- Binding em `AppServiceProvider` por `config('services.ai.driver')`: `openai` | `fake`.

## 3. Geração do plano

### 3.1 Mensagens

**system** (`PlanPrompt::system()`, versionado — `PLAN_PROMPT_VERSION = 1`):
> Você monta cardápios para pessoas que treinam em uma academia de bairro no Sul do Brasil. Use SOMENTE os alimentos da lista fornecida, referenciados pelo `id`. Monte 5 refeições (slots `cafe`, `lanche`, `almoco`, `pre-treino`, `jantar`) com comida simples, do dia a dia brasileiro, que a pessoa já tem em casa (prefira itens com `pantry: true`). Quantidades em gramas. Respeite a meta diária de calorias e proteína. Distribua a proteína ao longo do dia. No pré-treino, prefira carboidrato de digestão rápida com pouca gordura. Se o almoço é marmita, escolha itens que aguentem a manhã na bolsa. Responda APENAS com JSON no formato indicado, sem texto fora do JSON.

**user** (`PlanPrompt::user($inputs)`) — JSON com:
```json
{
  "pessoa": { "objetivo": "ganhar-massa", "sexo": "feminino", "idade": 27, "altura_cm": 164, "peso_kg": 58.4, "atividade": "moderado", "trabalho": "sentada", "almoco": "marmita" },
  "metas_diarias": { "kcal": 2250, "proteina_g": 115, "carboidrato_g": 285, "gordura_g": 60 },
  "horarios": { "cafe": "07:00", "lanche": "10:00", "almoco": "12:30", "pre-treino": "17:30", "jantar": "20:30", "treino": "19:00" },
  "distribuicao_kcal": { "cafe": 0.25, "lanche": 0.10, "almoco": 0.30, "pre-treino": 0.10, "jantar": 0.25 },
  "alimentos_permitidos": [ { "id": 12, "nome": "Arroz branco cozido", "grupo": "carboidrato", "kcal_100g": 128, "prot_100g": 2.5, "carb_100g": 28.1, "gord_100g": 0.2, "pantry": true, "porcao_g": 150 } ],
  "formato_resposta": { "meals": [ { "slot": "cafe", "items": [ { "food_id": 0, "grams": 0 } ] } ] }
}
```

🟡 Prompt versão 2 (Plano 10C): `porcao_g` é a porção de costume do catálogo; o system pede porções entre 0,5× e 2,5× dela (acrescentar outro alimento em vez de inflar a porção), a divisão de `distribuicao_kcal` (sugestão técnica, não vem do documento) e carboidrato de prato no almoço e no jantar. A `FakeAiClient` segue as mesmas regras.
Nome, e-mail e texto livre do usuário **não** são enviados (minimização — ver `seguranca.md`). "Outras restrições" já foram aplicadas no filtro (RN16) e não vão ao prompt.

### 3.2 Contrato de resposta (`PlanResponse`)
```json
{ "meals": [
  { "slot": "cafe",       "items": [ { "food_id": 31, "grams": 150 }, { "food_id": 40, "grams": 50 } ] },
  { "slot": "lanche",     "items": [ ... ] },
  { "slot": "almoco",     "items": [ ... ] },
  { "slot": "pre-treino", "items": [ ... ] },
  { "slot": "jantar",     "items": [ ... ] }
] }
```
Horários, nomes, notas e totais **não** vêm da IA: são do `MealScheduler`/`DayTotals`.

### 3.3 Pipeline (`PlanGenerator`)
```
inputs = snapshot(perfil) + NutritionCalculator + MealScheduler + FoodFilter
  → AiClient.chat(json)                       [attempt 1]
  → PlanResponse::parse   (falha ⇒ erro "JSON inválido")
  → PortionAdjuster       (escala uniforme se desvio de kcal ≤ 25%)
  → PlanValidator         (RN18 1–4) ─ ok ─▶ persistir plan_meals/items; status ready; PlanService::activate (RN20)
                              └ erros ─▶ attempt 2: mesmas mensagens + assistant(resposta anterior) + user("Corrija: <lista de erros>")
                                           └ erros de novo ─▶ status failed, failure_reason AI_INVALID_RESPONSE
```
`max_tokens` do plano: 1.500. Temperatura 0,4.

## 4. Nutri (chat)

### 4.1 Mensagens (RN29)
1. **system** — `NutriPrompt::system()` (versionado):
   > Você é o Nutri, assistente de alimentação do app Prato Forte, feito com a academia Zfit, de Capivari de Baixo. Fale português do Brasil, de forma curta, calorosa e prática, como a nutricionista da academia conversando no balcão. Baseie-se no plano e no contexto fornecidos. Nunca sugira alimentos das restrições ou alergias. Não faça diagnóstico nem prescrição para doenças, gestação, transtornos alimentares ou remédios: nesses casos, recomende procurar um profissional de saúde. Não recomende suplementos específicos. Quando propuser trocar um alimento de uma refeição de hoje ou montar uma refeição inteira, use SOMENTE alimentos da lista `alimentos_permitidos`, por `id`, e descreva a proposta no campo `action`. Em `suggestions`, escreva até 3 próximas perguntas curtas (até 60 caracteres), do jeito que a pessoa perguntaria, que continuem o assunto e ajudem com o plano de hoje. Responda APENAS com JSON no formato indicado.
2. **system** — contexto do momento em JSON (`NutriContextBuilder`): nome preferido, objetivo, meta de peso, metas/restantes do dia, refeições de hoje (slot, horário, feita, `sugestao` e `comido` — D13 —, itens com `food_id`, nome, gramas), restrições e alergias (destacadas), itens da cozinha, almoço, treino e se hoje treina, e `alimentos_permitidos` (id, nome, grupo — sem macros, para economizar tokens).
3. **system** — "Memória de conversas anteriores:" + os 3 resumos (se houver).
4. Últimas 20 mensagens da conversa (`user`/`assistant`, só `content`).
5. A pergunta nova (`user`).

`max_tokens`: 700. Temperatura 0,5.

### 4.2 Contrato de resposta (`NutriResponse`)
```json
{
  "reply": "Pode. No seu almoço os 150 g de arroz entram com 42 g de carboidrato…",
  "follow_up": "A batata-doce tem mais fibra…",          // opcional
  "suggestions": ["E no jantar, o que como?", "Por que a batata segura mais a fome?"],   // 0–3, RN45
  "action": null
          | { "type": "substituir", "slot": "almoco", "from_food_id": 12, "to_food_id": 57 }
          | { "type": "aplicar-refeicao", "slot": "jantar", "items": [ { "food_id": 31, "grams": 150 }, { "food_id": 57, "grams": 150 } ] }
}
```

### 4.3 Pós-processamento (`NutriChatService`)
1. Parse. Se não for JSON válido mas houver texto, usa o texto inteiro como `reply` e descarta ação (degradação graciosa).
2. `NutriActionValidator` (RN31). Troca: porção recalculada via regra do RN25 (não usa gramas da IA). Refeição: gramas da IA, 5–600 g, 1–6 itens.
3. Sanitiza `suggestions` (RN45): filtra vazias, duplicadas, longas, repetição da pergunta e alimentos proibidos; se nada sobrar, usa `SuggestionBuilder::continuations()`. Grava em `follow_up_suggestions`.
4. Monta `card` (calculado pelo backend) e `actions`:
   - `substituir` ⇒ `card.type = swap` (de/para, quantidades, kcal, carboidrato antes/depois, Δ kcal) + ações `[substituir: "Substituir no {refeição} de hoje", outra-opcao: "Ver outras opções", dispensar: "Agora não"]` 🔵
   - `aplicar-refeicao` ⇒ `card.type = meal` (título, horário, kcal, macros, itens, `warning` RN31) + ações `[aplicar-refeicao: "Aplicar no {refeição} de hoje", outra-opcao: "Gerar outra opção", dispensar: "Agora não"]` 🔵
   - sem ação e a resposta cita uma refeição de hoje ⇒ `[ver-refeicao: "Ver o {refeição}"]` (opcional, se o validador identificar o slot citado pela IA em `action` nulo — não obrigatório no MVP).
5. Grava a mensagem do usuário e a do assistente **na mesma transação**, atualiza `last_message_at` e título (RN28), devolve as duas mensagens no response (corrige 🟤).

### 4.4 Falhas
| Situação | Resultado |
|---|---|
| IA fora do ar / timeout | `503 AI_UNAVAILABLE`; **a pergunta do usuário não é gravada** (o front mostra "Não enviada · Tentar de novo" 🔵) |
| JSON inválido | resposta só com texto (ou mensagem padrão "Não consegui montar uma resposta agora. Pode perguntar de novo?") |
| Ação inválida | descartada, resposta mantém o texto, log `warning` |

## 5. Resumo de conversa
**system**: "Resuma a conversa abaixo em até 600 caracteres, em português, só com fatos úteis para próximas conversas: preferências e aversões declaradas, dificuldades, trocas aceitas, objetivos mencionados. Sem saudações." + mensagens desde `summarized_message_id` (e o resumo anterior, se existir, para ser incorporado). `max_tokens`: 250.

## 6. `FakeAiClient` (testes, E2E, demonstração)
- Responde de forma **determinística** a partir de `tests/Fixtures/ai/*.json` ou de roteiros registrados em teste: `FakeAiClient::queue(purpose, content)`, `FakeAiClient::failNext(purpose)`, `FakeAiClient::assertSent(purpose, fn)`.
- Para plano sem roteiro: monta um plano válido escolhendo, para cada slot, alimentos permitidos do grupo adequado (garante que o E2E de alergia exercite o filtro real).
- Para o Nutri sem roteiro: reproduz os 4 cenários do `askNutri` do mock 🔵 (batata/arroz → troca; frango → troca; jantar/ovo/brócolis → refeição; treino → texto) usando `food_id` reais do catálogo, e a resposta padrão "Ainda não sei responder isso por aqui…". Cada cenário devolve `suggestions` próprias (ex.: troca do arroz → "E no jantar, o que como?", "Por que a batata segura mais a fome?"); a resposta padrão devolve `suggestions` vazias, para exercitar a reserva do RN45. Fixtures extras: `nutri_suggestions_forbidden.json` (sugestão citando castanha) e `nutri_suggestions_messy.json` (duplicadas, longas, vazias).
- Variável `AI_FAKE_FAIL_PLAN_FOR=<e-mails separados por vírgula>` (só E2E) faz a **primeira** geração de plano dessas contas falhar, para o teste "Tentar de novo" — determinístico mesmo com várias contas e vários processos.

## 7. Custos 🟡
Estimativa por usuário ativo (modelo pequeno tipo `gpt-4o-mini`): geração de plano ≈ 4–6 mil tokens de entrada (lista de alimentos) + 1 mil de saída; mensagem do Nutri ≈ 2–4 mil de entrada + 300 de saída; resumo ≈ 1,5 mil. Os limites RN19/RN33 e `ai_requests` permitem acompanhar e cortar custo. Modelos configuráveis por uso (`AI_MODEL_PLAN`, `AI_MODEL_CHAT`).
