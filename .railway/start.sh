#!/bin/sh

echo "==> Creating .env from environment variables..."
cat > /var/www/html/.env << ENVEOF
APP_NAME=PlantAtHome
APP_ENV=staging
APP_KEY=
APP_DEBUG=false
APP_URL=https://plantathome-api-production.up.railway.app
LOG_CHANNEL=stderr
DB_CONNECTION=mysql
DB_HOST=${MYSQLHOST}
DB_PORT=${MYSQLPORT:-3306}
DB_DATABASE=${MYSQLDATABASE}
DB_USERNAME=${MYSQLUSER}
DB_PASSWORD=${MYSQLPASSWORD}
BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SANCTUM_STATEFUL_DOMAINS=plantathome-staging.vercel.app,plantathome-admin-staging.vercel.app
RAZORPAY_KEY=rzp_test_Sth5xcsZyNoPR4
RAZORPAY_SECRET=${RAZORPAY_SECRET:-}
MEDIA_DISK=local
ENVEOF

cd /var/www/html
php artisan key:generate --force

echo "==> Running migrations (non-fatal)..."
php artisan migrate --force || echo "WARNING: Migrations failed"

echo "==> Clearing cache..."
php artisan config:clear || true
php artisan route:clear || true

echo "==> Configuring nginx to listen on port ${PORT:-80}..."
sed -i "s/listen 80;/listen ${PORT:-80};/g" /etc/nginx/nginx.conf

echo "==> Starting nginx + php-fpm via supervisord on port ${PORT:-80}..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
