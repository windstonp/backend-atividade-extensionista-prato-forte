# 06 — Configurações, notificações (Web Push) e unidade de medida

## 1. Contexto e fontes
- 🔵 `/perfil/configuracoes`: "Avisos no celular" (Lembrete de refeição — "15 minutos antes de cada horário"; Resumo da semana — "Todo domingo à noite"; Dicas do Nutri — "No máximo duas por semana"), "Medidas" (Quilo e centímetro / Libra e polegada), "Sua conta" (spec 01), rodapé sobre o projeto. Nada é salvo hoje.
- 🟢 Não citado no documento. ✅ D5: notificações e unidade imperial entram no MVP. ✅ custo zero: Web Push (VAPID) é gratuito.
- Regras: RN38, RN39.

## 2. Requisitos funcionais

### RF26 — Configurar avisos
**Fluxo principal:**
1. Configurações → liga um aviso (`Toggle`).
2. Se este navegador ainda não tem inscrição push: o app pede permissão do navegador (só neste momento) → inscreve → envia a inscrição ao backend.
3. Salva a preferência.
**Alternativos:**
- Permissão negada → toggle volta a desligado; mensagem "Os avisos estão bloqueados no navegador. Libere nas configurações do celular para ligar." 
- iPhone sem o app na tela de início → mensagem "No iPhone, os avisos só funcionam com o Prato Forte na tela de início: toque em Compartilhar → Adicionar à Tela de Início." (RNF-OPE-05).
- Navegador sem suporte a push → toggles desabilitados com a explicação.
- Desligar todos os avisos **não** apaga a inscrição (apagada só no logout ou se o serviço de push recusar).

### RF27 — Lembrete de refeição
15 min antes de cada refeição de hoje não feita, notificação "{Refeição} às {hora}" / "{resumo}"; ao tocar, abre `/dieta/{slot}`. Regras: RN38.

### RF28 — Resumo da semana
Domingo 20:00: "Sua semana no Prato Forte" / "{n} de 7 dias completos · média de {p} g de proteína{ · peso {+/-x} kg}". Toque abre `/evolucao`. Só para quem teve ao menos 1 refeição marcada na semana.

### RF29 — Dicas do Nutri
Terça e sexta 18:00, no máximo 2 por semana, somente se uma regra se aplica (sem IA — `TipSelector`), a primeira aplicável que não foi enviada nos últimos 14 dias:
| Regra | Texto |
|---|---|
| Proteína média dos últimos 7 dias < 90% da meta | "Faltou proteína nesta semana. Um ovo a mais no café já ajuda." |
| Mesma refeição ficou sem marcar ≥ 3 vezes nos últimos 7 dias | "O {refeição} ficou de fora {n} vezes esta semana. Quer pedir ao Nutri uma opção mais prática?" |
| Meta definida e nenhuma pesagem há ≥ 7 dias | "Faz uma semana sem pesagem. Amanhã cedo, antes do café?" |
| Sequência ≥ 5 dias completos | "{n} dias seguidos com tudo feito. Segue assim!" |
Toque abre a tela relacionada (`/nutri`, `/evolucao/peso`, `/evolucao`).

### RF30 — Unidade de medida
Configurações → "Medidas": métrico (padrão) ou imperial. Afeta **só medidas corporais** (peso em lb, altura em ft/in) na exibição e na entrada; porções de alimentos continuam em gramas e medida caseira (público brasileiro 🟡). API sempre métrica (RN39).

## 3. Fluxos
```
Configurações ─▶ Toggle ON ─▶ tem inscrição? ─ não ─▶ Notification.requestPermission()
                                                     ├─ granted ─▶ pushManager.subscribe ─▶ POST /push-subscriptions ─▶ PUT /settings
                                                     └─ denied  ─▶ toggle OFF + mensagem
                                           └ sim ─▶ PUT /settings
Scheduler (cron * * * * *) ─▶ SendMealReminders ─▶ usuários elegíveis ─▶ Notification (fila) ─▶ serviço de push ─▶ sw.js ─▶ notificação ─▶ toque ─▶ /dieta/{slot}
Logout ─▶ DELETE /push-subscriptions {endpoint} ─▶ POST /logout
```

## 4. Telas ↔ backend

### S19 — Configurações (`/perfil/configuracoes`) 🔵
- **Exibe:** "Avisos no celular" (3 `Toggle` com descrição 🔵), mensagem de permissão/suporte quando aplicável; "Medidas" (`Segmento`); "Sua conta" (spec 01); rodapé "O Prato Forte é um projeto de extensão do curso de Ciência da Computação da UNINTER, feito junto com a academia Zfit, em Capivari de Baixo." e versão (de `NEXT_PUBLIC_APP_VERSION`, no lugar do fixo "Versão 0.9, protótipo de validação").
- **Endpoints:** `GET /settings`, `PUT /settings`, `POST /push-subscriptions`, `DELETE /push-subscriptions`, `GET /me`.
- **Estados:** carregando (`Skeleton` × 2 🔵); erro ao carregar (`ErrorState`); salvando (toggle desabilitado durante a requisição; otimista com reversão em erro + toast); sem suporte a push; permissão negada; sucesso.
- **Perfil (S17)**: item "Notificações e conta" mostra resumo real: "Lembretes de refeição ligados" / "Avisos desligados" / "{n} avisos ligados".

## 5. API

### `GET /api/v1/settings`
- **Auth:** sessão (não exige onboarding).
- **Response 200:**
```json
{ "data": {
  "unit_system": "metric",
  "notifications": { "meal_reminders": true, "weekly_summary": true, "tips": false },
  "push": { "vapid_public_key": "BExampleKey…", "subscriptions": 1 }
} }
```

### `PUT /api/v1/settings`
- **Request:** `{ "unit_system": "imperial", "notifications": { "meal_reminders": true, "weekly_summary": false, "tips": true } }` (campos parciais aceitos 🟡)
- **Response 200:** mesmo formato do GET.
- **Validações:** `unit_system` `Rule::enum(UnitSystem)`; `notifications.*` boolean.

### `POST /api/v1/push-subscriptions`
- **Request:** `{ "endpoint": "https://fcm.googleapis.com/fcm/send/…", "keys": { "p256dh": "…", "auth": "…" }, "content_encoding": "aes128gcm" }`
- **Response 201:** `{ "data": { "subscribed": true } }` (upsert por `endpoint`; se o endpoint era de outro usuário, passa a ser deste — mesmo navegador, outra conta).
- **Validações:** `endpoint` required|url|max:500|starts_with:https://; `keys.p256dh`, `keys.auth` required|string|max:255; `content_encoding` in:aesgcm,aes128gcm.

### `DELETE /api/v1/push-subscriptions`
- **Request:** `{ "endpoint": "…" }` · **Response:** 204 (idempotente).

## 6. Backend — comandos agendados

| Comando | Agenda | Seleção | Notificação | `reference` (anti-duplicata) |
|---|---|---|---|---|
| `SendMealReminders` | a cada minuto | onboarding concluído + `notify_meal_reminders` + ≥ 1 inscrição; refeições de hoje (dia materializado ou prévia do plano ativo) com `time − 15 min` = minuto atual, não feitas; dentro da janela acordado | `MealReminder` | `{data}:{slot}` |
| `SendWeeklySummary` | domingo 20:00 | `notify_weekly_summary` + inscrição + ≥ 1 refeição feita na semana | `WeeklySummary` | `{ano}-W{semana}` |
| `SendNutriTips` | ter e sex 18:00 | `notify_tips` + inscrição + regra aplicável + < 2 dicas na semana | `NutriTip` | `{ano}-W{semana}:{dia}` |

- Processamento em lotes (`chunkById(200)`); notificações `ShouldQueue` na fila `default`.
- Respeita RN38 (janela acordado; nada antes do onboarding).
- Resposta 404/410 do serviço de push ⇒ inscrição apagada (o pacote expõe o relatório de envio).
- Payload: `{ "title", "body", "url", "tag" }` — sem dado sensível (ver `seguranca.md` §11). `tag` evita empilhar lembretes da mesma refeição.

## 7. Critérios de aceitação
- **CA01** Dado que ligo "Lembrete de refeição" pela primeira vez, então o navegador pede permissão só nesse momento; aceitando, a inscrição fica salva e o toggle ligado.
- **CA02** Dado permissão negada, então o toggle volta a desligado com a explicação.
- **CA03** Dado almoço às 12:30 não feito e lembretes ligados, quando são 12:15, então é enviada uma notificação "Almoço às 12:30"; às 12:16 não é enviada de novo.
- **CA04** Dado almoço já marcado como feito às 12:10, então nenhum lembrete do almoço é enviado.
- **CA05** Dado dicas ligadas, então nunca chegam mais de 2 dicas na mesma semana, e nenhuma se nenhuma regra se aplica.
- **CA06** Dado imperial, então peso e altura aparecem em lb e ft/in em Perfil, Hoje, Evolução, Registrar peso e Dados; ao salvar 128,7 lb, a API recebe 58,4 kg.
- **CA07** Dado logout, então a inscrição deste navegador é removida e nenhum aviso chega a ele.
- **CA08** Dado inscrição que o serviço de push rejeita com 410, então ela é apagada após a tentativa.

## 8. Test Strategy

### Component Tests
- `Toggle` 🔵 — Ligado, Desligado, Desabilitado, Salvando; `role=switch`, `aria-checked`, rótulo e descrição associados.
- `NotificationSettings` — PermissaoPadrao, PermissaoNegada (mensagem), SemSuporte, IosSemPwa.
- `Segmento` (medidas) — métrico/imperial.

### Unit Tests
- Back: `TipSelectorTest` (cada regra; prioridade; janela de 14 dias; limite semanal); cálculo do conteúdo do resumo semanal.
- Front: `units.ts` (kg↔lb, cm↔ft/in, arredondamento, ida e volta); `push.ts` com `Notification`/`PushManager` simulados (fluxos granted/denied/sem suporte; detecção iOS standalone).

### Integration Tests
- Back: `SettingsTest` (GET padrão; PUT parcial; validação); `PushSubscriptionTest` (upsert por endpoint; troca de dono; delete idempotente); `MealReminderCommandTest` (`Carbon::setTestNow` 12:15 → `Notification::assertSentTo`; 12:16 não; refeição feita não; fora da janela não; sem inscrição não; sem onboarding não; `sent_notifications` impede duplicata); `WeeklySummaryCommandTest`; `TipsCommandTest` (máx. 2/semana).
- Front (MSW): ligar toggle → requestPermission (mock) → POST inscrição → PUT settings; erro no PUT reverte toggle; trocar unidade atualiza exibição de peso na Evolução.

### E2E Tests
- Nenhum dedicado: push real não é automatizável de forma estável. A cobertura vem dos testes de comando (back) e do fluxo de permissão (front, com mocks). Checklist manual na validação: receber lembrete em Android (Chrome) e iPhone (PWA instalado).

## 9. Definition of Done
```
[x] CA01–CA08 atendidos
[x] Pacote webpush configurado; VAPID em variáveis de ambiente
[x] Comandos agendados com feature tests; cron + worker documentados para o servidor
[x] manifest.webmanifest + sw.js (push e notificationclick)
[x] Tela de configurações ligada à API; resumo real no Perfil
[x] units.ts aplicado em todas as telas com peso/altura
[ ] Teste manual em Android e iPhone (PWA) registrado no relatório
[x] axe limpo; switches acessíveis
```

Verificado em 2026-10-06 (Plano 10B). **Aberto:** o teste manual em Android e iPhone (PWA) só pode ser feito pelos autores, em aparelhos reais e com o servidor em HTTPS (`docs/implantacao.md` §5).
