# syntax=docker/dockerfile:1

FROM composer:2 AS builder

WORKDIR /build

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY src/ ./src/
COPY server.php init_db.php ./

FROM php:8.4-cli-bookworm AS runtime

RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite pcntl posix \
    && rm -rf /var/lib/apt/lists/*

RUN groupadd --gid 1000 lotto \
    && useradd --uid 1000 --gid lotto --create-home --shell /usr/sbin/nologin lotto

WORKDIR /app

COPY --from=builder --chown=lotto:lotto /build/vendor ./vendor
COPY --from=builder --chown=lotto:lotto /build/src ./src
COPY --from=builder --chown=lotto:lotto /build/server.php ./server.php
COPY --from=builder --chown=lotto:lotto /build/init_db.php ./init_db.php
COPY --chown=lotto:lotto deploy/docker/healthcheck.php /app/healthcheck.php
COPY --chown=lotto:lotto deploy/lib/reset_admin_bootstrap.php /app/reset_admin_bootstrap.php

USER lotto

ENV LOTTO_WS_PORT=8080 \
    LOTTO_DB_PATH=/app/data/game.db \
    LOTTO_SERVER_LOG=php://stdout \
    LOTTO_WORKERMAN_LOG_FILE=php://stdout \
    LOTTO_WORKERMAN_PID_FILE=/app/data/workerman.pid

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD ["php", "/app/healthcheck.php"]

CMD ["php", "server.php", "start"]
