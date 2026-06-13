# ========== Stage 1: assemble the complete Laravel app ==========
# The repo holds only our application code (overlay). This stage creates a fresh
# Laravel 11 skeleton, installs the SaaS packages, then lays our code on top.
FROM php:8.3-cli-bookworm AS build
RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
# Composer now refuses to install package versions that have security advisories.
# The whole Laravel 11.x line is currently flagged, so relax this policy for the build.
RUN composer config --global policy.advisories.block false || true

WORKDIR /src
# 1) fresh Laravel 11 skeleton (artisan, public/, storage/, default config files, etc.)
RUN composer create-project laravel/laravel:^11.0 app \
        --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs
WORKDIR /src/app
# 2) core SaaS dependencies (must succeed)
RUN composer require \
        filament/filament:^3.2 \
        predis/predis:^2.2 \
        laravel/horizon:^5.0 \
        --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs --with-all-dependencies
# 3) optional dependency for Phase 2 NLU (don't fail the build if it can't resolve)
RUN composer require openai-php/laravel \
        --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs --with-all-dependencies || true
# 4) overlay our application code (merges into the skeleton, keeps skeleton defaults)
COPY app/        ./app/
COPY config/     ./config/
COPY database/   ./database/
COPY routes/     ./routes/
COPY bootstrap/  ./bootstrap/
COPY resources/  ./resources/
# 5) rebuild the optimized autoloader now that our code + composer.json are in place
RUN composer dump-autoload --optimize --no-interaction --ignore-platform-reqs

# ========== Stage 2: runtime (php-fpm + nginx + supervisor) ==========
FROM php:8.3-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor libpq-dev libzip-dev libpng-dev libonig-dev libicu-dev unzip \
    && docker-php-ext-install pdo pdo_pgsql pgsql gd zip intl bcmath opcache pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY --from=build /src/app /var/www/html

COPY docker/nginx.conf       /etc/nginx/sites-available/default
COPY docker/supervisord.conf /etc/supervisor/conf.d/app.conf
COPY docker/php.ini          /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/entrypoint.sh    /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint"]
