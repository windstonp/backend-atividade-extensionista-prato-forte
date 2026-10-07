# Implantação e demonstração do Prato Forte

Checklist para subir o app num servidor (RNF-OPE-01…05) e para a demonstração na Zfit. Tudo é software livre; o único custo é a API de IA.

## 1. Servidor

- PHP 8.3 com `pdo_mysql`, `intl`, `bcmath`, `zip`, `pcntl`; Composer 2; MySQL 8.
- **HTTPS** (Let's Encrypt) na API e no front. Sem HTTPS não há Web Push nem cookie `Secure` (RNF-OPE-03).
- Acesso por SSH e cron. Hospedagem compartilhada sem cron nem processo contínuo não atende (RNF-OPE-02).

## 2. Backend — `.env` de produção

| Variável | Valor |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_KEY` | `php artisan key:generate` (guarde: o `usuario_hash` da exportação depende dela) |
| `APP_URL` | `https://api.seu-dominio` |
| `FRONTEND_URL` | `https://app.seu-dominio` (links dos e-mails) |
| `SESSION_DOMAIN` | `.seu-dominio` (front e API no mesmo domínio-pai) |
| `SESSION_SECURE_COOKIE` | `true` |
| `SANCTUM_STATEFUL_DOMAINS` | `app.seu-dominio` |
| `DB_*` | banco MySQL |
| `MAIL_*` | SMTP gratuito: Gmail com senha de app ou Brevo (RNF-OPE-04) |
| `AI_DRIVER` / `AI_BASE_URL` / `AI_API_KEY` | `openai` / URL do provedor / **chave nova**. A chave do protótipo Node foi exposta no repositório antigo: revogue no painel da aimlapi.com antes de tudo (RN44). |
| `AI_MODEL_FALLBACK` / `AI_TIMEOUT` | `gemini-flash-lite-latest` / `25`: modelo reserva quando o principal responde 429/5xx ou estoura o tempo |
| `AI_MODEL_PLAN` / `AI_MODEL_CHAT` | Gemini (Google AI Studio, camada gratuita): `AI_BASE_URL=https://generativelanguage.googleapis.com/v1beta/openai` e `gemini-3.5-flash-lite` nos dois. O `gemini-2.5-*` não é mais liberado para chaves novas; os Flash maiores (3.5/3.8) davam 503 por alta demanda em 2026-10-07. |
| `VAPID_SUBJECT` / `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` | `php artisan webpush:vapid` grava as duas chaves no `.env` |
| `VALIDACAO_RODADA` / `VALIDACAO_INICIO` | rodada da validação e a data de abertura |
| `DB_QUEUE_RETRY_AFTER` | `200` (o padrão já é 200): precisa ser maior que o tempo da geração de plano (170 s) |
| `APP_NAME` / `SESSION_COOKIE` | não mude sem mudar `SESSION_COOKIE_NAME` no front (padrão `prato-forte-session`): o front lê esse cookie para saber se há sessão |

O `.env` nunca vai para o Git.

## 3. Primeira subida

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate            # só na primeira vez
php artisan migrate --force
php artisan db:seed --force         # em produção: só o catálogo (a demonstração não roda)
php artisan config:cache && php artisan route:cache
php artisan ai:smoke                # a IA de verdade responde?
```

## 4. Processos contínuos

- Cron (a cada minuto): `* * * * * cd /caminho/backend && php artisan schedule:run >> /dev/null 2>&1` — lembretes de refeição, resumo de domingo, dicas.
- Fila (planos gerados pela IA, avisos): **um** worker, sob systemd ou Supervisor.

```ini
# /etc/systemd/system/prato-forte-fila.service
[Unit]
Description=Fila do Prato Forte
After=network.target mysql.service

[Service]
User=www-data
WorkingDirectory=/caminho/backend
ExecStart=/usr/bin/php artisan queue:work --queue=notifications,default --tries=1 --timeout=180 --sleep=1
Restart=always

[Install]
WantedBy=multi-user.target
```

Depois de cada atualização: `php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan queue:restart` (o worker termina o job em andamento e o systemd o sobe de novo com o código novo).

## 5. Front

- Variáveis: `NEXT_PUBLIC_API_URL=https://api.seu-dominio`; `NEXT_PUBLIC_APP_VERSION` é opcional (sem ela, vale a do `package.json`).
- `npm ci && npm run build && npm start`, ou Vercel **com domínio próprio** (`app.seu-dominio`, mesmo domínio-pai da API). No domínio padrão `*.vercel.app` o cookie de sessão não chega ao front e ninguém consegue entrar.
- No iPhone, os avisos só chegam com o app na tela de início (Compartilhar → Adicionar à Tela de Início, iOS 16.4+).

## 6. Antes da rodada de validação

1. `php artisan ai:smoke` responde.
2. Pendências dos autores: texto final do termo (P3), revisão por nutricionista (P4) e conferência da tabela de alimentos com a TACO (P5) — ver `specs/99-inconsistencias.md` §D.
3. Abrir a rodada (`VALIDACAO_RODADA`, `VALIDACAO_INICIO`) e, ao fechar, `php artisan validacao:exportar` (README).

## 7. Demonstração local (sem IA de verdade)

```bash
cp .env.example .env                  # APP_ENV=local, AI_DRIVER=fake
docker compose up -d
docker compose exec api php artisan key:generate
docker compose exec api php artisan migrate:fresh --seed
```

- `camila@demo.pratoforte.test` / `demo1234` — a Camila do mock, com um mês de uso: plano pronto, 28 dias de registros (21 completos, sequência de 3), o café de hoje registrado acima da meta, 6 pesagens com previsão de meta e duas conversas com o Nutri.
- `novo@demo.pratoforte.test` / `demo1234` — conta parada na etapa "Atividade" do onboarding, para mostrar a retomada.
