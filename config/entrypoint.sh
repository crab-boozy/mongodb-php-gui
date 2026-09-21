#!/bin/sh
set -eu

# Static configs (baked into the image, no runtime generation):
#   /app/config/nginx/nginx.conf - access log off, error log crit, 52M body
#   /app/config/php/mpg.ini      - 50M/52M upload limits, production logging
# The only generated file is the FPM pool: the supervisor-managed processes
# do not reliably receive container environment, so every MPG_* variable is
# forwarded to the PHP workers explicitly via pool env[] directives.

FPM_ENV='env[PATH] = "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
env[PHP_INI_SCAN_DIR] = "/app/config/php:/usr/local/etc/php/conf.d"'
for v in \
    MPG_ALLOWED_MONGODB_HOSTS \
    MPG_ALLOWED_MONGODB_DOMAINS \
    MPG_DEFAULT_DOCUMENTS \
    MPG_MAX_DOCUMENTS \
    MPG_QUERY_MAX_TIME_MS \
    MPG_SERVER_SELECTION_TIMEOUT_MS \
    MPG_CONNECT_TIMEOUT_MS \
    MPG_SOCKET_TIMEOUT_MS \
    MPG_COOKIE_SECURE \
    MPG_DEBUG
do
    eval "value=\${$v-}"
    if [ -n "$value" ]; then
        FPM_ENV="${FPM_ENV}
env[${v}] = \"${value}\""
    fi
done

RUNTIME_DIR="/app/config/runtime"
mkdir -p "$RUNTIME_DIR/php"

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

# In k8s /var/lib/nginx is an emptyDir, wiping the package-provided layout
# baked into the image. Recreate what nginx needs (idempotent locally):
# the temp dirs for `nginx -t`, and the default-error-log symlink to stderr
# (nginx opens it before the config is read, so no log file on disk).
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

nginx -t -c /app/config/nginx/nginx.conf

exec /usr/bin/supervisord -c /app/config/supervisor/supervisord.conf
