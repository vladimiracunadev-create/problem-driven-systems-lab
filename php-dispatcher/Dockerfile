# syntax=docker/dockerfile:1.7

FROM php:8.3-cli-alpine

# tini = PID 1 limpio (forwarding de senales + reaping de zombies).
# libpq + pdo_pgsql para casos 01 y 02 (que conectan a PostgreSQL).
RUN apk add --no-cache libpq tini \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps

WORKDIR /app

# Copiar las 12 apps PHP de cada caso en /cases/{id}/
COPY cases/01-api-latency-under-load/php/app/                                          /cases/01/
COPY cases/02-n-plus-one-and-db-bottlenecks/php/app/                                   /cases/02/
COPY cases/03-poor-observability-and-useless-logs/php/app/                             /cases/03/
COPY cases/04-timeout-chain-and-retry-storms/php/app/                                  /cases/04/
COPY cases/05-memory-pressure-and-resource-leaks/php/app/                              /cases/05/
COPY cases/06-broken-pipeline-and-fragile-delivery/php/app/                            /cases/06/
COPY cases/07-incremental-monolith-modernization/php/app/                              /cases/07/
COPY cases/08-critical-module-extraction-without-breaking-operations/php/app/          /cases/08/
COPY cases/09-unstable-external-integration/php/app/                                   /cases/09/
COPY cases/10-expensive-architecture-for-simple-needs/php/app/                         /cases/10/
COPY cases/11-heavy-reporting-blocks-operations/php/app/                               /cases/11/
COPY cases/12-single-point-of-knowledge-and-operational-risk/php/app/                  /cases/12/

# Dispatcher + entrypoint
COPY php-dispatcher/app/entrypoint.sh /app/entrypoint.sh
COPY php-dispatcher/app/dispatcher.php /app/dispatcher.php
RUN chmod +x /app/entrypoint.sh

EXPOSE 8100

# tini como PID 1 propaga SIGTERM/SIGINT al entrypoint shell, que mata
# los 12 hijos antes de salir.
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["/app/entrypoint.sh"]
