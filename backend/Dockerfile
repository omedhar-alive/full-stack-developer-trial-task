# syntax=docker/dockerfile:1

# ---- vendor (Composer dependencies) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY . .
# Dev dependencies (Pest and friends) are kept so the review stack can run
# `php artisan test` inside the container. A hardened production build would add
# --no-dev here (or use a separate final stage).
RUN composer install \
      --no-interaction --prefer-dist \
      --optimize-autoloader --no-scripts

# ---- runtime ----
FROM dunglas/frankenphp:1-php8.4

# Extensions Laravel needs at runtime that are not already in the base image.
RUN install-php-extensions pdo_mysql bcmath intl opcache pcntl zip

WORKDIR /app

# App source first, then overlay the built vendor tree.
COPY . .
COPY --from=vendor /app/vendor ./vendor

# Framework-writable directories.
RUN mkdir -p storage/framework/cache/data \
             storage/framework/sessions \
             storage/framework/views \
             storage/logs \
             bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/laravel-entrypoint
RUN chmod +x /usr/local/bin/laravel-entrypoint

ENV SERVER_NAME=:8000
EXPOSE 8000

HEALTHCHECK --interval=10s --timeout=5s --start-period=45s --retries=6 \
  CMD php -r "exit(@file_get_contents('http://127.0.0.1:8000/api/health') !== false ? 0 : 1);"

ENTRYPOINT ["laravel-entrypoint"]
CMD ["--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
