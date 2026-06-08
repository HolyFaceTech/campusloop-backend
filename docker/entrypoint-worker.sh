#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
    cp .env.example .env
fi

git config --global --add safe.directory /var/www/html 2>/dev/null || true
export COMPOSER_ALLOW_SUPERUSER=1

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

echo "Waiting for database..."
attempt=0
until php artisan db:show --no-interaction >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "Database not reachable after 60 seconds."
        exit 1
    fi
    sleep 2
done

exec "$@"
