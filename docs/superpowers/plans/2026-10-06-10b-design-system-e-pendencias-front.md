# Plano 10B — Design system completo e pendências do front — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Cumprir o que faltava da spec 08 — primitivos `EmptyState`, `Aviso`, `Selo` usados nas telas; stories dos primitivos e da estrutura que não tinham; componentes de domínio ainda dentro das telas extraídos com stories — e fechar os "minor (deferred)" do front dos Planos 05B–09.

**Architecture:** Primitivos em `src/components/ui`, estrutura em `src/components/app`, domínio em `src/features/*/components` (recebem dados por props; o contêiner liga o hook — spec 08 §6). Extrair não muda texto, role, aria nem id: os testes de integração e E2E existentes são a rede de segurança; cada componente novo ganha story com `play`.

**Tech Stack:** Next 16, React 19, Storybook 10 (+ axe), Vitest 4, MSW 2, Playwright.

**Spec:** `specs/08-design-system/spec.md` (§4, §5, §6, DoD), specs 03–07 (§8 Component Tests).

**Onde rodar:** front, branch `plano-10b-design-system` saindo de `plano-09-demonstracao`.

## Decisões deste plano (rulings)

1. **Nomes que já existem com outro nome** ficam (a spec 08 §6 é atualizada): `AveragesBlock` = `MediasDoPeriodo`, `WeightHistory` = `HistoricoPesagens`, `NotificationSettings` = `AvisosNoCelular`, `UsabilitySurvey` = `QuestionarioTela`, `ChatBubble` exporta `PerguntaBubble`/`RespostaBubble`. Renomear não muda comportamento e custaria diffs grandes.
2. **`EmptyState`** com as props da spec (`ilustracao?`, `titulo`, `descricao?`, `acao?`, `tom?`); `EvolucaoVazia` passa a usá-lo (mesma ilustração).
3. **`Aviso`**: `role="status"` em gema/mata; `role="alert"` em alerta.
4. **Minors que não entram** (registrados): Likert com tabindex móvel (o grupo já responde às setas; o tab em cada opção é aceitável e mais previsível em formulário longo).

## Tasks

### Task 1: `Selo`, `Aviso`, `EmptyState` (primitivos) + stories
- Stories com `play`: Selo (Mata, Gema, Alerta, Neutro, Pulsante, MovimentoReduzido); Aviso (Gema, Alerta, Mata, ComTitulo — role certo); EmptyState (Evolucao, TrocasVazias, DiaSemRegistro, MediasVazias, Obrigado — título, ação como link ou botão).
- `EtiquetaAlergia` passa a ser `<Selo tom="alerta">Alergia</Selo>`.

### Task 2: Usar os primitivos nas telas
- Selo: "Trocado" (FoodItemRow), "Refeição feita"/"Próxima refeição" (DetalheTela, MealRow), "meta sugerida" (GoalCard), "Alergia".
- Aviso: aviso da refeição do Nutri (MealSuggestionCard), OfflineNotice, mensagem de AvisosNoCelular, "Você já registrou hoje" (RegistrarPeso), aviso do GoalWeightField (via `Field aviso`), "Sua pergunta" das Conversas.
- EmptyState: EvolucaoVazia, médias vazias, "Nada registrado neste dia" (Dieta), agradecimento do questionário, folha de troca sem opções.
- Rede: integração + stories + E2E existentes continuam verdes (textos iguais).

### Task 3: Stories da estrutura e dos primitivos que faltavam
- `Screen` (Clara, Escura), `TopBar` (Simples, ComSelo, ComNutri), `BottomNav` (CadaAbaAtiva — `aria-current`), `ErrorState` (ComTentar, SemTentar), `Rail`/`RailSimples`/`ReguaPeso`, `Skeleton`/`EsqueletoDoDia`, `Steps`, `CountUp`/`Reveal`, `OfflineNotice`.

### Task 4: Domínio extraído das telas, com stories
- onboarding: `RoutineTimes` e `DayToggleGroup` (de EtapaRotina) — Padrao, ErroTreino, NenhumDia.
- perfil: `ProfileHeader`, `ProfileMenu` (de PerfilTela) — ComMeta/MetaSugerida/SemMeta no GoalCard já existem; Menu com item desabilitado ("Obrigado por avaliar!").
- plano: `MacroSummary`, `PlanReadySummary` (de ProntoTela), `PlanGenerating` (de GerandoTela).
- nutri: `ConversationList` (de ConversasTela), `SuggestionList` (perguntas do estado inicial do chat).
- settings: `UnitSelector` (Segmento de medidas).
- Atualizar a tabela §6 da spec 08 com os nomes reais (ruling 1).

### Task 5: Pendências do front (minors adiados), cada uma com teste
- 05B: aplicar ação invalida `conversas` e `contextoNutri`; `acrescentar` cancela consultas em voo; `aplicando` aceita várias mensagens; OfflineNotice sem "Sua pergunta não saiu daqui" quando não há pergunta; `?pergunta=` sai da URL depois do primeiro envio.
- 06B: `key={dados.period}` no gráfico; região `sr-only` com o peso em vez de `aria-live` no CountUp; `usePeriodo` prefere a memória depois de falha de escrita; rótulo de data único centralizado; histórico sem depender do formato de `dataPorExtenso`; delta 0 como "0 g" neutro; médias sem meta escondem o denominador; POST de pesagem manda `date` de hoje.
- 07B/07C: `Toggle` com `aria-disabled` (foco não cai); medidas travadas enquanto salva um aviso; iPadOS como iPhone; mensagem de diferença usa o valor que vai à API; larguras do histórico se ajustam.
- 08B: campo de comentário limpa ao fechar; envio sem `?? 3` (volta à primeira tela sem resposta); erro do envio só na tela 13; escala de utilidade com "1 — Nada úteis"/"5 — Muito úteis" no nome; "Agora não" que falha avisa e devolve o convite.
- 09: PNG 512 com `purpose: any` no manifesto.

### Task 6: Verificação
- `npm run lint && npm run typecheck && npm test && npm run build`; E2E com o backend do 10A semeado ⇒ tudo verde. Screenshot das telas com Selo/Aviso/EmptyState.
