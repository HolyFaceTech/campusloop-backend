#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
    cp .env.example .env
fi

git config --global --add safe.directory /var/www/html 2>/dev/null || true
export COMPOSER_ALLOW_SUPERUSER=1

is_production=false
if [ "${APP_ENV:-local}" = "production" ]; then
    is_production=true
fi

if [ ! -f vendor/autoload.php ]; then
    echo "Installing Composer dependencies..."
    if [ "$is_production" = true ]; then
        composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
    else
        composer install --no-interaction --prefer-dist
    fi
fi

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

if [ "$is_production" = true ]; then
    if [ "${MIGRATE_ON_START:-false}" = "true" ]; then
        php artisan migrate --force --no-interaction
    fi
else
    php artisan migrate --force --no-interaction
fi

php artisan storage:link --force 2>/dev/null || true

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

if [ "$is_production" = true ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
