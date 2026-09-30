# Prato Forte — API (Laravel 12)

Backend do projeto de extensão UNINTER "Dietas saudáveis usando tecnologia de ponta".
Specs em `specs/` (comece por `specs/README.md`); planos em `docs/superpowers/plans/`.

## Rodar local (Docker)

```bash
cp .env.example .env
docker compose run --rm api composer install
docker compose run --rm api php artisan key:generate
docker compose up -d                       # API :8000, fila e agendador, Mailpit :8025, MySQL :3307
docker compose exec api php artisan migrate
```

## Testes e qualidade

```bash
docker compose run --rm api php artisan test          # Pest contra MySQL (banco prato_forte_test)
docker compose run --rm --no-deps api composer lint   # Pint + Larastan nível 6
```

## IA

`AI_DRIVER=fake` (padrão) usa uma IA determinística: planos montados com os alimentos permitidos, sem custo — é a usada nos testes, no E2E e na demonstração. Para a IA de verdade, no `.env`: `AI_DRIVER=openai`, `AI_BASE_URL` (ex.: `https://api.aimlapi.com/v1`), `AI_API_KEY` e, se quiser, `AI_MODEL_PLAN`/`AI_MODEL_CHAT`. Toda chamada vai para `ai_requests` sem conteúdo (RN44).

Contas do E2E (senha `senha1234`): `php artisan migrate:fresh --seeder=E2ESeeder --force`.

## Segredos

Nunca versione `.env`. A chave de IA usada no protótipo Node foi exposta e deve ser revogada; a nova vai só no ambiente (`AI_API_KEY`).
