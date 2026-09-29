# 08 — Biblioteca de componentes e Storybook

## 1. Contexto e princípios
- 🔵 O frontend já tem uma biblioteca embrionária: `components/ui` (Button, Chip, CountUp, Field/Segmento, OptionRow/EtiquetaAlergia, Rail/RailSimples/ReguaPeso, Reveal, Sheet, Skeleton/EsqueletoDoDia, Steps, Toast, Toggle) e `components/app` (BottomNav, DayRail, ErrorState, NutriBar, OnboardingStep, Screen, TopBar). Vários componentes de domínio ainda vivem **dentro das páginas** (`LinhaRefeicao`, `GraficoPeso`, `EvolucaoVazia`, `Pergunta`, `Resposta`, `Pensando`, `EstadoInicial`).
- **Identidade** 🔵 (manter): tokens em `globals.css` — tinta `#15251C`, papel `#ECEEE7`, gema `#E8A93C` (único acento), mata `#2F6B47` (feito), alerta `#A8342A` (só erro e alergia); Bricolage Grotesque (títulos e números) + Instrument Sans (interface); motivos visuais **linha do dia** (`DayRail`) e **régua** (`Rail`).
- **Movimento generoso** 🔵 (preferência dos autores): transição por segmento (`template.tsx`), cascatas (`cascata()`), barras que enchem (`useMontado`), números que contam (`CountUp`), revelação ao rolar (`Reveal`), microinterações em tudo que se toca — sempre em `transform`/`opacity` e respeitando `prefers-reduced-motion` (estado final imediato).
- ✅ D11: **telas e componentes novos são gerados com o skill `frontend-design:frontend-design`**, pedindo explicitamente **bastante animação** e **fidelidade à identidade visual do mock** (tokens, tipografia, motivos). Vale para: Cadastro, Entrar, Esqueci/Redefinir senha, Trocar senha, Apagar conta, Conversas, Questionário, estados novos (sem plano, vazio de médias).
- **Idioma do código** 🔵: nomes e props em português (`variante`, `marcado`, `aoFechar`, `rotulo`), como já é no projeto.
- **Não criar genérico por criar**: não há `Select`, `Checkbox`, `Radio`, `Modal` nem `Card` genéricos — os mocks resolvem com `Segmento`, `Chip`, `OptionRow`, `Sheet` e cartões de domínio. Um primitivo novo só entra quando há ≥ 3 usos reais.

## 2. Organização

```
src/components/ui/        primitivos sem conhecimento de domínio
src/components/app/       estrutura de tela e navegação
src/features/*/components domínio (plano, nutri, progresso, auth, onboarding, perfil, settings, feedback)
```
Cada componente: `Nome.tsx` + `Nome.stories.tsx` (estados reais + `play`) na mesma pasta; `Nome.test.tsx` só quando houver lógica além do que a story cobre.
**Regra de composição:** páginas (`src/app/**/page.tsx`) só compõem componentes e chamam hooks; nenhuma página reimplementa um primitivo (ex.: o seletor de período da Evolução passa a ser `Segmento`; os botões de dia da Rotina e da Dieta passam a `DayToggleGroup`/`WeekDayPicker`).

## 3. Storybook

- **Versão/framework:** Storybook 10, `@storybook/nextjs-vite` (App Router, Tailwind 4 via Vite).
- **Addons:** `@storybook/addon-vitest` (stories viram testes, modo browser/Playwright), `@storybook/addon-a11y` (`parameters.a11y.test = 'error'`), `@storybook/addon-docs` (autodocs a partir das props), `msw-storybook-addon` (componentes de domínio que buscam dado).
- **`preview.tsx`:** importa `globals.css` e as fontes; fundo `papel` e variante escura (`tinta`) como *backgrounds*; *global toolbar* "Movimento: normal / reduzido" que força `prefers-reduced-motion` (emulação via classe no `<html>` lida por `motion.ts`); viewport padrão 390×844; decorator com `QueryClientProvider` e `Toaster`.
- **Títulos:** `UI/Button`, `App/TopBar`, `Plano/DayRail`, `Nutri/SwapCard`, `Evolução/WeightChart`, `Conta/LoginForm`…
- **Stories obrigatórias por componente com dado remoto:** `Carregando`, `Vazio` (quando existir), `Erro`, `Sucesso`; **com animação:** `MovimentoReduzido`.
- **Publicação:** `storybook build` no CI como artefato 🟡 (útil para mostrar a biblioteca no relatório da atividade).

## 4. Catálogo — primitivos (`components/ui`)

Formato: **Responsabilidade · Props · Variantes · Estados · Comportamento · A11y · Responsivo · Composição · Stories (play)**.

### Button / ButtonLink 🔵 (evolui)
- **Responsabilidade:** ação principal/secundária; `ButtonLink` para navegação com aparência de botão.
- **Props:** `variante: 'primaria' | 'contorno' | 'contorno-escuro' | 'texto' | 'destrutiva'` (**nova**); `tamanho: 'grande' | 'media' | 'pequena'`; `carregando?: boolean` (**nova**); `rotuloCarregando?: string`; `icone?: ReactNode` + `iconePosicao?: 'inicio' | 'fim'` (**nova**); `disabled`; props nativas.
- **Estados:** normal, hover, pressionado (escala 0,98), foco (outline), desabilitado (opacidade 0,45, sem hover), carregando (spinner + rótulo, `aria-busy`, clique ignorado, largura preservada).
- **A11y:** `<button type="button">` por padrão; `ButtonLink` é `<Link>`; ícone sozinho exige `aria-label`.
- **Responsivo:** `grande` ocupa 100% (rodapés das telas).
- **Stories:** Primaria, Contorno, ContornoEscuro (fundo tinta), Texto, Destrutiva, Desabilitado, Carregando, ComIcone, Tamanhos. **play:** executa `onClick`; não executa em `disabled`/`carregando`; `aria-busy` no carregando.

### Field / PasswordField / Segmento 🔵 (evolui)
- **Field — Props:** `id`, `label`, `sufixo?`, `ajuda?`, `erro?: string` (**novo**), `aviso?: string` (**novo**, tom gema), props de `<input>`.
- **PasswordField (novo, usa Field):** botão "Mostrar/Ocultar senha" (`aria-pressed`).
- **Segmento — Props:** `label?`, `opcoes: {valor, rotulo}[]`, `valor`, `onChange`, `ajuda?`, `disabled?`.
- **Estados:** vazio, preenchido, foco, erro (borda `alerta`, mensagem com ícone), aviso, desabilitado.
- **A11y:** `label` ligado ao `id`; `aria-describedby` → ajuda + erro/aviso; `aria-invalid` com erro; Segmento como `radiogroup` com setas.
- **Stories:** Vazio, ComSufixo, ComAjuda, ComErro, ComAviso, Desabilitado, Senha (alternar), Segmento (3 opções, desabilitado). **play:** digitar; alternar senha; setas no Segmento.

### Chip 🔵
- **Props:** `marcado`, `onClick`, `removivel?` (**novo**, mostra ×, `aoRemover`), `disabled?`.
- **A11y:** `aria-pressed`; removível com botão interno rotulado "Remover {texto}".
- **Stories:** Desmarcado, Marcado, Removivel, EmCascata (grupo animado). **play:** alterna `aria-pressed`.

### OptionRow + EtiquetaAlergia 🔵
- **Props:** `marcado`, `onClick`, `titulo`, `descricao?`, `etiqueta?`, `quadrado?` (checkbox) / redondo (radio), `compacto?`.
- **A11y:** `role="radio"` ou `"checkbox"` conforme `quadrado`, `aria-checked`; grupo pai com `role=radiogroup`/`group` e `aria-label`.
- **Stories:** Radio, Checkbox, ComDescricao, ComAlergia, Compacto. **play:** clique alterna `aria-checked`.

### Toggle 🔵
- **Props:** `ligado`, `onChange`, `rotulo`, `descricao?`, `disabled?` (**novo**), `salvando?` (**novo**).
- **A11y:** `role="switch"`, `aria-checked`, descrição via `aria-describedby`.
- **Stories:** Ligado, Desligado, Desabilitado, Salvando.

### Sheet 🔵
- **Responsabilidade:** folha inferior modal (substitui Modal/Dialog em todo o app).
- **Props:** `aberta`, `aoFechar`, `titulo`, `descricao?`, `children`, `tom?: 'padrao' | 'destrutivo'` (**novo** — título em alerta para apagar conta/conversa).
- **Comportamento:** entra de baixo com fundo esmaecido; fecha no fundo, no `Esc` e no arraste para baixo 🟡; trava rolagem do corpo; devolve o foco a quem abriu.
- **A11y:** `role="dialog"`, `aria-modal`, `aria-labelledby`/`describedby`, *focus trap*.
- **Stories:** Aberta, ComLista, Destrutiva, ConteudoLongo (rolagem interna). **play:** foco inicial dentro; `Tab` não sai; `Esc` chama `aoFechar`.

### Toast 🔵
- **Props:** `texto`, `acao?: {rotulo, onClick}`, `aoExpirar?`, `segundos?` (padrão 6), `tom?: 'padrao' | 'erro'` (**novo**).
- **Comportamento:** barra de tempo 🔵; pausa com foco/hover; um por vez (fila no `Toaster`).
- **A11y:** `role="status"` (`alert` no tom erro); ação alcançável por teclado.
- **Stories:** ComDesfazer, Erro, Expirando. **play:** ação chamada; `aoExpirar` após o tempo (timers falsos).

### Skeleton / EsqueletoDoDia 🔵
- **Props:** `className`, `atraso?`. **A11y:** `aria-hidden`; o contêiner da tela anuncia "Carregando…" (`aria-busy`).
- **Stories:** Linha, Bloco, EsqueletoDoDia, MovimentoReduzido (sem brilho).

### Steps 🔵
- **Props:** `atual`, `total`. **A11y:** `role="progressbar"` com `aria-valuenow/max` e texto "Etapa {n} de {total}".
- **Stories:** Inicio, Meio, Fim.

### Rail / RailSimples / ReguaPeso 🔵
- **Rail — Props:** `rotulo`, `valor`, `meta`, `unidade?`, `cor?`, `fundo?`, `atraso?`. Acima de 100%: barra cheia + texto "+{excesso}" 🟡.
- **ReguaPeso — Props:** `inicio`, `atual`, `meta` (**pode ser `null`** → não renderiza a meta), `escuro?`, `atraso?`, `unidade?: 'kg' | 'lb'` (**novo**).
- **A11y:** `role="meter"` com `aria-valuenow/min/max` e texto; cor nunca é a única informação.
- **Stories:** Zero, Parcial, Completo, AcimaDaMeta, SemMeta, Escuro, Imperial, MovimentoReduzido (já cheio).

### CountUp / Reveal 🔵
- **CountUp — Props:** `valor`, `casas?`, `duracao?`. Texto final sempre presente para leitores de tela (`aria-label` com o valor final).
- **Reveal — Props:** `atraso?`, `children`. Revela ao entrar na tela (IntersectionObserver).
- **Stories:** Inteiro, Decimal, MovimentoReduzido (valor final imediato).

### EmptyState ✅ novo (generaliza `EvolucaoVazia` e o "vazio" da folha de troca)
- **Props:** `ilustracao?: ReactNode` (SVG em traço, estilo do mock), `titulo`, `descricao`, `acao?: {rotulo, href | onClick}`, `tom?: 'claro' | 'escuro'`.
- **Stories:** Evolucao (sem pesagens), TrocasVazias, DiaSemRegistro, MediasVazias, Obrigado (questionário).

### Aviso ✅ novo (≥ 3 usos: meta fora da faixa, refeição do Nutri com proteína menor, offline, permissão de push)
- **Props:** `tom: 'gema' | 'alerta' | 'mata'`, `titulo?`, `children`, `icone?`.
- **A11y:** `role="status"` (ou `alert` no tom alerta quando é erro).
- **Stories:** Gema, Alerta, Mata, ComTitulo.

### Selo ✅ novo (≥ 3 usos: "Trocado", "Refeição feita", "Próxima refeição", "Alergia", "meta sugerida")
- **Props:** `tom: 'mata' | 'gema' | 'alerta' | 'neutro'`, `icone?`, `pulsante?` (ponto "próxima").
- `EtiquetaAlergia` passa a ser `Selo tom="alerta"`.
- **Stories:** cada tom, Pulsante, MovimentoReduzido.

### FormError ✅ novo
- **Responsabilidade:** erro geral de formulário (rede, 500, credenciais) acima do botão, com ação "Tentar de novo" opcional; recebe `ApiError`.
- **Props:** `erro: ApiError | string | null`, `aoTentarDeNovo?`.
- **A11y:** `role="alert"`; foco movido para ele ao aparecer.
- **Stories:** Rede, Servidor, Credenciais, MuitasTentativas.

## 5. Catálogo — estrutura (`components/app`)

| Componente | Responsabilidade | Props principais | Mudanças | Stories |
|---|---|---|---|---|
| `Screen` 🔵 | contêiner 430 px, áreas seguras, fundo claro/escuro | `escura?`, `children` | `aria-busy` quando carregando | Clara, Escura |
| `TopBar` 🔵 | voltar + conteúdo à direita | `voltarPara`, `rotuloVoltar`, `direita?` | — | Simples, ComSelo, ComNutri |
| `BottomNav` 🔵 | 4 abas (Hoje, Dieta, Evolução, Perfil) | — | `aria-current="page"` na ativa | CadaAbaAtiva |
| `OnboardingStep` 🔵 | moldura das etapas (Steps, voltar, título, descrição, botão) | `etapa`, `voltarPara`, `titulo`, `descricao`, `proximo`→ **`aoContinuar`**, `rotuloProximo?`, `acimaDoBotao?`, **`carregando?`**, **`erro?`**, **`modoEdicao?`**, **`total?`** (reusado no questionário) | salva antes de navegar | Etapa, Salvando, Erro, ModoEdicao, Questionario |
| `ErrorState` 🔵 | erro de carregamento de tela | `titulo`, `descricao`, `aoTentarDeNovo?` | — | ComTentar, SemTentar |
| `NutriBar` 🔵 | atalho para o Nutri com pergunta | `texto`, `href?` | `href` padrão `/nutri?pergunta={texto}` | Padrao, Longo |

## 6. Catálogo — domínio

| Feature | Componente | Origem | Responsabilidade | Estados/variantes (stories) |
|---|---|---|---|---|
| auth | `RegisterForm`, `LoginForm`, `ForgotPasswordForm`, `ResetPasswordForm`, `ChangePasswordForm`, `DeleteAccountSheet` | ✅ novos | formulários da spec 01 | Vazio, Enviando, ErroCampo, ErroGeral, Sucesso |
| onboarding | `GoalWeightField` | ✅ novo | meta + faixa saudável + aviso | Oculto, Vazio, Dentro, Fora, DirecaoErrada |
| onboarding | `RoutineTimes`, `DayToggleGroup` | 🔵 extraídos de Rotina | horários e dias | Padrao, ErroTreino, NenhumDia |
| onboarding | `SummaryList` | 🔵 extraído do Resumo | linhas com "Editar" | ComAlergia, PreviaCarregando |
| perfil | `ProfileHeader`, `GoalCard`, `ProfileMenu` | 🔵 extraídos | cabeçalho, objetivo, lista | ComMeta, MetaSugerida, SemMeta |
| plano | `DayRail` 🔵, `MealRow` (ex-`LinhaRefeicao`), `FoodItemRow`, `SubstitutionSheet`, `WeekDayPicker`, `MacroSummary`, `PlanGenerating`, `PlanReadySummary`, `NoPlanState` | 🔵/✅ | spec 03 | ver spec 03 §8 |
| nutri | `ConversationList`, `ConversationListItem` ✅, `ChatBubble` (ex-`Pergunta`/`Resposta`), `SwapCard`, `MealSuggestionCard`, `NutriActions`, `ContextCard` (ex-`EstadoInicial`), `ThinkingIndicator` (ex-`Pensando`), `ChatComposer`, `OfflineNotice`, `SuggestionList` (perguntas do estado inicial), `SuggestionChips` ✅ (continuações dinâmicas, RN45) | 🔵/✅ | spec 04 | ver spec 04 §9 |
| progresso | `WeightChart` (ex-`GraficoPeso`), `AdherenceGrid`, `AveragesBlock`, `WeightStepper`, `WeightHistory`, `WeightDeltaMessage` | 🔵 | spec 05 | ver spec 05 §8 |
| settings | `NotificationSettings`, `UnitSelector` | 🔵 | spec 06 | ver spec 06 §8 |
| feedback | `RatingButtons`, `LikertQuestion`, `UsabilitySurvey`, `InviteBanner` | ✅ | spec 07 | ver spec 07 §8 |

Componentes de domínio recebem **dados prontos por props** (não chamam hooks de API), para que a story não dependa de rede; a página/contêiner liga o hook. Exceção documentada: `SubstitutionSheet` e `ConversationList` usam MSW nas stories porque carregam dados ao abrir.

## 7. Critérios de aceitação
- **CA01** Todo componente listado em §4–§6 tem story para cada estado citado, e as stories rodam como testes no CI.
- **CA02** Nenhuma story tem violação axe `serious`/`critical`.
- **CA03** Toda story com animação tem `MovimentoReduzido` e, nela, o estado final aparece sem transição.
- **CA04** Nenhuma página em `src/app` define componente visual reutilizável inline (verificado em revisão; os extraídos de §6 saíram das páginas).
- **CA05** As telas novas seguem os tokens de `globals.css` (sem cores fora do `@theme`) e usam as fontes do projeto.

## 8. Test Strategy
- **Component:** as stories com `play` (§4–§6) são os testes de componente — renderização, props, interações, estados, variantes, eventos, desabilitado, carregando, erro, a11y.
- **Unit:** `motion.ts` (`cascata`, `useMontado` com reduced-motion), `format.ts`, `units.ts`.
- **Integration:** composição de páginas com MSW é testada nas specs de feature.
- **Visual (opcional 🟡):** comparação de screenshots das stories principais com Playwright (`toHaveScreenshot`) apenas para `DayRail`, `WeightChart`, `SwapCard` — os mais sensíveis a regressão visual.

## 9. Definition of Done
```
[ ] Storybook 10 configurado (Tailwind, fontes, MSW, a11y, vitest, toolbar de movimento)
[ ] Primitivos §4 com as mudanças marcadas (novo) e stories
[ ] Estrutura §5 atualizada (OnboardingStep com aoContinuar/carregando/erro/modoEdicao)
[ ] Domínio §6 extraído das páginas, com stories
[ ] CI rodando testes de stories + axe
[ ] Componentes/telas novos gerados com frontend-design, animados, na identidade do mock
```
