FROM php:8.3-cli-alpine
WORKDIR /app
COPY app/ /app/
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app", "/app/index.php"]
