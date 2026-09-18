# ---- Build stage: composer dependencies -------------------------------
FROM php:8.4-cli-alpine AS build

ARG MONGODB_EXT_VERSION=2.5.2

WORKDIR /app

# Optional extra CAs for corporate TLS-inspecting proxies (directory is
# committed empty; drop *.crt files into it and rebuild).
COPY build-certs/ /tmp/extra-ca/
RUN set -e \
  && for f in /tmp/extra-ca/*.crt; do \
       if [ -f "$f" ]; then cp "$f" /usr/local/share/ca-certificates/; fi \
     done \
  && update-ca-certificates

COPY composer.json composer.lock ./

RUN apk add --no-cache --virtual .build-deps autoconf build-base openssl-dev curl \
  && (ok=0; for i in 1 2 3; do pecl -q install "mongodb-${MONGODB_EXT_VERSION}" && { ok=1; break; } || sleep 5; done; [ -n "$ok" ]) \
  && docker-php-ext-enable mongodb \
  && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
  && composer --version

COPY . /app/
RUN composer install --no-dev --no-interaction --no-progress

# ---- Runtime stage: nginx + php-fpm, non-root ---------------------------
FROM php:8.4-fpm-alpine

ARG MONGODB_EXT_VERSION=2.5.2

RUN apk add --no-cache --virtual .build-deps autoconf build-base openssl-dev \
  && (ok=0; for i in 1 2 3; do pecl -q install "mongodb-${MONGODB_EXT_VERSION}" && { ok=1; break; } || sleep 5; done; [ -n "$ok" ]) \
  && docker-php-ext-enable mongodb \
  && apk del .build-deps \
  && apk add --no-cache nginx supervisor \
  && addgroup -g 808 mpg \
  && adduser -u 808 -G mpg -D -H mpg \
  && mkdir -p /app/config/runtime/php /app/config/runtime/nginx \
    /var/lib/php/sessions /var/lib/nginx /var/lib/supervisor

COPY --from=build --chown=mpg:mpg /app /app/

RUN chown -R mpg:mpg /app /var/lib/php /var/lib/nginx /var/lib/supervisor \
  && chmod 700 /var/lib/php/sessions \
  && chmod +x /app/config/entrypoint.sh \
  && rm -f /var/log/*.log

# PHP scans the runtime ini directory (populated by the entrypoint) in
# addition to the system configuration.
ENV PHP_INI_SCAN_DIR=/app/config/runtime/php:/usr/local/etc/php/conf.d

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD wget -qO- http://127.0.0.1:8080/health || exit 1

USER mpg

ENTRYPOINT ["/app/config/entrypoint.sh"]
