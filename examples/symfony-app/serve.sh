#!/usr/bin/env bash
# Migrates, then serves the app (prod environment) with PHP's built-in server and 16 workers: the
# sync API on ACCORD_PORT (default 8847) and, from a second process with the test-only control API
# enabled, on ACCORD_CONTROL_PORT (default 8848). Ctrl-C stops both. variables_order=EGPCS: real
# environment variables win over .env under `php -S` too, as they do from the command line.
set -euo pipefail
cd "$(dirname "$0")"
port="${ACCORD_PORT:-8847}"
control_port="${ACCORD_CONTROL_PORT:-8848}"
php bin/console cache:clear --no-warmup -q && php bin/console cache:warmup -q
php bin/console accord:migrate
if [[ "${ACCORD_SERVE:-}" == fpm ]]; then # PHP-FPM behind nginx (CI), see tools/conformance/fpm-nginx.sh
  fpm="$(cd "$PWD/../.." && pwd)/tools/conformance/fpm-nginx.sh"
  env -u ACCORD_CONTROL_ENABLED "$fpm" "$port" "$PWD/public/index.php" "${PHP_CLI_SERVER_WORKERS:-16}" &
  sync_pid=$!
  ACCORD_CONTROL_ENABLED=1 "$fpm" "$control_port" "$PWD/public/index.php" 2 &
else
env -u ACCORD_CONTROL_ENABLED PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-16}" \
  php -q -d opcache.enable_cli=${ACCORD_OPCACHE:-1} -d opcache.validate_timestamps=0 -d variables_order=EGPCS ${ACCORD_PHP_FLAGS:-} -S "127.0.0.1:$port" -t public public/index.php &
sync_pid=$!
ACCORD_CONTROL_ENABLED=1 \
  php -q -d opcache.enable_cli=${ACCORD_OPCACHE:-1} -d opcache.validate_timestamps=0 -d variables_order=EGPCS ${ACCORD_PHP_FLAGS:-} -S "127.0.0.1:$control_port" -t public public/index.php &
fi
control_pid=$!
trap 'kill $sync_pid $control_pid 2>/dev/null || true' EXIT INT TERM
wait -n $sync_pid $control_pid
