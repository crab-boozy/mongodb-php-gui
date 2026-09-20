#!/bin/sh
set -eu

MAX_IMPORT_SIZE="${MPG_MAX_IMPORT_SIZE:-10485760}"
case "$MAX_IMPORT_SIZE" in
    ''|*[!0-9]*) MAX_IMPORT_SIZE=10485760 ;;
esac
POST_MAX_SIZE=$((MAX_IMPORT_SIZE + 2097152))

# Access log: minimal by default, opt-in with MPG_ACCESS_LOG=on.
case "${MPG_ACCESS_LOG:-off}" in
    on|1|true|yes) ACCESS_LOG_DIRECTIVE='access_log /dev/stdout combined;' ;;
    off|0|false|no|'') ACCESS_LOG_DIRECTIVE='access_log off;' ;;
    *) ACCESS_LOG_DIRECTIVE='access_log off;'; echo "MPG config | MPG_ACCESS_LOG='${MPG_ACCESS_LOG}' is invalid; using off." >&2 ;;
esac

# Nginx error log level: critical-only by default.
case "${MPG_NGINX_LOG_LEVEL:-crit}" in
    debug|info|notice|warn|err|crit|alert|emerg) NGINX_LOG_LEVEL="${MPG_NGINX_LOG_LEVEL:-crit}" ;;
    *) NGINX_LOG_LEVEL='crit'; echo "MPG config | MPG_NGINX_LOG_LEVEL='${MPG_NGINX_LOG_LEVEL}' is invalid; using crit." >&2 ;;
esac

# PHP error_reporting: production-minimal by default (no notices/deprecations).
case "${MPG_PHP_LOG_LEVEL:-production}" in
    all|max) PHP_ERROR_REPORTING='E_ALL' ;;
    production|'') PHP_ERROR_REPORTING='E_ERROR | E_WARNING | E_PARSE | E_RECOVERABLE_ERROR' ;;
    *) PHP_ERROR_REPORTING='E_ERROR | E_WARNING | E_PARSE | E_RECOVERABLE_ERROR'; echo "MPG config | MPG_PHP_LOG_LEVEL='${MPG_PHP_LOG_LEVEL}' is invalid; using production." >&2 ;;
esac

RUNTIME_DIR="/app/config/runtime"
mkdir -p "$RUNTIME_DIR/php" "$RUNTIME_DIR/nginx"

# PHP runtime ini (single source: MPG_MAX_IMPORT_SIZE).
cat > "$RUNTIME_DIR/php/mpg.ini" <<EOF
upload_max_filesize = ${MAX_IMPORT_SIZE}
post_max_size = ${POST_MAX_SIZE}
upload_tmp_dir = /tmp
session.save_path = /var/lib/php/sessions
session.use_strict_mode = 1
expose_php = Off
error_reporting = ${PHP_ERROR_REPORTING}
display_errors = 0
log_errors = On
error_log = /dev/stderr
EOF

# FPM pool with the runtime environment. The supervisor-managed processes
# do not reliably receive container environment, so every MPG_* variable is
# forwarded to the PHP workers explicitly via pool env[] directives.
FPM_ENV='env[PATH] = "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
env[PHP_INI_SCAN_DIR] = "/app/config/runtime/php:/usr/local/etc/php/conf.d"'
for v in \
    MPG_ALLOWED_MONGODB_HOSTS \
    MPG_ALLOWED_MONGODB_DOMAINS \
    MPG_DEFAULT_DOCUMENTS \
    MPG_MAX_DOCUMENTS \
    MPG_QUERY_MAX_TIME_MS \
    MPG_SERVER_SELECTION_TIMEOUT_MS \
    MPG_CONNECT_TIMEOUT_MS \
    MPG_SOCKET_TIMEOUT_MS \
    MPG_MAX_IMPORT_SIZE \
    MPG_MAX_IMPORT_DOCUMENTS \
    MPG_COOKIE_SECURE \
    MPG_DEBUG
do
    eval "value=\${$v-}"
    if [ -n "$value" ]; then
        FPM_ENV="${FPM_ENV}
env[${v}] = \"${value}\""
    fi
done

cat > "$RUNTIME_DIR/php/www.conf" <<EOF
[www]
user = mpg
group = mpg

listen = 127.0.0.1:9000

pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 1000

catch_workers_output = yes
${FPM_ENV}
EOF

# Nginx runtime config (single source: MPG_MAX_IMPORT_SIZE).
cat > "$RUNTIME_DIR/nginx/nginx.conf" <<EOF
worker_processes 1;
pid /var/lib/nginx/nginx.pid;
    error_log /dev/stderr ${NGINX_LOG_LEVEL};

events {
    worker_connections 1024;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    ${ACCESS_LOG_DIRECTIVE}

    sendfile on;
    server_tokens off;
    client_max_body_size ${POST_MAX_SIZE};

    server {
        listen 0.0.0.0:8080;

        root /app;

        add_header X-Frame-Options "DENY" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; blob-src 'self'" always;
        add_header Permissions-Policy "camera=(), geolocation=(), microphone=()" always;
        add_header Cross-Origin-Resource-Policy "same-origin" always;

        location ~ /\. {
            deny all;
        }

        location /assets/ {
            try_files \$uri =404;
        }

        location /source/css/ {
            try_files \$uri =404;
        }

        location /source/js/ {
            try_files \$uri =404;
        }

        location ~ \\.php\$ {
            return 404;
        }

        location = /index.php {
            include /etc/nginx/fastcgi_params;
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_param SCRIPT_FILENAME /app/index.php;
        }

        location / {
            rewrite ^ /index.php?\$args last;
        }
    }
}
EOF

# In k8s /var/lib/nginx is an emptyDir, wiping the package-provided layout
# baked into the image. Recreate what nginx needs (idempotent locally):
# the temp dirs for `nginx -t`, and the default-error-log symlink to stderr
# (nginx opens it before the config above is read, so no log file on disk).
mkdir -p /var/lib/php/sessions \
         /var/lib/nginx/logs \
         /var/lib/nginx/tmp/client_body \
         /var/lib/nginx/tmp/proxy \
         /var/lib/nginx/tmp/fastcgi \
         /var/lib/nginx/tmp/uwsgi \
         /var/lib/nginx/tmp/scgi
chmod 700 /var/lib/php/sessions
if [ ! -e /var/lib/nginx/logs/error.log ]; then
    ln -sf /dev/stderr /var/lib/nginx/logs/error.log
fi

nginx -t -c "$RUNTIME_DIR/nginx/nginx.conf"

exec /usr/bin/supervisord -c /app/config/supervisor/supervisord.conf
