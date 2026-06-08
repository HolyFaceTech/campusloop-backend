#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
    cp .env.example .env
fi

git config --global --add safe.directory /var/www/html 2>/dev/null || true
export COMPOSER_ALLOW_SUPERUSER=1

echo "Installing Composer dependencies..."
composer install --no-interaction --prefer-dist

echo "Waiting for database..."
attempt=0
until php artisan db:show --no-interaction >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "Database not reachable after 60 seconds."
        php artisan db:show --no-interaction || true
        exit 1
    fi
    sleep 2
done

if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    php artisan key:generate --force --no-interaction
fi

php artisan migrate --force --no-interaction
php artisan storage:link --force 2>/dev/null || true

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

exec "$@"
