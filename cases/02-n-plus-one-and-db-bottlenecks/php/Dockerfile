FROM php:8.3-cli-alpine
WORKDIR /app
RUN docker-php-ext-install pdo pdo_pgsql
COPY app/ /app/
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app", "/app/index.php"]
