#!/usr/bin/env bash
# The mixed-server fleet: for each seed, a fresh PostgreSQL database migrated by one implementation
# (even seeds: TypeScript, odd seeds: PHP; the other must find nothing to do), the TypeScript
# reference server and the PHP server on it at the same time, then fleet.mts.
#
#   ACCORD_APP_DIR=/path/to/accord/app php/interop/run.sh
#   ACCORD_FLEET_SEEDS=1,2,3,4,5 php/interop/run.sh
#
# ACCORD_APP_DIR is the Accord workspace (pnpm install done). ACCORD_DATABASE_URL: a PostgreSQL
# whose user may create databases (CI); unset, a Docker container is started on port 55471.
# Ports: TS 8841/8842, PHP 8843/8844 (ACCORD_FLEET_PORT_BASE moves all four).
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
php_root="$(cd "$here/.." && pwd)"
: "${ACCORD_APP_DIR:?ACCORD_APP_DIR (the Accord workspace) is required}"
app="$(cd "$ACCORD_APP_DIR" && pwd)"
base="${ACCORD_FLEET_PORT_BASE:-8841}"
ts_port=$base ts_control=$((base + 1)) php_port=$((base + 2)) php_control=$((base + 3))
logs="${ACCORD_FLEET_LOGS:-$(mktemp -d -t accord-fleet.XXXXXX)}"
container=accord-php-fleet-pg

# Kills every process listening on a port (php -S with workers is several PIDs on one port).
kill_port() {
  local pids
  pids=$(ss -ltnpH "sport = :$1" 2>/dev/null | grep -o 'pid=[0-9]*' | cut -d= -f2 | sort -u || true)
  [[ -n "$pids" ]] && kill $pids 2>/dev/null || true
  for _ in $(seq 1 50); do ss -ltnH "sport = :$1" | grep -q . || return 0; sleep 0.1; done
  [[ -n "$pids" ]] && kill -9 $pids 2>/dev/null || true
}
stop_servers() { for p in $ts_port $ts_control $php_port $php_control; do kill_port "$p"; done; }

cleanup() {
  stop_servers
  [[ -n "${started_pg:-}" ]] && docker rm -f "$container" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

if [[ -z "${ACCORD_DATABASE_URL:-}" ]]; then
  docker rm -f "$container" >/dev/null 2>&1 || true
  docker run -d --name "$container" -e POSTGRES_USER=accord -e POSTGRES_PASSWORD=accord \
    -e POSTGRES_DB=accord -p 55471:5432 postgres:16-alpine >/dev/null
  started_pg=1
  export ACCORD_DATABASE_URL=postgres://accord:accord@127.0.0.1:55471/accord
  for _ in $(seq 1 100); do psql "$ACCORD_DATABASE_URL" -qtAc 'select 1' >/dev/null 2>&1 && break; sleep 0.3; done
fi
admin_url="$ACCORD_DATABASE_URL"
url_for() { echo "${admin_url%/*}/$1"; }

ledger() { psql "$1" -qtAc 'select name || $$ $$ || timestamp from kysely_migration order by name'; }
ts_migrate() { (cd "$app/packages/server" && node --import tsx --conditions=@accordsync/source "$php_root/tools/conformance/ts-migrate.mts" "$1"); }
php_migrate() { ACCORD_DATABASE_URL="$1" php "$php_root/tools/conformance/migrate.php"; }

wait_up() {
  for _ in $(seq 1 150); do curl -sf "$1/health" >/dev/null && return 0; sleep 0.2; done
  echo "server $1 did not start" >&2; return 1
}

IFS=, read -ra seeds <<< "${ACCORD_FLEET_SEEDS:-1,2,3}"
for seed in "${seeds[@]}"; do
  db="accord_fleet_$seed"
  psql "$admin_url" -qtAc "drop database if exists $db" -c "create database $db" >/dev/null
  dburl="$(url_for "$db")"

  # Migrate once with one implementation; the other must then have nothing to do.
  if (( seed % 2 == 0 )); then first=ts; ts_migrate "$dburl"; else first=php; php_migrate "$dburl" >/dev/null; fi
  before="$(ledger "$dburl")"
  [[ $(wc -l <<< "$before") -ge 7 ]] || { echo "seed $seed: $first migrated only: $before" >&2; exit 1; }
  if [[ $first == ts ]]; then
    out="$(php_migrate "$dburl")"
    [[ "$out" == *"up to date"* ]] || { echo "seed $seed: PHP migrated after TS: $out" >&2; exit 1; }
  else
    ts_migrate "$dburl"
  fi
  [[ "$(ledger "$dburl")" == "$before" ]] || { echo "seed $seed: the second migrator changed the ledger" >&2; exit 1; }

  (cd "$app/conformance" && ACCORD_DATABASE_URL="$dburl" ACCORD_PORT=$ts_port ACCORD_CONTROL_PORT=$ts_control \
    exec node --import tsx --conditions=@accordsync/source reference-server.ts) >"$logs/ts-$seed.log" 2>&1 &
  rate="$logs/rate-$seed.json"
  if [[ "${ACCORD_SERVE:-}" == fpm ]]; then # PHP-FPM behind nginx (CI), see tools/conformance/fpm-nginx.sh
    ACCORD_DATABASE_URL="$dburl" ACCORD_RATE_FILE="$rate" \
      "$php_root/tools/conformance/fpm-nginx.sh" "$php_port" "$php_root/tools/conformance/server.php" 8 >"$logs/php-$seed.log" 2>&1 &
  else
    ACCORD_DATABASE_URL="$dburl" ACCORD_RATE_FILE="$rate" PHP_CLI_SERVER_WORKERS=8 \
      php -q -d opcache.enable_cli=${ACCORD_OPCACHE:-1} -S "127.0.0.1:$php_port" "$php_root/tools/conformance/server.php" >"$logs/php-$seed.log" 2>&1 &
  fi
  ACCORD_DATABASE_URL="$dburl" ACCORD_RATE_FILE="$rate" \
    php -q "$php_root/tools/conformance/control-server.php" "$php_control" >"$logs/php-control-$seed.log" 2>&1 &
  wait_up "http://127.0.0.1:$ts_port"; wait_up "http://127.0.0.1:$php_port"
  [[ "$(ledger "$dburl")" == "$before" ]] || { echo "seed $seed: server start changed the ledger" >&2; exit 1; }

  echo "seed $seed: migrated by $first"
  if ! (cd "$app/conformance" && ACCORD_APP_DIR="$app" ACCORD_FLEET_SEED=$seed \
      ACCORD_TS_URL="http://127.0.0.1:$ts_port" ACCORD_TS_CONTROL_URL="http://127.0.0.1:$ts_control" \
      ACCORD_PHP_URL="http://127.0.0.1:$php_port" ACCORD_PHP_CONTROL_URL="http://127.0.0.1:$php_control" \
      node --import tsx --conditions=@accordsync/source "$here/fleet.mts"); then
    echo "seed $seed FAILED; server logs in $logs" >&2
    tail -n 30 "$logs"/*-"$seed".log >&2 || true
    exit 1
  fi
  stop_servers
  psql "$admin_url" -qtAc "drop database $db" >/dev/null
done
echo "mixed fleet: ${#seeds[@]} seed(s) green"
