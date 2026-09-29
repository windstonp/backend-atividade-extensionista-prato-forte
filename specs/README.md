# Prato Forte — Especificação técnica

> Projeto de extensão **"Dietas saudáveis usando tecnologia de ponta"** — UNINTER, Bacharelado em Ciência da Computação, Atividade Extensionista III (Validação da proposta).
> Alunos: Pedro Gomes Antunes (RU 4785771) e João Vitor Assunção Alves (RU 4783813).
> Local de aplicação: academia **Zfit**, bairro Centro, Capivari de Baixo (SC). ODS 03 — Saúde e bem-estar.

Este diretório é o **contrato de implementação** do Prato Forte: backend em Laravel e frontend Next.js já existente (hoje com dados mockados). Cada feature tem requisitos, regras, telas, API, critérios de aceitação e estratégia de testes, definidos antes da implementação.

---

## 1. Como ler estas specs

### 1.1 Fontes e legenda

Toda afirmação relevante é marcada com a sua origem:

| Marca | Significa | Fonte |
|---|---|---|
| 🟢 **DOC** | Requisito identificado no documento da atividade | `Atividades Extensionistas - Validacao da proposta III - 4785771 - 4783813.docx.pdf` |
| 🔵 **MOCK** | Comportamento observado nas telas mockadas | `frontend/src/app/**`, `frontend/src/lib/**` |
| 🟤 **NODE** | Comportamento existente no backend Node.js de referência | `backend (legado node)/src/**` |
| ✅ **DECISÃO** | Decidido com os autores durante o levantamento (2026-09-23) | registro na seção 6 |
| 🟡 **SUGESTÃO TÉCNICA** | Proposta desta especificação, sem base explícita nas fontes | — |

Quando fontes divergem, o conflito está em [`99-inconsistencias.md`](99-inconsistencias.md) — nada foi resolvido em silêncio.

### 1.2 Índice

| Pasta / arquivo | Conteúdo |
|---|---|
| [`00-fundacao/requisitos-nao-funcionais.md`](00-fundacao/requisitos-nao-funcionais.md) | RNFs (segurança, performance, usabilidade, acessibilidade, privacidade, logs…) |
| [`00-fundacao/regras-de-negocio.md`](00-fundacao/regras-de-negocio.md) | **Todas as regras de negócio (RN01…)** centralizadas, com onde aplicar |
| [`00-fundacao/modelo-de-dados.md`](00-fundacao/modelo-de-dados.md) | Entidades, campos, índices, integridade, ER, ordem das migrations, seeders |
| [`00-fundacao/arquitetura-backend.md`](00-fundacao/arquitetura-backend.md) | Arquitetura Laravel, estrutura de diretórios, classes, filas, agendador |
| [`00-fundacao/integracao-ia.md`](00-fundacao/integracao-ia.md) | Cliente de IA, prompts, contratos JSON, validação, custos |
| [`00-fundacao/api-convencoes-e-erros.md`](00-fundacao/api-convencoes-e-erros.md) | Convenções REST, formato de resposta, padrão de erros, códigos |
| [`00-fundacao/autenticacao-autorizacao.md`](00-fundacao/autenticacao-autorizacao.md) | Sanctum SPA, sessão, CSRF, policies, proteção de rotas |
| [`00-fundacao/seguranca.md`](00-fundacao/seguranca.md) | Riscos e medidas |
| [`00-fundacao/arquitetura-frontend.md`](00-fundacao/arquitetura-frontend.md) | Estrutura Next.js, camada de API, estado, navegação, rotas |
| [`00-fundacao/estrategia-de-testes.md`](00-fundacao/estrategia-de-testes.md) | Pirâmide, ferramentas, estrutura de testes, CI, ciclo de qualidade, DoD geral |
| [`01-contas-autenticacao/spec.md`](01-contas-autenticacao/spec.md) | Cadastro, login, logout, senha, apagar conta |
| [`02-onboarding-perfil/spec.md`](02-onboarding-perfil/spec.md) | Onboarding em 7 etapas, perfil, meta de peso, preferências e restrições |
| [`03-plano-alimentar/spec.md`](03-plano-alimentar/spec.md) | Cálculo de metas, geração do plano com IA, dia, semana, refeição, trocas, desfazer |
| [`04-nutri/spec.md`](04-nutri/spec.md) | Assistente de IA: conversas, contexto, memória, ações |
| [`05-evolucao/spec.md`](05-evolucao/spec.md) | Pesagens, gráfico, previsão, constância, médias |
| [`06-configuracoes-notificacoes/spec.md`](06-configuracoes-notificacoes/spec.md) | Web Push, lembretes, resumo semanal, dicas, unidade de medida |
| [`07-validacao-feedback/spec.md`](07-validacao-feedback/spec.md) | 👍/👎, questionário de usabilidade, exportação para o relatório |
| [`08-design-system/spec.md`](08-design-system/spec.md) | Biblioteca de componentes, Storybook, testes de componente |
| [`99-inconsistencias.md`](99-inconsistencias.md) | Conflitos entre documento, Node e mocks — e decisões pendentes |

### 1.3 Estrutura de cada spec de feature

1. Contexto e fontes
2. Requisitos funcionais (RF) — atores, pré-condições, fluxo principal, alternativos, regras
3. Fluxos ponta a ponta
4. Telas ↔ backend (dados, ações, endpoints, estados, validações)
5. API (endpoints detalhados)
6. Validações
7. Critérios de aceitação (Dado / Quando / Então)
8. Test Strategy (Component / Unit / Integration / E2E)
9. Definition of Done

---

## 2. Visão geral do produto

**Nome:** Prato Forte (nome usado no frontend; o documento usa o título "Dietas saudáveis usando tecnologia de ponta").

**Objetivo** 🟢 — aplicação web de planejamento alimentar que ajuda o usuário a montar refeições mais saudáveis, usando IA generativa para gerar recomendações e cardápios personalizados a partir do perfil e das restrições de cada usuário; validada junto a um grupo de usuários da comunidade.

**Problema resolvido** 🟢🔵 — quem treina (ou quer começar a cuidar da alimentação) em Capivari de Baixo não tem acompanhamento nutricional acessível; dietas genéricas não cabem na rotina real (horário de trabalho, treino, marmita) nem na comida que a pessoa tem em casa. O app monta um cardápio com os alimentos que a pessoa já come, encaixado nos horários dela, e ajusta quando o dia sai do plano.

**Público-alvo** 🟢 — moradores da região e alunos da academia Zfit. Perfil: adultos, uso predominantemente no celular 🔵, letramento digital variado (o documento pede interface "acessível para diferentes perfis de usuários").

**Escopo do MVP** (detalhe em §5):
- Conta (cadastro, login, senha, exclusão) ✅
- Onboarding em 7 etapas + perfil + meta de peso 🔵✅
- Plano alimentar gerado por IA com metas calculadas, dia, semana, trocas, desfazer 🟢🔵✅
- Nutri — assistente de IA com contexto, conversas e memória 🟢🔵✅
- Evolução — pesagens, gráfico, previsão, constância 🔵
- Configurações — notificações Web Push, unidade de medida 🔵✅
- Validação com a comunidade — avaliações 👍/👎, questionário de usabilidade, exportação 🟢✅

**Fora do escopo:** painel administrativo, múltiplos papéis, upload de avatar, pagamentos, integração com balança/wearables, app nativo.

---

## 3. Arquitetura geral

```
┌──────────────────────────────┐        HTTPS + cookie de sessão (Sanctum SPA)       ┌────────────────────────────────┐
│ Frontend — Next.js 16        │ ──────────────────────────────────────────────────▶ │ Backend — Laravel 12 (PHP 8.3) │
│ React 19 · TS · Tailwind 4   │   /api/v1/*  JSON                                   │ API REST                       │
│ Storybook · Vitest · MSW     │ ◀────────────────────────────────────────────────── │ Sanctum · Policies · Pest      │
│ Service Worker (Web Push)    │                                                     │                                │
└──────────────┬───────────────┘                                                     └───┬──────────┬───────────┬─────┘
               │ push (VAPID)                                                            │          │           │
               ▼                                                                         ▼          ▼           ▼
   Serviços de push dos navegadores  ◀──────────── notificações ─────────────── Fila (driver database)  MySQL 8   API de IA
   (Google / Mozilla / Apple — gratuitos)                                       + Scheduler (cron)      Eloquent  (OpenAI-compatível:
                                                                                                                    aimlapi.com hoje)
```

- **Stack obrigatória** 🟢: Next.js + TypeScript + Tailwind no frontend; Laravel (PHP) + MySQL + Eloquent no backend; integração via API REST; IA generativa integrada ao backend.
- **Frontend**: o projeto `frontend/` existente é mantido. Toda troca de mock → API acontece em `src/lib/api/` (ver `00-fundacao/arquitetura-frontend.md`).
- **Backend**: projeto Laravel novo (`backend/`), **não é uma conversão** do Node. O Node serviu apenas para entender decisões (bcrypt, chat com histórico, prompt de nutricionista) e para listar o que **não** repetir (ver `99-inconsistencias.md`).
- **Custos** ✅: todo o software é livre; Web Push é gratuito; o único custo variável é a API de IA (já contratada). Requisitos de infraestrutura: HTTPS (Let's Encrypt), cron a cada minuto e um worker de fila em execução contínua, SMTP (gratuito: Gmail com senha de app ou Brevo).

### 3.1 Decisão central — plano híbrido validado ✅

A IA **escolhe** alimentos; o **backend calcula e garante**:

1. `NutritionCalculator` calcula metas (kcal, proteína, carboidrato, gordura) de forma determinística.
2. `FoodFilter` monta a lista de alimentos **permitidos** (catálogo − restrições − alergias − "outras restrições" − "não curto").
3. A IA recebe perfil, metas, horários e **apenas** a lista permitida, e devolve JSON estruturado (`slot → [{food_id, grams}]`).
4. `PortionAdjuster` + `PlanValidator` recalculam macros a partir do catálogo e rejeitam qualquer alimento fora da lista.
5. Trocas de alimento vêm do catálogo (`SubstitutionFinder`), sem IA. Ações do Nutri passam pelo mesmo validador.

Consequência: números confiáveis, **alergia garantida em código**, e todo o domínio testável sem chamar a IA real (`FakeAiClient`).

---

## 4. Glossário

| Termo | Definição |
|---|---|
| **Plano** (`meal_plan`) | Modelo semanal de 5 refeições gerado pela IA. Um usuário tem no máximo um plano **ativo**. |
| **Refeição-modelo** (`plan_meal`) | Uma das 5 refeições do plano, por *slot*: `cafe`, `lanche`, `almoco`, `pre-treino`, `jantar`. |
| **Dia** (`day_meal`) | Cópia do plano para uma data, criada sob demanda ("materializada"). É onde vivem "feita", trocas e desfazer. |
| **Troca** | Substituição de um alimento por outro equivalente, válida só naquele dia. |
| **Nutri** | O assistente de IA. Nome vindo dos mocks (o Node usava "NutriTodos"). |
| **Conversa** | Sequência de mensagens com o Nutri. O usuário escolhe continuar uma anterior ou abrir uma nova. |
| **Memória** | Resumos das conversas anteriores, enviados como contexto à IA. |
| **Ação do Nutri** | Proposta estruturada anexada a uma resposta (trocar alimento, aplicar refeição) que o app sabe executar. |
| **Constância** | Status de adesão de cada dia: completo, parcial, vazio, hoje. |
| **Registro alimentar** ✅ | Interpretação adotada para o termo do documento: refeições marcadas como feitas + trocas aplicadas. |
| **Catálogo** | Tabela de alimentos com macros por 100 g (base TACO), grupo, medida caseira e restrições associadas. |
| **Rodada de validação** | Período em que o grupo da comunidade usa o app e responde o questionário (configurável). |

---

## 5. Escopo

### 5.1 MVP / obrigatório

| Feature | Itens | Origem |
|---|---|---|
| Contas | cadastro, login, logout, recuperar senha, trocar senha, apagar conta, consentimento LGPD | ✅ 🔵 🟡 (consentimento) |
| Onboarding / perfil | 7 etapas salvas no servidor, retomada, meta de peso, edição posterior, preferências e restrições | 🔵 ✅ |
| Plano alimentar | cálculo de metas, geração assíncrona por IA, validação, dia, semana, detalhe, marcar feita, trocar, desfazer, refazer plano | 🟢 🔵 ✅ |
| Nutri | conversas (nova/continuar/apagar), contexto, memória por resumos, ações aplicáveis, sugestões | 🟢 🔵 ✅ |
| Evolução | registrar peso, gráfico, previsão, constância 28 dias, médias do período | 🔵 |
| Configurações | Web Push (lembrete de refeição, resumo semanal, dicas), unidade métrica/imperial | 🔵 ✅ |
| Validação | 👍/👎 em respostas e no plano, questionário de usabilidade, `artisan validacao:exportar` | 🟢 ✅ |
| Qualidade | Storybook, testes de componente/unit/integração/E2E, CI | pedido dos autores |

### 5.2 Pode ser implementado posteriormente

- Alimentos "não curto" voltando a ser sugeridos de vez em quando (texto do mock; no MVP nunca são sugeridos).
- Plano com cardápios diferentes por dia da semana / dias de treino × descanso com metas diferentes.
- Regeneração automática semanal do plano.
- Apagar pesagem individual; editar pesagem antiga.
- Streaming (SSE) das respostas do Nutri.
- Registro livre do que foi comido fora do plano (interpretação alternativa do documento, descartada no MVP ✅).
- Painel administrativo; métricas em tempo real.
- Busca livre no catálogo de alimentos ao adicionar item.
- Internacionalização.

---

## 6. Registro de decisões (2026-09-23)

| # | Decisão | Alternativas descartadas |
|---|---|---|
| D1 | "Registros alimentares" do documento = marcar refeição feita + trocas. A IA usa esse histórico. | registro livre; deixar em aberto |
| D2 | Conta criada **antes** do onboarding; onboarding salvo no servidor etapa a etapa; "Já tenho conta" abre Login. | conta ao fim do onboarding; link mágico |
| D3 | Validação com a comunidade: 👍/👎 nas respostas do Nutri e no plano + questionário curto de usabilidade. | formulário externo; só 👍/👎 |
| D4 | Um único papel (usuário). Dados da validação extraídos por comando artisan. | admin via API; painel admin |
| D5 | MVP inclui trocar/recuperar senha, apagar conta, notificações e unidade imperial. | — |
| D6 | Plano híbrido validado (§3.1). | IA gera tudo; tudo determinístico |
| D7 | Sanctum SPA (cookie) como autenticação. | JWT do Node |
| D8 | Meta de peso: campo opcional com coerência de direção; fora do IMC 18,5–24,9 **só avisa, nunca bloqueia**. | bloquear abaixo de 18,5; bloquear fora da faixa |
| D9 | Chat com várias conversas: antes de abrir, o usuário escolhe continuar uma conversa ou começar outra; a IA recebe resumos das últimas conversas. | conversa única |
| D10 | Ferramentas de teste: Pest (back); Storybook 10 + Vitest + Testing Library + MSW + Playwright (front). | — |
| D11 | Telas novas geradas com o skill `frontend-design:frontend-design`, com bastante animação e na identidade visual do mock. | — |
| D12 | Sugestões de continuação do Nutri geradas pela IA a cada resposta (RN45), com filtro e reserva determinística. Nenhum dado de domínio fixo no front: checagem no CI proíbe importar mocks fora de `src/mocks/`, stories e testes. | chips fixos do mock |

Decisões **pendentes** (precisam de resposta dos autores antes da implementação da feature afetada) estão no fim de `99-inconsistencias.md`.

---

## 7. Rastreabilidade

### 7.1 Documento → Requisitos → Telas → Entidades → APIs → Regras

| Objetivo do documento 🟢 | RFs | Telas | Entidades | APIs | RNs |
|---|---|---|---|---|---|
| **O1** Planejamento alimentar com IA gerando cardápios personalizados a partir do perfil e restrições | RF07–RF18 | S02–S13, S17, S18 | profiles, foods, restrictions, pantry_items, meal_plans, plan_meals, plan_meal_items, day_meals, day_meal_items | `/onboarding/*`, `/profile*`, `/plans*`, `/days/*` (inclui `/days/{date}/items/{item}/substitutions`) | RN08–RN27 |
| **O2** Arquitetura desacoplada Next.js + Laravel + MySQL/Eloquent via API REST | todos | todas | todas | todas | RN43 |
| **O3** IA processa registros alimentares e produz sugestões de melhoria | RF09, RF19–RF22, RF29 | S09, S14, N05 | nutri_conversations, nutri_messages, day_meals (histórico), ai_requests | `/plans`, `/conversations*`, `/messages/*/actions/*`, `/nutri/*` | RN17, RN28–RN33 |
| **O4** Interface agradável, responsiva e acessível | — (RNF) | todas | — | — | RNF-USA, RNF-ACE |
| **O5** Validar com usuários da comunidade coletando feedback de usabilidade e utilidade | RF31–RF33 | S10, S14, N08, S17 | ratings, usability_responses, ai_requests | `/ratings`, `/usability-responses`, `artisan validacao:exportar` | RN40–RN42 |

### 7.2 Requisitos funcionais → feature

| RF | Nome | Spec |
|---|---|---|
| RF01–RF06 | Cadastro, login, logout, recuperar senha, trocar senha, apagar conta | 01 |
| RF07–RF08, RF16–RF17 | Onboarding, prévia de metas, editar perfil, preferências e restrições | 02 |
| RF09–RF15, RF18 | Gerar plano, dia, semana, detalhe, marcar feita, trocar, desfazer, refazer plano | 03 |
| RF19–RF22 | Conversas, perguntar, aplicar ação, memória | 04 |
| RF23–RF25 | Registrar peso, evolução, constância e médias | 05 |
| RF26–RF30 | Notificações, lembretes, resumo semanal, dicas, unidade | 06 |
| RF31–RF33 | Avaliar, questionário, exportar | 07 |

### 7.3 Telas → feature

| Id | Rota | Tela | Spec |
|---|---|---|---|
| S01 | `/` | Boas-vindas 🔵 | 01 |
| N01 | `/cadastro` | Criar conta ✅ (nova) | 01 |
| N02 | `/entrar` | Login ✅ (nova) | 01 |
| N03 | `/senha/esqueci` | Esqueci minha senha ✅ (nova) | 01 |
| N04 | `/senha/redefinir` | Redefinir senha ✅ (nova) | 01 |
| N06 | `/perfil/configuracoes/senha` | Trocar senha ✅ (nova) | 01 |
| N07 | sheet em `/perfil/configuracoes` | Apagar conta ✅ (nova) | 01 |
| S02–S08 | `/onboarding/{objetivo,dados,atividade,preferencias,restricoes,rotina,resumo}` | Onboarding 🔵 | 02 |
| S17 | `/perfil` | Perfil 🔵 | 02 |
| S18 | `/perfil/preferencias` | Preferências e restrições 🔵 | 02 |
| S09 | `/onboarding/gerando` | Gerando plano 🔵 | 03 |
| S10 | `/onboarding/pronto` | Plano pronto 🔵 | 03 |
| S11 | `/hoje` | Hoje 🔵 | 03 |
| S12 | `/dieta` | Dieta (semana) 🔵 | 03 |
| S13 | `/dieta/[slot]` | Detalhe da refeição + folha de troca 🔵 | 03 |
| N05 | `/nutri` | Conversas ✅ (nova) | 04 |
| S14 | `/nutri/[conversa]` | Chat do Nutri 🔵 | 04 |
| S15 | `/evolucao` | Evolução 🔵 | 05 |
| S16 | `/evolucao/peso` | Registrar peso 🔵 | 05 |
| S19 | `/perfil/configuracoes` | Configurações 🔵 | 06 |
| N08 | `/perfil/avaliar` | Questionário de usabilidade ✅ (nova) | 07 |

### 7.4 Navegação

```
/ (Boas-vindas)
├── "Montar meu plano" ─▶ /cadastro ─▶ /onboarding/objetivo ─▶ … ─▶ /onboarding/resumo ─▶ /onboarding/gerando ─▶ /onboarding/pronto ─▶ /hoje
└── "Já tenho conta"  ─▶ /entrar ─┬─▶ onboarding incompleto ─▶ /onboarding/{primeira etapa pendente}
                                  └─▶ onboarding completo   ─▶ /hoje
                         /entrar ─▶ "Esqueci minha senha" ─▶ /senha/esqueci ─▶ (e-mail) ─▶ /senha/redefinir ─▶ /entrar

BottomNav (app autenticado e com onboarding completo): Hoje · Dieta · Evolução · Perfil
/hoje ─▶ DayRail (marcar) · /dieta/[slot] · /evolucao · /perfil · NutriBar ─▶ /nutri
/dieta ─▶ /dieta/[slot] (só hoje) ─▶ folha de troca · NutriBar ─▶ /nutri (nova conversa com pergunta)
/nutri ─▶ "Nova conversa" / conversa existente ─▶ /nutri/[conversa] ─▶ ação "ver refeição" ─▶ /dieta/[slot]
/evolucao ─▶ /evolucao/peso
/perfil ─▶ /onboarding/* (modo edição) · /perfil/preferencias · /perfil/configuracoes · /perfil/avaliar · "Refazer meu plano" ─▶ /onboarding/gerando
/perfil/configuracoes ─▶ /perfil/configuracoes/senha · apagar conta · sair ─▶ /
```

---

## 8. Checklist final da especificação

### Produto
- [x] Todos os fluxos do documento foram mapeados (§7.1 — o documento tem 5 objetivos, sem fluxos explícitos)
- [x] Fluxos do frontend foram analisados (19 telas mockadas, `lib/api.ts`, stores)
- [x] Gaps identificados (`99-inconsistencias.md`)
- [x] Features faltantes levantadas (auth, conversas, feedback, meta de peso)
- [x] Features novas submetidas à decisão antes de especificadas (D1–D9)
- [x] Fluxos incompletos identificados (pendências P1–P4 em `99-inconsistencias.md`)

### Frontend
- [x] Telas mapeadas (§7.3)
- [x] Navegação mapeada (§7.4)
- [x] Estados de loading / vazio / erro / sucesso definidos (seção "Telas" de cada spec)
- [x] Componentes reutilizáveis identificados e biblioteca definida (`08-design-system`)
- [x] Storybook planejado

### Backend
- [x] Entidades e banco definidos (`modelo-de-dados.md`)
- [x] APIs definidas (seção "API" de cada spec + convenções)
- [x] Regras de negócio definidas (`regras-de-negocio.md`)
- [x] Autenticação e autorização definidas

### Testing
- [x] Component tests e Stories (`08-design-system`, Test Strategy de cada feature)
- [x] Unit, Integration, E2E (`estrategia-de-testes.md` + cada feature)
- [x] Critérios de aceitação e Definition of Done por feature

### Specifications
- [x] Cada feature possui specification, critérios de aceitação e estratégia de testes
- [x] Frontend e backend relacionados (seção "Telas ↔ backend")
- [x] APIs e entidades relacionadas às features (§7)
- [x] Specs organizadas em `/specs` na raiz; este README documenta a arquitetura geral
