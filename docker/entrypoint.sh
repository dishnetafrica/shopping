#!/usr/bin/env bash
set -e
cd /var/www/html

# generate key if not provided
if [ -z "$APP_KEY" ] && ! grep -q "^APP_KEY=base64" .env 2>/dev/null; then
  php artisan key:generate --force || true
fi

# wait for the database, then migrate (idempotent)
echo "Waiting for database..."
until php artisan migrate:status >/dev/null 2>&1 || [ "$?" = "1" ]; do sleep 2; done
php artisan migrate --force || true

# first-boot seed (creates super admin + demo tenant) only if no tenants yet
php artisan db:seed --class=Database\\Seeders\\InitialSeeder --force || true

php artisan storage:link || true
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache || true

exec supervisord -c /etc/supervisor/conf.d/app.conf
