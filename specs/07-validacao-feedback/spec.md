# 07 — Validação com a comunidade: avaliações, questionário e exportação

## 1. Contexto e fontes
- 🟢 Objetivo explícito do documento: "Validar a aplicação junto a um grupo de usuários da comunidade, coletando feedback sobre **usabilidade** e **utilidade das recomendações** para orientar os ajustes finais do sistema."
- 🔵 Nenhuma tela de feedback nos mocks.
- ✅ D3: 👍/👎 nas respostas do Nutri e no plano + questionário curto de usabilidade. ✅ D4: sem papel admin; extração por comando artisan.
- Regras: RN40, RN41, RN42, RN06 (exclusão apaga as avaliações do usuário).

## 2. Requisitos funcionais

### RF31 — Avaliar resposta do Nutri e plano
**Fluxo:** abaixo de cada resposta do Nutri (e no Pronto, sobre o plano: "Esse plano faz sentido para você?") → 👍 ou 👎 → marcado na hora; em 👎, abre campo opcional "O que não ajudou?" (até 500 caracteres) com "Enviar" e "Pular". Tocar de novo no mesmo ícone remove a avaliação; tocar no outro troca.
**Regras:** RN40.

### RF32 — Responder questionário de usabilidade
**Fluxo principal:**
1. Convite em Hoje (banner dispensável) quando elegível (RN41) — "Você já usa o Prato Forte há uma semana. Topa responder umas perguntas rápidas? Leva uns 2 minutos e ajuda o projeto da UNINTER."; ou Perfil → "Avaliar o app".
2. Uma pergunta por tela, com progresso: 10 afirmações SUS (escala de 5 pontos), utilidade das recomendações (1–5), duas perguntas abertas opcionais.
3. "Enviar" → agradecimento → volta a Hoje/Perfil.
**Alternativos:** já respondeu na rodada → Perfil mostra "Obrigado por avaliar!" e o item fica desabilitado; dispensar o banner → não aparece de novo nesta rodada (continua no Perfil).
**Regras:** RN41.

### RF33 — Exportar dados da validação
**Atores:** alunos-pesquisadores (acesso ao servidor).
**Fluxo:** `php artisan validacao:exportar --rodada=2026-1` → gera CSVs anonimizados em `storage/app/validacao/{rodada}/` e imprime um resumo.
**Regras:** RN42.

## 3. Fluxos
```
Nutri (resposta) ─▶ 👍/👎 ─▶ PUT /ratings ─▶ (👎) comentário opcional ─▶ PUT /ratings {comment}
Pronto (plano)   ─▶ 👍/👎 ─▶ PUT /ratings
Hoje ─▶ GET /usability-responses/status ─▶ invite=true ─▶ banner ─┬─ "Responder" ─▶ /perfil/avaliar ─▶ 13 telas ─▶ POST /usability-responses ─▶ obrigado
                                                                  └─ "Agora não" ─▶ POST /usability-responses/dismiss
Servidor ─▶ artisan validacao:exportar ─▶ CSVs ─▶ relatório final da atividade extensionista
```

## 4. Telas ↔ backend

### `RatingButtons` no chat (S14) e no Pronto (S10)
- **Exibe:** dois botões ícone com `aria-pressed` e rótulos "Resposta útil"/"Resposta não ajudou" (no plano: "O plano faz sentido"/"O plano não faz sentido"); microanimação ao marcar; campo de comentário após 👎.
- **Endpoints:** `PUT /ratings`, `DELETE /ratings`.
- **Estados:** neutro; marcado; salvando (otimista); erro (reverte + toast).

### Banner de convite em Hoje (S11)
- **Endpoint:** `GET /usability-responses/status`; "Agora não" → `POST /usability-responses/dismiss`.
- **Estados:** oculto (não elegível/respondido/dispensado); visível.

### N08 — Questionário (`/perfil/avaliar`) ✅ nova
- **Layout:** mesma estrutura do onboarding (`Steps` de 13, voltar, título grande, "Continuar"), com animação de entrada lateral.
- **Telas 1–10 (SUS, versão em português adaptada ao produto)** — escala `OptionRow` de 5 opções: "Discordo totalmente", "Discordo", "Neutro", "Concordo", "Concordo totalmente":
  1. Eu usaria o Prato Forte com frequência.
  2. Achei o Prato Forte mais complicado do que precisava.
  3. Achei o Prato Forte fácil de usar.
  4. Eu precisaria da ajuda de alguém que entende de tecnologia para usar o Prato Forte.
  5. As partes do Prato Forte (plano, Nutri, evolução) funcionam bem juntas.
  6. O Prato Forte tem coisas que não combinam entre si.
  7. Imagino que a maioria das pessoas aprende a usar o Prato Forte rapidinho.
  8. Achei o Prato Forte atrapalhado de usar.
  9. Me senti seguro(a) usando o Prato Forte.
  10. Precisei aprender muita coisa antes de conseguir usar o Prato Forte.
- **Tela 11:** "As sugestões do plano e do Nutri foram úteis para você?" — 1 (Nada úteis) a 5 (Muito úteis).
- **Tela 12 (opcional):** "O que mais te ajudou?" (texto, até 1.000).
- **Tela 13 (opcional):** "O que atrapalhou ou faltou?" (texto, até 1.000) + "Enviar".
- Respostas ficam no estado do cliente até o envio (é um questionário curto; não há retomada no servidor 🟡).
- **Endpoint:** `POST /usability-responses`.
- **Estados:** preenchendo; enviando; erro (fica na última tela com "Tentar de novo" — nada se perde); já respondido (409 → tela de agradecimento); sucesso (`EmptyState` de agradecimento, "Voltar para o app").

## 5. API

### `PUT /api/v1/ratings`
- **Request:** `{ "rateable_type": "nutri_message", "rateable_id": 302, "value": "down", "comment": "Não tenho batata-doce em casa." }`
- **Response 200:** `{ "data": { "rateable_type": "nutri_message", "rateable_id": 302, "value": "down", "comment": "Não tenho batata-doce em casa." } }`
- **Validações:** `rateable_type` in:nutri_message,meal_plan; `rateable_id` inteiro; `value` in:up,down; `comment` nullable|string|max:500.
- **Permissão:** `RatingPolicy` — item existe, é do usuário, e mensagem tem `role = assistant` (404 caso contrário).
- **Erros:** 404; 422.

### `DELETE /api/v1/ratings`
- **Request:** `{ "rateable_type": "nutri_message", "rateable_id": 302 }` · **Response:** 204 (idempotente).

### `GET /api/v1/usability-responses/status`
- **Response 200:** `{ "data": { "round": "2026-1", "responded": false, "invite": true } }`
- `invite` = elegível por RN41 e não dispensado e não respondido.

### `POST /api/v1/usability-responses`
- **Request:** `{ "sus_answers": [4, 2, 5, 1, 4, 2, 5, 1, 4, 2], "usefulness": 4, "liked": "Os horários batem com o meu treino.", "disliked": null }`
- **Response 201:** `{ "data": { "round": "2026-1", "responded": true } }` (o escore SUS não é mostrado ao usuário 🟡).
- **Validações:** `sus_answers` array tamanho 10, itens inteiros 1–5; `usefulness` inteiro 1–5; `liked`, `disliked` nullable|string|max:1000.
- **Erros:** 409 `ALREADY_RESPONDED`; 422.

### `POST /api/v1/usability-responses/dismiss`
- **Response:** 204. Grava `user_settings.usability_invite_dismissed_at`.

## 6. Exportação — `php artisan validacao:exportar`

**Opções:** `--rodada=` (padrão: config), `--de=` / `--ate=` (datas, padrão: toda a rodada), `--dir=` (padrão `storage/app/validacao/{rodada}`).

| Arquivo | Uma linha por | Colunas |
|---|---|---|
| `usabilidade.csv` | resposta | usuario_hash, rodada, q1…q10, sus, utilidade, ajudou, atrapalhou, respondido_em |
| `avaliacoes.csv` | avaliação | usuario_hash, tipo (resposta_nutri/plano), valor, comentario, criado_em |
| `uso.csv` | usuário ativo no período | usuario_hash, objetivo, dias_desde_cadastro, dias_com_refeicao_marcada, refeicoes_feitas, dias_completos, trocas_manuais, trocas_nutri, perguntas_nutri, conversas, planos_gerados, planos_falhos, pesagens, variacao_peso_kg |
| `ia.csv` | dia × propósito | dia, proposito, chamadas, falhas, tokens_entrada, tokens_saida, latencia_media_ms |

**Resumo impresso:** nº de participantes, SUS médio (e desvio), % de 👍 no Nutri e no plano, média de utilidade, dias ativos médios.
Sem nome, e-mail, conteúdo de mensagens, restrições específicas ou datas de nascimento (RN42). Arquivos CSV em UTF-8 com BOM (abre direto no Excel) e separador `;` (padrão pt-BR) 🟡.

## 7. Critérios de aceitação
- **CA01** Dado uma resposta do Nutri, quando toco 👍, então fica marcada e persiste ao recarregar; tocar de novo remove.
- **CA02** Dado 👎, então posso escrever um comentário e ele é salvo junto.
- **CA03** Dado a mensagem do usuário (não do assistente) ou de outro usuário, então a API responde 404 ao avaliar.
- **CA04** Dado usuário com onboarding há 8 dias e sem resposta, então Hoje mostra o convite; "Agora não" some com ele nesta rodada.
- **CA05** Dado respostas `[4,2,5,1,4,2,5,1,4,2]`, então o SUS gravado é 85,0.
- **CA06** Dado resposta já enviada na rodada, então um novo envio responde `ALREADY_RESPONDED` e o Perfil mostra "Obrigado por avaliar!".
- **CA07** Dado a exportação, então nenhum CSV contém nome, e-mail ou texto de conversa, e o mesmo usuário tem o mesmo `usuario_hash` em todos os arquivos.

## 8. Test Strategy

### Component Tests
- `RatingButtons` — Neutro, Positivo, Negativo, NegativoComComentario, Salvando; `aria-pressed`; teclado.
- `LikertQuestion` (novo) — Sem resposta (Continuar desabilitado), Respondida; `radiogroup` com setas.
- `UsabilitySurvey` — Primeira, Última com envio, Enviando, Erro, Obrigado.
- `InviteBanner` — Visível, Dispensando.

### Unit Tests
- Back: `SusScoreTest` (exemplo do CA05; todos 3 → 50; extremos 0 e 100); elegibilidade do convite (7 dias OU 10 refeições); hash anônimo estável.
- Front: nenhum além dos componentes.

### Integration Tests
- Back: `RatingTest` (upsert, delete, 404 posse/role, 422); `UsabilityTest` (201, 409, 422, status/convite, dismiss); `ExportCommandTest` (gera 4 arquivos; colunas; anonimização; filtros de data; resumo).
- Front (MSW): avaliar resposta no chat (otimista + reversão); fluxo do questionário até o agradecimento; convite em Hoje aparece/some.

### E2E Tests
- E2E-12 (avaliar resposta 👍 e responder o questionário).

## 9. Definition of Done
```
[ ] CA01–CA07 atendidos
[ ] ratings e usability_responses com migrations, Policies e feature tests
[ ] SusScore com unit tests; ValidationExporter + comando com teste
[ ] RatingButtons no chat e no Pronto; banner em Hoje; tela N08 gerada com frontend-design
[ ] Texto do termo (RN03) menciona o uso anonimizado para a pesquisa
[ ] E2E-12 verde
[ ] Roteiro da rodada de validação (quando abrir/fechar, como exportar) anotado no README do backend
```
