#!/bin/sh
set -e

# Railway injects PORT; nginx must listen on it
PORT=${PORT:-80}
sed -i "s/listen 80;/listen $PORT;/g" /etc/nginx/nginx.conf
echo "==> Nginx configured to listen on port $PORT"

echo "==> Running migrations..."
php artisan migrate --force

echo "==> Clearing and caching config..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Starting services..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
