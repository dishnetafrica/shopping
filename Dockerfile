# ---------- build stage: install PHP deps ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs || true
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# ---------- runtime stage ----------
FROM php:8.3-fpm-bookworm

# system deps + PHP extensions Laravel/Filament/Postgres/Redis need
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor libpq-dev libzip-dev libpng-dev libonig-dev libicu-dev unzip \
    && docker-php-ext-install pdo pdo_pgsql pgsql gd zip intl bcmath opcache pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html

COPY docker/nginx.conf      /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/app.conf
COPY docker/php.ini         /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/entrypoint.sh   /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint"]
