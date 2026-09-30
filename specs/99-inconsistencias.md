# Pontos de atenção e inconsistências

Comparação entre **Documento** (🟢 proposta da atividade), **Node** (🟤 backend de referência) e **Mocks** (🔵 frontend atual).
Status: **Decidido** (✅ com os autores) · **Recomendado** (adotado nesta spec como sugestão técnica; pode ser revertido) · **Pendente** (precisa de resposta antes da feature afetada).

---

## A. Documento × implementação existente

### I01 — Stack do backend · Decidido
- **Problema:** o documento define Laravel + MySQL + Eloquent; o backend existente é Node + Express + TypeORM + PostgreSQL (fork do boilerplate "RentX").
- **Onde:** documento p. 3; `backend (legado node)/package.json`, `ormconfig.json`, `README.md`.
- **Impacto:** todo o backend é refeito.
- **Opções:** manter Node; migrar para Laravel.
- **Recomendação:** Laravel (requisito do documento e decisão dos autores). O Node vira só referência.

### I02 — "Registros alimentares" · Decidido (D1)
- **Problema:** o documento diz que a IA deve "processar os registros alimentares dos usuários"; os mocks só permitem marcar refeições planejadas como feitas e trocar alimentos.
- **Onde:** documento p. 3; `lib/plan-store.tsx`.
- **Impacto:** define se há tela de registro livre.
- **Opções:** marcar+trocas bastam; registro livre; deixar em aberto.
- **Recomendação/decisão:** marcar+trocas = registro alimentar; entram no contexto do Nutri (RN29) e nas dicas/resumo (spec 06). Registro livre fica para depois. **Registrar essa interpretação no relatório.**

### I03 — Validação com a comunidade sem suporte no produto · Decidido (D3, D4)
- **Problema:** o documento exige coletar feedback de usabilidade e utilidade; nenhum mock cobre.
- **Onde:** documento p. 3.
- **Recomendação/decisão:** 👍/👎 + questionário SUS + exportação por comando (spec 07).

## B. Mocks × necessidades do produto

### I04 — Sem cadastro nem login · Decidido (D2)
- **Problema:** nenhuma tela de conta; "Já tenho conta" e o link `sr-only` "Pular para o app" levam direto a `/hoje`.
- **Onde:** `src/app/page.tsx`.
- **Impacto:** sem identidade não há plano persistente.
- **Recomendação/decisão:** conta antes do onboarding; telas N01–N04; remover "Pular para o app".

### I05 — Meta de peso exibida mas nunca perguntada · Decidido (D8)
- **Problema:** Perfil, Hoje e Evolução mostram `goalWeightKg`; o onboarding não pergunta.
- **Onde:** `lib/types.ts` (`Profile.goalWeightKg`), `onboarding/dados/page.tsx`.
- **Recomendação/decisão:** RN10 (campo opcional em Dados; coerência de direção; sugestão ±5%; só avisa fora da faixa).

### I06 — Chat único × vários chats · Decidido (D9)
- **Problema:** Node tem vários chats por usuário; o mock mostra uma conversa só, que se perde ao sair.
- **Onde:** `chats.routes.ts`; `(app)/nutri/page.tsx`.
- **Recomendação/decisão:** tela Conversas (nova/continuar), memória por resumos (RN28–RN30).

### I07 — Listas de restrições e de cozinha diferentes entre telas · Recomendado
- **Problema:** onboarding tem 6 restrições e 17 itens de cozinha; Preferências do perfil tem 4 restrições (sem "não come carne" e "nada de origem animal") e 14 itens (sem Cuscuz, Maçã, Laranja). Usuário que marcou "sem carne" no onboarding não consegue desmarcar no perfil.
- **Onde:** `onboarding/restricoes`, `onboarding/preferencias`, `perfil/preferencias`.
- **Recomendação:** as duas telas usam o catálogo de `GET /catalog/onboarding`.

### I08 — "Entra no plano da semana que vem" · Recomendado
- **Problema:** o texto de Preferências sugere regeneração semanal automática; não existe plano semanal por data nem agendamento, e alergia não pode esperar uma semana.
- **Onde:** `perfil/preferencias/page.tsx`.
- **Opções:** regenerar toda semana (custo de IA recorrente); regenerar na hora sempre; regra por tipo de mudança.
- **Recomendação:** RN21 (restrição/alergia refaz na hora; o resto sugere "Refazer") e trocar o texto.

### I09 — "Não curto" pode voltar "de vez em quando" · Recomendado
- **Problema:** texto do mock promete reintroduzir alimentos não curtidos; não há regra para isso.
- **Recomendação:** no MVP nunca sugerir (RN16); remover a frase; reintrodução fica para depois.

### I10 — "O plano se reequilibra sozinho no fim do dia" · Recomendado
- **Problema:** texto da Dieta promete reequilíbrio automático; nada no mock ou no documento define isso.
- **Recomendação:** não implementar no MVP; trocar o texto (spec 03 S12). Reequilíbrio pode vir depois via Nutri.

### I11 — Dias passados na Dieta aparecem como não feitos · Recomendado
- **Problema:** o mock zera `done` para todo dia que não é hoje.
- **Recomendação:** passado mostra o status real, somente leitura (RN22).

### I12 — Números fixos no mock · Recomendado
- **Problema:** "1.950 kcal"/"120 g" fixos no Resumo; médias 112/120 e 1.870/1.950 fixas na Evolução; "em {n} semanas" usa o número de pesagens; peso inicial da tela de pesagem = peso do perfil + 0,2; `aplicarRefeicao` divide os macros igualmente entre os itens.
- **Onde:** `onboarding/resumo`, `(app)/evolucao`, `(app)/evolucao/peso`, `lib/plan-store.tsx`.
- **Recomendação:** tudo calculado no backend (RN13, RN24, RN31, RN37) ou a partir da última pesagem.

### I13 — Atalho "Não tenho {item} em casa" usa o 3º item fixo · Recomendado
- **Problema:** `meal.items[2]` no Detalhe — em refeições com < 3 itens vira "um dos alimentos"; nem sempre é a proteína.
- **Recomendação:** usar o principal item do grupo proteína da refeição (fallback: o de maior kcal).

### I14 — Confirmação do Nutri sempre cita o almoço · Recomendado
- **Problema:** `confirmar()` fixa "Seu almoço de hoje…" e `mealId: "almoco"`.
- **Recomendação:** confirmação montada pelo backend com o slot da ação (spec 04).

### I15 — Garantia de restrição cita só a primeira · Recomendado
- **Problema:** a folha de troca diz "Nenhuma dessas opções tem {restrictions[0]}".
- **Recomendação:** listar todas as restrições do usuário (spec 03 S13).

### I16 — Objetivo pré-selecionado no onboarding · Recomendado
- **Problema:** `RESPOSTAS_INICIAIS` já marca "ganhar-massa", sexo "feminino", rotina 06:20/19:00/23:00 e marmita — o usuário pode avançar sem escolher.
- **Recomendação:** sem pré-seleção em objetivo e sexo; horários podem vir sugeridos (ajudam a digitação) mas visíveis.

### I17 — Rota de edição do perfil reaproveita o onboarding · Recomendado
- **Problema:** links do Perfil vão para `/onboarding/dados` e `/onboarding/rotina`, cujo botão leva à próxima etapa.
- **Recomendação:** modo edição (`?editar=1`) com "Salvar" e retorno ao Perfil (spec 02).

### I18 — "Refazer meu plano com o Nutri" · Recomendado
- **Problema:** no Perfil, a barra do Nutri diz "Refazer meu plano", mas o Nutri não refaz planos.
- **Recomendação:** botão "Refazer meu plano" (spec 03 RF18), separado do atalho do Nutri.

### I27 — Chips de continuação fixos no chat · Decidido (D12)
- **Problema:** depois de cada resposta, o mock mostra sempre "E no jantar?", "Por que mais fibra?", "O que como antes do treino?", independentemente do assunto.
- **Onde:** `(app)/nutri/page.tsx`.
- **Impacto:** sugestões fora de contexto; dado de domínio fixo no front.
- **Recomendação/decisão:** IA devolve até 3 sugestões por resposta, filtradas pelo backend, com reserva determinística (RN45).

### I28 — Dado mockado importado direto pelas telas · Decidido (D12)
- **Problema:** além de `lib/api.ts`, as telas `onboarding/pronto` (`mockDayPlan`) e `(app)/nutri` (`mockSuggestions`) importam `mock-data.ts` direto, contornando a camada de API.
- **Recomendação/decisão:** `mock-data.ts` vira fixture em `src/mocks/fixtures/`; regra de lint no CI impede importar de `src/mocks/` fora de stories, testes e do bootstrap do MSW (ver `estrategia-de-testes.md` §6).

## C. Node × proposta (o que não portar)

### I19 — Chats sem verificação de dono · Corrigido na spec
- **Problema:** `GET /chats/:id`, `DELETE /chats/:id`, `POST /messages/:chat_id` aceitam qualquer ID.
- **Recomendação:** Policies + 404 (RN43) + testes de posse.

### I20 — Resposta da IA não volta ao cliente · Corrigido na spec
- **Problema:** `createMessageController` consome o stream e responde `201` sem corpo.
- **Recomendação:** `POST /conversations/{id}/messages` devolve as duas mensagens (spec 04).

### I21 — Segredos no código e chave exposta · **Ação imediata**
- **Problema:** `OPENAI_KEY` em `.env` commitado e como padrão em `openai-provider.ts` (com `console.log`); segredos JWT em `config/auth.ts`; senha do Postgres em `ormconfig.json`.
- **Recomendação:** revogar a chave da aimlapi.com agora; segredos só em ambiente (RN44).

### I22 — JWT inconsistente · Corrigido na spec
- **Problema:** middleware valida o token com o segredo do **refresh**; controllers leem token de body/header/query e decodificam por conta própria.
- **Recomendação:** Sanctum SPA (D7).

### I23 — Rotas e recursos quebrados · Descartado
- **Problema:** `password.routes.ts` e avatar nunca montados; `User` sem coluna `avatar`; seed de admin insere `is_admin` inexistente e e-mail `admin@rentx.com.br`; e-mail de senha assinado "Rentx".
- **Recomendação:** recuperar senha reescrita (spec 01); sem avatar (mocks usam iniciais); sem admin (D4).

### I24 — System prompt gravado como mensagem, em inglês, com outro nome · Corrigido na spec
- **Problema:** "You are a nutricionist called NutriTodos…" salvo como mensagem `system` no chat de boas-vindas; o produto chama o assistente de "Nutri".
- **Recomendação:** prompt versionado em código, em português, nome "Nutri" (`integracao-ia.md` §4).

### I25 — Recuperação de senha revela contas · Corrigido na spec
- **Problema:** `SendForgotPasswordEmailUseCase` lança "User does not exists".
- **Recomendação:** RN04.

### I26 — Histórico completo enviado à IA · Corrigido na spec
- **Problema:** o Node envia todas as mensagens do chat a cada pergunta (custo crescente sem limite).
- **Recomendação:** 20 últimas + resumos (RN29).

## D. Pendências (decisão dos autores)

### P1 — "Treina na Zfit desde agosto" · **Pendente**
- **Problema:** o Perfil assume que todo usuário é aluno da Zfit, mas o público do documento inclui **moradores da região** que não treinam lá.
- **Onde:** `(app)/perfil/page.tsx`; documento p. 2 ("envolvendo moradores da região e participantes das atividades da academia").
- **Impacto:** texto incorreto para parte do público; eventual campo novo.
- **Opções:** (a) texto neutro "No Prato Forte desde {mês}"; (b) perguntar na etapa Dados "Você treina na Zfit?" e mostrar o texto só para alunos; (c) manter como está.
- **Recomendação técnica:** (a) — sem campo novo; a Zfit continua citada em Boas-vindas e Configurações. Até a decisão, a spec usa (a).
- **Situação (Plano 09):** aplicada a recomendação (a) — o Perfil diz "No Prato Forte desde {mês}". Confirmar com os autores.

### P2 — Idade mínima · **Pendente**
- **Problema:** nenhuma fonte define idade mínima. A academia pode ter adolescentes; dados de saúde de menores exigem consentimento dos pais (LGPD art. 14) e a fórmula de gasto (RN13) é para adultos.
- **Opções:** 18+; 16+ com aviso; sem limite.
- **Recomendação técnica:** **18+** no MVP (RN09); rever depois com orientação de profissional de nutrição.
- **Situação (Plano 09):** aplicada a recomendação — 18+ (RN09, `ProfileStepRequest`, `between:18,100`).

### P3 — Termo de consentimento (LGPD) · **Pendente (incluído na spec como recomendação)**
- **Problema:** o app coleta dado sensível de saúde; nenhuma fonte prevê consentimento. A pesquisa de validação também usa esses dados (anonimizados).
- **Opções:** checkbox + termo no cadastro; termo só na validação; nada.
- **Recomendação técnica:** checkbox obrigatório no cadastro (RN03) com termo curto que cubra: finalidade (montar o plano), envio de dados à API de IA, uso anonimizado no projeto de extensão, direito de apagar tudo. **O texto do termo precisa ser redigido pelos autores** (possivelmente com orientação do professor/UNINTER).
- **Situação (Plano 09):** termo provisório no cadastro, versão `2026-10` (`frontend/src/features/auth/termo.ts` e `config/prato.php`), já com o uso anonimizado, os comentários livres e o aviso de que não substitui nutricionista. **O texto final é dos autores.**

### P4 — Revisão nutricional · **Pendente (não bloqueia o MVP)**
- **Problema:** fórmulas (RN13), pisos de kcal, faixas de troca (RN25) e regras do prompt são sugestões técnicas, sem validação de nutricionista.
- **Recomendação técnica:** se houver nutricionista parceiro(a) da Zfit, revisar `regras-de-negocio.md` RN13/RN25 e o system prompt antes da rodada de validação; registrar no relatório que o app **não substitui acompanhamento profissional** (texto no termo e no rodapé de Configurações).
- **Situação (Plano 09):** aviso "O Prato Forte não substitui o acompanhamento de um(a) nutricionista." no termo e no rodapé de Configurações. A revisão por nutricionista continua pendente.

### P5 — Valores nutricionais do catálogo · **Pendente (não bloqueia o MVP)**
- **Problema:** `database/data/foods.csv` (Plano 03) foi montado com valores por 100 g da TACO 4ª ed. quando o alimento existe nela, de rótulo ou da tabela USDA quando não existe, e de receita caseira para preparos (ovos mexidos, salada). Ninguém da nutrição conferiu.
- **Impacto:** as metas (RN13) não mudam; as porções e os totais do cardápio (Plano 04) herdam qualquer erro da tabela.
- **Recomendação técnica:** antes da rodada de validação, conferir a planilha com a TACO (e, se possível, com o(a) nutricionista de P4); a coluna `source` diz de onde veio cada linha.
- **Situação (Plano 09):** a coluna `source` de `database/data/foods.csv` diz a origem de cada linha; a conferência com a TACO continua pendente (antes da rodada de validação).
