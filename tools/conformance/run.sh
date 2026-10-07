#!/usr/bin/env bash
# Migrates ACCORD_DATABASE_URL, then serves the conformance profile on ACCORD_PORT (default 8801) and
# the control API on ACCORD_CONTROL_PORT (default 8802) with PHP's built-in server. Ctrl-C stops both.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
: "${ACCORD_DATABASE_URL:?ACCORD_DATABASE_URL is required}"
port="${ACCORD_PORT:-8801}"
control_port="${ACCORD_CONTROL_PORT:-8802}"
export ACCORD_RATE_FILE="${ACCORD_RATE_FILE:-$(mktemp -t accord-rate.XXXXXX)}"
php "$here/migrate.php"
# php -S is single-threaded unless PHP_CLI_SERVER_WORKERS is set (Linux): the concurrency tests need it.
PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}" php -q -d opcache.enable_cli=${ACCORD_OPCACHE:-1} ${ACCORD_PHP_FLAGS:-} -S "127.0.0.1:$port" "$here/server.php" &
sync_pid=$!
php -q -d opcache.enable_cli=${ACCORD_OPCACHE:-1} ${ACCORD_PHP_FLAGS:-} -S "127.0.0.1:$control_port" "$here/control.php" &
control_pid=$!
trap 'kill $sync_pid $control_pid 2>/dev/null || true' EXIT INT TERM
wait -n $sync_pid $control_pid
