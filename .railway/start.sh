#!/bin/sh

echo "==> Setting up .env..."
cp /var/www/html/.env.staging.example /var/www/html/.env
php artisan key:generate --force

echo "==> Starting with PORT=${PORT:-3000} APP_ENV=${APP_ENV}"

echo "==> Running migrations (non-fatal)..."
php artisan migrate --force || echo "WARNING: Migrations failed"

echo "==> Clearing cache..."
php artisan config:clear || true
php artisan route:clear || true

echo "==> Starting Laravel server on 0.0.0.0:${PORT:-3000}..."
exec php artisan serve --host=0.0.0.0 --port=${PORT:-3000}
