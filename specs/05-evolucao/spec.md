# 05 — Evolução: pesagens, gráfico, previsão, constância e médias

## 1. Contexto e fontes
- 🟢 Não detalhado no documento; apoia "promover alimentação mais equilibrada" e o objetivo do usuário.
- 🔵 `/evolucao` (período 6 semanas / 3 meses / tudo, gráfico de peso com meta tracejada, previsão, estado vazio, constância em 28 quadrados com legenda e sequência, "Média por dia neste período" com observação) e `/evolucao/peso` (stepper ±100 g, régua ±1 kg, comparação com a semana passada, últimas 4 pesagens, "Salvar peso de hoje", dica de pesagem).
- 🟤 Nada no Node.
- Regras: RN10 (meta), RN34–RN37.

## 2. Requisitos funcionais

### RF23 — Registrar peso
**Atores:** usuário com onboarding concluído.
**Fluxo principal:** Evolução → "Registrar peso da semana" → valor inicial = última pesagem → ajusta com − / + (100 g) ou toca no número e digita 🟡 → "Salvar peso de hoje" → volta à Evolução com o ponto novo animado.
**Alternativos:** já pesou hoje → o valor de hoje é substituído (RN34), com aviso "Você já registrou hoje. Salvar vai atualizar o valor."; erro de rede → fica na tela, botão volta ao normal, mensagem.
**Regras:** RN34.

### RF24 — Ver evolução do peso
**Fluxo:** Evolução → escolhe período → vê peso atual (`CountUp`), variação desde o início do período, gráfico com meta e início, previsão de chegada na meta (RN35) ou mensagem para registrar mais pesagens.
**Alternativos:** sem pesagens no período → estado vazio 🔵 "Sua linha começa na primeira pesagem". Sem meta (objetivo "mais disposição") → gráfico sem linha de meta e sem previsão.

### RF25 — Constância e médias
**Fluxo:** Evolução → "Constância, últimos 28 dias": quadrados por status (RN36), "{n} dias com todas as refeições feitas" (feita = com registro, RN46 — D13), "Sua sequência atual é de {n} dias" (se > 1); "Média por dia neste período": proteína e calorias × meta, observação (RN37).
**Alternativo:** nenhum dia com refeição feita → bloco de médias com `EmptyState` "Registre o que você comeu para ver suas médias aqui." (D13; as médias somam os registros — RN24)

## 3. Fluxos
```
Hoje (card de peso) ─▶ /evolucao ─▶ GET /progress?period=6w ─┬─ com pesagens ─▶ gráfico + constância + médias
                                                             └─ sem pesagens ─▶ estado vazio + "Registrar meu peso"
/evolucao ─▶ troca período ─▶ GET /progress?period=3m|all
/evolucao ─▶ "Registrar peso" ─▶ /evolucao/peso ─▶ GET /weigh-ins ─▶ ajustar ─▶ POST /weigh-ins ─▶ /evolucao (invalida progress, profile, day)
```

## 4. Telas ↔ backend

### S15 — Evolução (`/evolucao`) 🔵
- **Exibe:** título; `Segmento` de período (hoje é um `tablist` próprio — passa a usar o componente); `WeightChart` (peso atual, "+1,6 kg em 5 semanas", linha, área, pontos, ponto atual pulsando, linha tracejada "meta {x} kg", rótulos de data); texto de previsão; `AdherenceGrid` (28 dias, "hoje" respirando, legenda); médias (`Rail` proteína e calorias) + observação; botão "Registrar peso da semana"; `BottomNav`.
- **Endpoint:** `GET /progress?period=6w|3m|all` (período lembrado em `localStorage` 🟡).
- **Estados:** carregando (`Skeleton` × 4 🔵); erro ("Não foi possível carregar sua evolução" 🔵); vazio de pesagens (`EvolucaoVazia` 🔵 → `EmptyState` de domínio); vazio de médias; sucesso.
- **Correção do mock:** "em {n} semanas" usa o intervalo real entre a primeira e a última pesagem do período (o mock usava o número de pesagens); as médias vêm da API (o mock tinha 112/120 e 1.870/1.950 fixos).

### S16 — Registrar peso (`/evolucao/peso`) 🔵
- **Exibe:** "Quanto a balança marcou?", data de hoje por extenso, `WeightStepper` (−, número, +; régua de ±1 kg em torno da última pesagem, marcador animado), comparação com a última pesagem, "Suas pesagens" (últimas 4 com delta em gramas ou "início"), botão "Salvar peso de hoje" (`carregando` "Salvando"), dica "Pese-se de manhã, antes de comer, sempre na mesma balança." 🔵
- **Dados:** `GET /weigh-ins`, objetivo e meta de `GET /profile`.
- **Valor inicial:** última pesagem (o mock usava peso do perfil + 0,2 — ver `99-inconsistencias.md`).
- **Mensagem de comparação (regra de interface, por objetivo)** 🟡 (o mock só tinha a de ganho):
| Objetivo | Subiu | Desceu | Igual |
|---|---|---|---|
| ganhar-massa | "Dentro do esperado para quem está ganhando massa." 🔵 | "Vale conferir se a semana teve menos refeições no plano." 🔵 | "Mesmo peso da semana passada. Uma semana estável é normal." 🔵 |
| perder-gordura | "Oscilação de uma semana é normal. Vale olhar a constância." | "Dentro do esperado para quem está perdendo gordura." | idem 🔵 |
| manter-peso | até 500 g: "Dentro do esperado para quem quer manter." acima: "Vale olhar a constância desta semana." | idem | idem 🔵 |
| mais-disposicao | só o número ("São {g} g a mais/menos que na última pesagem.") | idem | idem 🔵 |
- **Imperial (RN39):** stepper em 0,2 lb; régua ±2 lb; conversão na hora de enviar.
- **Estados:** carregando (`Skeleton` × 2 🔵); sem pesagens (valor inicial = `start_weight_kg`, régua centrada nele, histórico vazio); salvando; erro; sucesso → navega.

### Card de peso em Hoje (S11) e Perfil (S17)
- `ReguaPeso` com início, atual e meta vindos de `GET /profile` (`start_weight_kg`, `current_weight_kg`, `goal_weight_kg`); oculto sem meta.

## 5. API

### `GET /api/v1/weigh-ins`
- **Response 200:** `{ "data": [ { "id": 1, "date": "2026-08-11", "weight_kg": 56.8 } ] }` — ordem crescente de data, todas (volume pequeno: ≤ 1/dia).

### `POST /api/v1/weigh-ins`
- **Request:** `{ "weight_kg": 58.6, "date": "2026-09-23" }` (`date` opcional, padrão hoje)
- **Response 201** (nova) / **200** (substituiu): `{ "data": { "id": 7, "date": "2026-09-23", "weight_kg": 58.6 }, "meta": { "replaced": false } }`
- **Validações:** `weight_kg` required|numeric|between:30,250|decimal:0,1; `date` date_format:Y-m-d|before_or_equal:today|after_or_equal:today-30.
- **Erros:** 401; 409 `ONBOARDING_INCOMPLETE`; 422.

### `GET /api/v1/progress?period=6w|3m|all`
- **Response 200:**
```json
{ "data": {
  "period": "6w",
  "weight": {
    "start_kg": 56.8, "current_kg": 58.4, "goal_kg": 62.0, "goal_source": "user",
    "change_kg": 1.6, "span_weeks": 5,
    "points": [ { "date": "2026-08-11", "weight_kg": 56.8 } ],
    "forecast": { "date": "2027-01-28", "label": "fim de janeiro" }
  },
  "adherence": {
    "days": [ { "date": "2026-08-27", "status": "completo" } ],
    "complete_days": 21, "streak": 4
  },
  "averages": {
    "days_counted": 26,
    "protein": { "avg_g": 112, "target_g": 115 },
    "calories": { "avg_kcal": 1870, "target_kcal": 2250 },
    "insight": "Você fica um pouco abaixo da meta de proteína nos dias sem treino."
  }
} }
```
- `weight.points` vazio ⇒ estado vazio; `forecast` `null` quando RN35 não se aplica; `goal_kg` `null` para "mais disposição"; `averages` com `days_counted: 0` e valores `null` ⇒ vazio de médias; `insight` `null` quando a regra do RN37 não dispara.
- `adherence` é sempre dos últimos 28 dias (independe do período) 🔵.
- **Erros:** 422 período inválido.

## 6. Validações
| Campo | Regra |
|---|---|
| weight_kg | 30,0–250,0; 1 casa decimal |
| date | hoje − 30 … hoje |
| period | `6w`, `3m`, `all` |

## 7. Critérios de aceitação
- **CA01** Dado a primeira pesagem do onboarding, então Evolução mostra 1 ponto e a mensagem "Registre mais uma pesagem para estimar quando você chega na meta."
- **CA02** Dado duas pesagens no mesmo dia, então existe só uma, com o valor mais recente.
- **CA03** Dado as 6 pesagens do mock (56,8 → 58,4 em 5 semanas) e meta 62, então a previsão aparece e é calculada por regressão linear (valor verificado no unit test).
- **CA04** Dado pesagens se afastando da meta, então não há previsão.
- **CA05** Dado 28 dias com o padrão do mock, então `complete_days` e `streak` batem com o cálculo de RN36 (hoje ignorado na sequência).
- **CA06** Dado nenhum dia com refeição feita, então o bloco de médias mostra o estado vazio.
- **CA07** Dado um usuário "mais disposição", então o gráfico não tem linha de meta nem previsão.
- **CA08** Dado registrar peso, então o Perfil e o card de Hoje passam a mostrar o novo peso atual.

## 8. Test Strategy

### Component Tests
- `WeightChart` — SeisPesagens (mock), UmaPesagem, SemMeta, Perda (linha descendo), MovimentoReduzido (linha já desenhada); `role=img` com `aria-label` descritivo.
- `AdherenceGrid` — Padrão do mock, TodoVazio, SequenciaLonga; cada quadrado com `title`/rótulo acessível ("21 de setembro: dia completo").
- `WeightStepper` — Inicial, Ajustando (±), ForaDaRegua (marcador limitado), Imperial, Digitando (modo edição do número); botões com `aria-label` "Diminuir 100 gramas"/"Aumentar 100 gramas" 🔵.
- `WeightDeltaMessage` — cada célula da tabela do §4.
- `EmptyState` (evolução) — com botão.

### Unit Tests
- Back: `WeightForecastTest` (mínimo 3 pesagens/14 dias; direção; horizonte 52 semanas; rótulo início/meados/fim de mês); `AdherenceCalculatorTest` (status por dia; sequência terminando ontem; dias não materializados = vazio); `ProgressService` médias e `insight` (dias de treino × descanso).
- Front: `weightDeltaMessage(goal, delta)`; `spanWeeks(points)`; `units` para o stepper.

### Integration Tests
- Back: `WeighInTest` (201/200 upsert; limites; data futura 422; 30 dias; atualiza peso atual do perfil); `ProgressTest` (períodos; vazio; sem meta; aderência 28 dias; médias com fixtures de `day_meals`).
- Front (MSW): Evolução troca de período refaz a consulta; estado vazio → registrar → volta com ponto; erro → `ErrorState` → tentar de novo; página de peso salva e invalida `profile`/`progress`.

### E2E Tests
- E2E-09 (registrar peso e ver no gráfico).

## 9. Definition of Done
```
[x] CA01–CA08 atendidos
[x] WeightForecast, AdherenceCalculator, ProgressService com unit tests
[x] Endpoints weigh-ins e progress com feature tests
[x] Evolução e Registrar peso ligados à API; valores fixos do mock removidos
[x] WeightChart, AdherenceGrid, WeightStepper extraídos para features/progress com stories
[x] E2E-09 verde
[x] Gráfico acessível (rótulo descritivo); reduced-motion verificado
```

Verificado em 2026-10-06 (Plano 10B): componentes em `features/progresso`; gráfico `role="img"` com rótulo descritivo; story MovimentoReduzido; E2E-09 verde.
