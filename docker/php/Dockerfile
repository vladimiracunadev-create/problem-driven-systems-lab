# syntax=docker/dockerfile:1.7

FROM php:8.3-cli-alpine

RUN apk add --no-cache libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps

ARG APP_DIR

WORKDIR /app
COPY ${APP_DIR}/ /app/

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app", "/app/index.php"]
