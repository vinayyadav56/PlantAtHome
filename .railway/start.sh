#!/bin/sh

echo "==> Creating .env from environment variables..."
cat > /app/.env << ENVEOF
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

cd /app
php artisan key:generate --force

echo "==> Running migrations (non-fatal)..."
php artisan migrate --force || echo "WARNING: Migrations failed"

echo "==> Clearing cache..."
php artisan config:clear || true
php artisan route:clear || true

echo "==> Starting Laravel server on 0.0.0.0:${PORT:-3000}..."
exec php artisan serve --host=0.0.0.0 --port=${PORT:-3000}
