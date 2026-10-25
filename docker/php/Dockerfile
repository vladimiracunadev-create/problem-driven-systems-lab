# syntax=docker/dockerfile:1.7

FROM php:8.4.27-cli-alpine3.24

RUN printf '%s\n' \
    'session.upload_progress.enabled=0' \
    'file_uploads=0' \
    'allow_url_include=0' \
    'expose_php=0' \
    'display_errors=0' \
    'log_errors=1' \
    > "$PHP_INI_DIR/conf.d/zz-security.ini"

RUN apk add --no-cache libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps

ARG APP_DIR

WORKDIR /app
COPY ${APP_DIR}/ /app/

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app", "/app/index.php"]
