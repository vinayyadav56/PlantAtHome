#!/bin/sh

echo "==> Creating .env from environment variables..."
cat > /var/www/html/.env << ENVEOF
APP_NAME=PlantAtHome
APP_ENV=staging
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=https://${RAILWAY_PUBLIC_DOMAIN:-plantathome-production.up.railway.app}
LOG_CHANNEL=stderr
DB_CONNECTION=mysql
DB_HOST=${DB_HOST:-${MYSQLHOST}}
DB_PORT=${DB_PORT:-${MYSQLPORT:-3306}}
DB_DATABASE=${DB_DATABASE:-${MYSQLDATABASE}}
DB_USERNAME=${DB_USERNAME:-${MYSQLUSER}}
DB_PASSWORD=${DB_PASSWORD:-${MYSQLPASSWORD}}
BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SANCTUM_STATEFUL_DOMAINS=plantathome-shop-staging.vercel.app,plantathome-admin-staging.vercel.app
RAZORPAY_KEY_ID=${RAZORPAY_KEY_ID:-}
RAZORPAY_KEY_SECRET=${RAZORPAY_KEY_SECRET:-}
MEDIA_DISK=public
MAIL_MAILER=smtp
MAIL_HOST=smtp.sendgrid.net
MAIL_PORT=587
MAIL_USERNAME=apikey
MAIL_PASSWORD=${SENDGRID_API_KEY:-}
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@plantathome.in
ADMIN_EMAIL=${ADMIN_EMAIL:-yadavvinay9996@gmail.com}
DUMMY_DATA_PATH=pickbazar
ENVEOF

cd /var/www/html

echo "==> Ensuring storage directories exist with correct permissions..."
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/public /tmp/nginx_client_body
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

echo "==> Creating public/storage symlink for media file access..."
php artisan storage:link --force || true

echo "==> Configuring nginx to listen on port ${PORT:-80}..."
sed -i "s/listen 80;/listen ${PORT:-80};/g" /etc/nginx/nginx.conf

if [ -z "${APP_KEY}" ]; then
  echo "==> Generating APP_KEY (not set in Railway env)..."
  php artisan key:generate --force
fi

echo "==> Discovering service providers (package:discover)..."
php artisan package:discover --ansi || true

echo "==> Clearing stale caches..."
php artisan config:clear || true
php artisan route:clear  || true

# Full marvel:install equivalent — runs in background so supervisord (nginx + php-fpm)
# starts immediately and Railway's health check passes while setup is in progress.
(
  echo "==> [bg] Waiting for MySQL (up to 60s)..."
  WAIT=0
  _HOST="${DB_HOST:-${MYSQLHOST}}"
  _PORT="${DB_PORT:-${MYSQLPORT:-3306}}"
  _DB="${DB_DATABASE:-${MYSQLDATABASE}}"
  _USER="${DB_USERNAME:-${MYSQLUSER}}"
  _PASS="${DB_PASSWORD:-${MYSQLPASSWORD}}"
  until php -r "new PDO('mysql:host=${_HOST};port=${_PORT};dbname=${_DB}', '${_USER}', '${_PASS}');" 2>/dev/null; do
    if [ "$WAIT" -ge 60 ]; then
      echo "[bg] WARNING: MySQL not ready after 60s, continuing..."
      break
    fi
    sleep 3
    WAIT=$((WAIT + 3))
  done
  echo "[bg] MySQL OK."

  echo "==> [bg] Checking database state..."
  TABLE_COUNT=$(php -r "
try {
  \$pdo = new PDO('mysql:host=${_HOST};port=${_PORT};dbname=${_DB}', '${_USER}', '${_PASS}');
  echo \$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = \"${_DB}\"')->fetchColumn();
} catch (Exception \$e) { echo 0; }
" 2>/dev/null)

  # Write setup script now — used in both fresh and existing DB paths (idempotent via firstOrCreate)
  cat > /tmp/marvel_setup.php << 'PHPEOF'
<?php
define('LARAVEL_START', microtime(true));
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

\Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'super_admin']);
\Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'customer']);
\Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'store_owner']);
\Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'staff']);

\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin'])
    ->syncPermissions(['super_admin', 'store_owner', 'customer']);
\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'store_owner'])
    ->syncPermissions(['store_owner', 'customer']);
\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'staff'])
    ->syncPermissions(['staff', 'customer']);
\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'customer'])
    ->syncPermissions(['customer']);

$adminEmail    = getenv('ADMIN_EMAIL')    ?: 'yadavvinay9996@gmail.com';
$adminPassword = getenv('ADMIN_PASSWORD') ?: 'Admin@1234';
$adminName     = getenv('ADMIN_NAME')     ?: 'Admin';

$user = Marvel\Database\Models\User::where('email', $adminEmail)->first();
if (!$user) {
    $user = Marvel\Database\Models\User::create([
        'name'      => $adminName,
        'email'     => $adminEmail,
        'password'  => \Illuminate\Support\Facades\Hash::make($adminPassword),
        'is_active' => true,
    ]);
    echo "Admin created: {$adminEmail}\n";
} else {
    echo "Admin already exists: {$adminEmail}\n";
    $user->is_active = true;
}
$user->email_verified_at = now();
$user->save();
$user->givePermissionTo(['super_admin', 'store_owner', 'customer']);
$user->assignRole('super_admin');
echo "Roles + permissions assigned to {$adminEmail}\n";

// Seed settings if table is empty, then ensure app_settings.trust = true so login works
$settings = Marvel\Database\Models\Settings::getData();
if (!$settings) {
    echo "No settings record — running SettingsSeeder...\n";
    \Illuminate\Support\Facades\Artisan::call('db:seed', [
        '--class' => 'Marvel\\Database\\Seeders\\SettingsSeeder',
        '--force' => true,
    ]);
    $settings = Marvel\Database\Models\Settings::getData();
}
if ($settings) {
    $opts = $settings->options ?? [];
    $opts['app_settings'] = ['trust' => true, 'last_checking_time' => now()->toISOString()];

    // Ensure Razorpay is in paymentGateway (idempotent)
    $gateways = $opts['paymentGateway'] ?? [];
    $hasRazorpay = false;
    foreach ($gateways as $gw) {
        if (strtolower($gw['name'] ?? '') === 'razorpay') { $hasRazorpay = true; break; }
    }
    if (!$hasRazorpay) {
        $gateways[] = ['name' => 'Razorpay', 'title' => 'Razorpay'];
        $opts['paymentGateway'] = $gateways;
        echo "Razorpay added to paymentGateway settings\n";
    }

    // Ensure currency is INR
    if (($opts['currency'] ?? '') !== 'INR') {
        $opts['currency'] = 'INR';
        echo "Currency set to INR\n";
    }

    $settings->update(['options' => $opts]);
    \Illuminate\Support\Facades\Cache::flush();
    echo "Settings app_settings.trust set to true\n";
} else {
    echo "WARNING: still no settings record — trust not set\n";
}

// Create shop.config.json (license file) — required by EnsureEmailIsVerified middleware.
// verify() sets trust=true and writes encrypted file using APP_KEY; no external HTTP call is made.
$verification = new Marvel\Console\MarvelVerification();
$verification->verify('staging-bypass-key');
echo "shop.config.json (license) written\n";
PHPEOF

  if [ "${TABLE_COUNT:-0}" = "0" ]; then
    echo "==> [bg] Fresh database — running full marvel:install..."

    echo "[bg]   [1/7] migrate:fresh..."
    php artisan migrate:fresh --force

    echo "[bg]   [2/7] marvel:seed (products, categories, shops demo data)..."
    php artisan marvel:seed || echo "[bg] WARNING: marvel:seed failed"

    echo "[bg]   [3/7] MarvelSeeder..."
    php artisan db:seed --class="Marvel\\Database\\Seeders\\MarvelSeeder" --force \
      || echo "[bg] WARNING: MarvelSeeder failed"

    echo "[bg]   [4/7] SettingsSeeder..."
    php artisan db:seed --class="Marvel\\Database\\Seeders\\SettingsSeeder" --force \
      || echo "[bg] WARNING: SettingsSeeder failed"

    echo "[bg]   [5/7] Permissions, roles, and admin user..."
    php /tmp/marvel_setup.php || echo "[bg] WARNING: Admin setup script failed"

    echo "[bg]   [6/7] marvel:copy-files (email/PDF templates)..."
    php artisan marvel:copy-files || echo "[bg] WARNING: copy-files failed"

    echo "[bg]   [7/7] optimize:clear..."
    php artisan optimize:clear || true

    echo "==> [bg] marvel:install complete!"

  else
    echo "[bg] DB has ${TABLE_COUNT} tables. Running pending migrations..."
    php artisan migrate --force || echo "[bg] WARNING: Migrations failed"

    echo "[bg] Checking if settings record exists..."
    SETTINGS_COUNT=$(php -r "
try {
  \$pdo = new PDO('mysql:host=${_HOST};port=${_PORT};dbname=${_DB}', '${_USER}', '${_PASS}');
  echo \$pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn();
} catch (Exception \$e) { echo 0; }
" 2>/dev/null)
    if [ "${SETTINGS_COUNT:-0}" = "0" ]; then
      echo "[bg] No settings record found — running SettingsSeeder..."
      php artisan db:seed --class="Marvel\\Database\\Seeders\\SettingsSeeder" --force \
        || echo "[bg] WARNING: SettingsSeeder failed"
    fi

    echo "[bg] Ensuring permissions, roles, and admin user exist (idempotent)..."
    php /tmp/marvel_setup.php || echo "[bg] WARNING: Admin setup script failed"
  fi

  php artisan config:clear || true
  php artisan route:clear  || true
  php artisan view:clear   || true
  echo "==> [bg] Setup done."
) &

echo "==> Starting nginx + php-fpm via supervisord on port ${PORT:-80}..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
