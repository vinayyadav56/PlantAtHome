#!/bin/sh

# Railway injects PORT; nginx must listen on it
PORT=${PORT:-80}
sed -i "s/listen 80;/listen $PORT;/g" /etc/nginx/nginx.conf
echo "==> Nginx configured to listen on port $PORT"

echo "==> APP_ENV=$APP_ENV DB_HOST=$DB_HOST DB_DATABASE=$DB_DATABASE"

echo "==> Running migrations (non-fatal)..."
php artisan migrate --force || echo "WARNING: Migrations failed - continuing startup"

echo "==> Clearing and caching config..."
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

echo "==> Starting services..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
