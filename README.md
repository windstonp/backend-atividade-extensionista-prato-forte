# Prato Forte — API (Laravel 12)

Backend do projeto de extensão UNINTER "Dietas saudáveis usando tecnologia de ponta".
Specs em `specs/` (comece por `specs/README.md`); planos em `docs/superpowers/plans/`.

## Rodar local (Docker)

```bash
cp .env.example .env
docker compose run --rm api composer install
docker compose run --rm api php artisan key:generate
docker compose up -d                       # API em :8000, Mailpit em :8025, MySQL em :3307
docker compose exec api php artisan migrate
```

## Testes e qualidade

```bash
docker compose run --rm api php artisan test          # Pest contra MySQL (banco prato_forte_test)
docker compose run --rm --no-deps api composer lint   # Pint + Larastan nível 6
```

## Segredos

Nunca versione `.env`. A chave de IA usada no protótipo Node foi exposta e deve ser revogada; a nova vai só no ambiente (`AI_API_KEY`, a partir do Plano 04).
