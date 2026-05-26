#!/bin/sh
set -e

echo "==> Running migrations..."
php artisan migrate --force

echo "==> Clearing and caching config..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Starting services..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
