FROM php:8.2-cli
RUN docker-php-ext-install pdo pdo_mysql mysqli
WORKDIR /app
COPY . .
ENV PHP_CLI_SERVER_WORKERS=4
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t public"]