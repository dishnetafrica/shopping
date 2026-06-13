#!/usr/bin/env bash
set -e
cd /var/www/html

# Laravel needs a .env file to exist; EasyPanel injects the real values as env vars.
[ -f .env ] || { [ -f .env.example ] && cp .env.example .env || touch .env; }

# App key: prefer the APP_KEY env var; only generate if it's missing.
if [ -z "$APP_KEY" ]; then php artisan key:generate --force || true; fi

# Make sure package providers (Filament, Horizon) are discovered.
php artisan package:discover --ansi || true

# Wait for Postgres to accept connections.
echo "Waiting for database..."
until php -r '
  try {
    new PDO("pgsql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT")?:"5432").";dbname=".getenv("DB_DATABASE"),
            getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
    exit(0);
  } catch (Throwable $e) { exit(1); }' 2>/dev/null; do
  echo "  ...db not ready yet"; sleep 2;
done

php artisan migrate --force || true
php artisan db:seed --class=Database\\Seeders\\InitialSeeder --force || true

php artisan storage:link || true
php artisan filament:assets || true
php artisan config:cache || true   # NOTE: no route:cache (routes use a closure) / no view:cache

chown -R www-data:www-data storage bootstrap/cache || true
exec supervisord -c /etc/supervisor/conf.d/app.conf
