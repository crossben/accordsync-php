#!/usr/bin/env bash
# Serves a PHP front controller with PHP-FPM behind nginx, in the foreground, the way PHP runs in
# production. CI uses it instead of `php -S`: on the GitHub runner, the built-in server's workers
# crashed in the engine (segfaults in zend_std_get_static_property_with_info) while serving Accord.
#
#   tools/conformance/fpm-nginx.sh PORT FRONT_CONTROLLER [WORKERS]
#
# Every request goes to FRONT_CONTROLLER (an absolute path), like `php -S host:port script.php`.
# The environment is passed to PHP (clear_env = no), so ACCORD_DATABASE_URL etc. reach the app.
# Needs the php-fpm matching the active `php` (php-fpmX.Y or php-fpm) and nginx, on PATH or in /usr/sbin.
set -euo pipefail

port="$1"
script="$(cd "$(dirname "$2")" && pwd)/$(basename "$2")"
workers="${3:-16}"

v="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
fpm="$(command -v "php-fpm$v" || ls "/usr/sbin/php-fpm$v" 2>/dev/null || command -v php-fpm || true)"
nginx="$(command -v nginx || ls /usr/sbin/nginx 2>/dev/null)"
[[ -x "$fpm" && -x "$nginx" ]] || { echo "fpm-nginx.sh: needs php-fpm and nginx" >&2; exit 1; }

dir="$(mktemp -d "${TMPDIR:-/tmp}/accord-fpm-$port.XXXXXX")"
mkdir -p "$dir/tmp"
# nginx workers may run as another user (www-data when started as root).
chmod 755 "$dir"

cat >"$dir/php-fpm.conf" <<EOF
[global]
error_log = /dev/stderr
daemonize = no

[www]
listen = $dir/fpm.sock
listen.mode = 0666
pm = static
pm.max_children = $workers
clear_env = no
; Real environment variables win over .env files (Symfony reads \$_ENV).
php_admin_value[variables_order] = EGPCS
catch_workers_output = yes
decorate_workers_output = no
EOF

cat >"$dir/nginx.conf" <<EOF
daemon off;
worker_processes 2;
pid $dir/nginx.pid;
error_log stderr warn;
events { worker_connections 1024; }
http {
  access_log off;
  client_body_temp_path $dir/tmp/body;
  fastcgi_temp_path $dir/tmp/fastcgi;
  proxy_temp_path $dir/tmp/proxy;
  uwsgi_temp_path $dir/tmp/uwsgi;
  scgi_temp_path $dir/tmp/scgi;
  # Larger than any limit the app enforces, so the app answers 413 itself.
  client_max_body_size 64m;
  server {
    listen 127.0.0.1:$port;
    location / {
      fastcgi_pass unix:$dir/fpm.sock;
      fastcgi_param SCRIPT_FILENAME $script;
      fastcgi_param SCRIPT_NAME /$(basename "$script");
      fastcgi_param DOCUMENT_ROOT $(dirname "$script");
      fastcgi_param REQUEST_METHOD \$request_method;
      fastcgi_param REQUEST_URI \$request_uri;
      fastcgi_param QUERY_STRING \$query_string;
      fastcgi_param CONTENT_TYPE \$content_type;
      fastcgi_param CONTENT_LENGTH \$content_length;
      fastcgi_param SERVER_PROTOCOL \$server_protocol;
      fastcgi_param SERVER_NAME \$server_name;
      fastcgi_param SERVER_PORT \$server_port;
      fastcgi_param REMOTE_ADDR \$remote_addr;
      fastcgi_param HTTPS "";
    }
  }
}
EOF

"$fpm" --nodaemonize ${ACCORD_FPM_FLAGS:-} --fpm-config "$dir/php-fpm.conf" &
fpm_pid=$!
trap 'kill $fpm_pid $nginx_pid 2>/dev/null || true; rm -rf "$dir"' EXIT INT TERM
for _ in $(seq 1 50); do [[ -S "$dir/fpm.sock" ]] && break; sleep 0.1; done
"$nginx" -e stderr -c "$dir/nginx.conf" -p "$dir" &
nginx_pid=$!
wait -n $fpm_pid $nginx_pid
