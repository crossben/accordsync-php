#!/usr/bin/env bash
# Migrates, then serves the app with PHP's built-in server and 16 workers (php artisan serve is single
# threaded): the sync API on ACCORD_PORT (default 8845) and, from a second process with the
# test-only control API enabled, on ACCORD_CONTROL_PORT (default 8846). Ctrl-C stops both.
set -euo pipefail
cd "$(dirname "$0")"
[ -f .env ] || cp .env.example .env
port="${ACCORD_PORT:-8845}"
control_port="${ACCORD_CONTROL_PORT:-8846}"
# Cached config, routes and events: a few milliseconds less per request (the 429 test needs speed).
# Rebuilt first, from this environment: a cache left by an earlier run would otherwise win over it,
# for the migration as well as for the server.
php artisan optimize:clear -q
php artisan config:cache -q && php artisan route:cache -q && php artisan event:cache -q
php artisan accord:migrate
PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-16}" ACCORD_CONTROL_ENABLED=false \
  php -q -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 ${ACCORD_PHP_FLAGS:-} -S "127.0.0.1:$port" -t public public/index.php &
sync_pid=$!
PHP_CLI_SERVER_WORKERS=2 ACCORD_CONTROL_ENABLED=true \
  php -q -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 ${ACCORD_PHP_FLAGS:-} -S "127.0.0.1:$control_port" -t public public/index.php &
control_pid=$!
trap 'kill $sync_pid $control_pid 2>/dev/null || true' EXIT INT TERM
wait -n $sync_pid $control_pid
