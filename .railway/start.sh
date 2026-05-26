#!/bin/sh

echo "==> APP_ENV=${APP_ENV} DB_HOST=${DB_HOST} PORT=${PORT}"

echo "==> Running migrations (non-fatal)..."
php artisan migrate --force || echo "WARNING: Migrations failed, DB may not be ready yet"

echo "==> Clearing config cache..."
php artisan config:clear || true
php artisan route:clear || true

echo "==> Starting Laravel server on port ${PORT:-8080}..."
exec php artisan serve --host=0.0.0.0 --port=${PORT:-8080}
