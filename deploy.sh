#!/usr/bin/env bash
# Deploy da API na Hostinger. Rode no servidor, dentro da pasta api/:
#
#   bash deploy.sh
#
# O `php` do terminal da Hostinger é 8.1, e o Laravel 13 precisa de 8.4.
# Por isso o script usa o PHP 8.4 alternativo do servidor em tudo.
set -euo pipefail

cd "$(dirname "$0")"

PHP="${PHP:-/opt/alt/php84/usr/bin/php}"

if [ ! -x "$PHP" ]; then
  echo "Não achei o PHP em $PHP. Veja as versões disponíveis com: ls /opt/alt | grep php" >&2
  echo "E rode de novo indicando o caminho: PHP=/opt/alt/phpXX/usr/bin/php bash deploy.sh" >&2
  exit 1
fi

if ! "$PHP" -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);'; then
  echo "O PHP em $PHP é $("$PHP" -r 'echo PHP_VERSION;'); o projeto precisa de 8.4.1 ou mais." >&2
  exit 1
fi

missing=""
for extension in ctype curl dom fileinfo mbstring openssl pdo_mysql tokenizer xml; do
  "$PHP" -m | grep -qix "$extension" || missing="$missing $extension"
done

if [ -n "$missing" ]; then
  echo "Faltam extensões no PHP de $PHP:$missing" >&2
  exit 1
fi

# Composer: usa o do servidor se for um .phar; senão, baixa um só para este projeto.
COMPOSER="${COMPOSER_PHAR:-}"

if [ -z "$COMPOSER" ]; then
  global="$(command -v composer || true)"

  if [ -n "$global" ] && head -n 1 "$global" | grep -q php; then
    COMPOSER="$global"
  else
    COMPOSER="$HOME/.local/bin/composer.phar"

    if [ ! -f "$COMPOSER" ]; then
      echo "Baixando o Composer para $COMPOSER..."
      mkdir -p "$(dirname "$COMPOSER")"
      curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar -o "$COMPOSER"
    fi
  fi
fi

echo "PHP $("$PHP" -r 'echo PHP_VERSION;') · Composer em $COMPOSER"

git pull --ff-only

"$PHP" "$COMPOSER" install --optimize-autoloader --no-interaction

if [ ! -f .env ]; then
  cp .env.example .env
  "$PHP" artisan key:generate --force

  cat <<'TEXT'

Criei o arquivo .env. Antes de continuar, edite-o (nano .env) e ajuste:

  APP_ENV=production
  APP_DEBUG=false
  APP_URL=https://api-extensionista.pedrogom.es
  LOG_LEVEL=warning
  DB_CONNECTION=mysql
  DB_HOST=localhost
  DB_PORT=3306
  DB_DATABASE=   (nome do banco criado no hPanel)
  DB_USERNAME=   (usuário do banco)
  DB_PASSWORD=   (senha do banco)

Depois rode de novo: bash deploy.sh
TEXT
  exit 0
fi

"$PHP" artisan migrate --force
# Só o catálogo (upsert pelo slug, pode rodar sempre); a demonstração nunca roda aqui.
"$PHP" artisan db:seed --class=CatalogSeeder --force

# Testes: só com um banco próprio no .env.testing (o Laravel lê esse arquivo quando APP_ENV=testing).
# Rodam antes do optimize: com a config em cache eles ignoram o phpunit.xml e o RefreshDatabase
# apaga o banco do .env, que aqui é o de produção.
env_value() {
  "$PHP" -r 'require "vendor/autoload.php"; echo Dotenv\Dotenv::parse(file_get_contents($argv[1]))[$argv[2]] ?? "";' "$1" "$2"
}

tests_failed=""

if [ -f .env.testing ]; then
  # O phpunit.xml fixa DB_DATABASE=prato_forte_test (o do CI); uma variável já exportada vence.
  test_database="$(env_value .env.testing DB_DATABASE)"

  if [ -z "$test_database" ] || [ "$test_database" = "$(env_value .env DB_DATABASE)" ]; then
    echo "O DB_DATABASE do .env.testing está vazio ou é o de produção. Não rodo os testes." >&2
    exit 1
  fi

  "$PHP" artisan config:clear
  "$PHP" artisan route:clear
  "$PHP" artisan event:clear

  # Tabelas prontas antes dos testes de tests/Unit que usam o banco sem RefreshDatabase.
  DB_DATABASE="$test_database" "$PHP" artisan migrate --env=testing --force
  DB_DATABASE="$test_database" "$PHP" ./vendor/bin/pest || tests_failed=1
else
  echo "Sem .env.testing: pulei os testes (eles rodam no CI)."
fi

"$PHP" artisan optimize

if [ -n "$tests_failed" ]; then
  echo
  echo "Deploy aplicado, mas os testes falharam (veja acima)." >&2
  exit 1
fi

echo
echo "Deploy concluído. Teste: curl https://api-extensionista.pedrogom.es/up"
echo
