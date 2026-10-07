#!/usr/bin/env bash
# Migrates ACCORD_DATABASE_URL, then serves the conformance profile on ACCORD_PORT (default 8801) and
# the control API on ACCORD_CONTROL_PORT (default 8802) as a plain CLI process (control-server.php). Ctrl-C stops both.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
: "${ACCORD_DATABASE_URL:?ACCORD_DATABASE_URL is required}"
port="${ACCORD_PORT:-8801}"
control_port="${ACCORD_CONTROL_PORT:-8802}"
export ACCORD_RATE_FILE="${ACCORD_RATE_FILE:-$(mktemp -t accord-rate.XXXXXX)}"
php "$here/migrate.php"
# php -S is single-threaded unless PHP_CLI_SERVER_WORKERS is set (Linux): the concurrency tests need it.
# ACCORD_SERVE=fpm serves through PHP-FPM behind nginx (CI); the default is PHP's built-in server.
if [[ "${ACCORD_SERVE:-}" == fpm ]]; then
  "$here/fpm-nginx.sh" "$port" "$here/server.php" "${PHP_CLI_SERVER_WORKERS:-8}" &
else
  PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}" php -q -d opcache.enable_cli=${ACCORD_OPCACHE:-1} ${ACCORD_PHP_FLAGS:-} -S "127.0.0.1:$port" "$here/server.php" &
fi
sync_pid=$!
# The control API runs as a plain CLI process, not under php -S (see control-server.php).
php -q ${ACCORD_PHP_FLAGS:-} "$here/control-server.php" "$control_port" &
control_pid=$!
trap 'kill $sync_pid $control_pid 2>/dev/null || true' EXIT INT TERM
wait -n $sync_pid $control_pid
