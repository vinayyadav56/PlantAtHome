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
FILESYSTEM_DISK=s3
QUEUE_CONNECTION=${QUEUE_CONNECTION:-database}
SESSION_DRIVER=file
SANCTUM_STATEFUL_DOMAINS=plantathome-shop-staging.vercel.app,plantathome-admin-staging.vercel.app
RAZORPAY_KEY_ID=${RAZORPAY_KEY_ID:-}
RAZORPAY_KEY_SECRET=${RAZORPAY_KEY_SECRET:-}
MEDIA_DISK=s3
AWS_ACCESS_KEY_ID=${AWS_ACCESS_KEY_ID}
AWS_SECRET_ACCESS_KEY=${AWS_SECRET_ACCESS_KEY}
AWS_DEFAULT_REGION=${AWS_DEFAULT_REGION:-ap-south-1}
AWS_BUCKET=${AWS_BUCKET:-plantathome-media-prod}
MAIL_MAILER=smtp
MAIL_HOST=smtp.sendgrid.net
MAIL_PORT=587
MAIL_USERNAME=apikey
MAIL_PASSWORD=${SENDGRID_API_KEY:-}
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@plantathome.in
ADMIN_EMAIL=${ADMIN_EMAIL:-yadavvinay9996@gmail.com}
DUMMY_DATA_PATH=${DUMMY_DATA_PATH:-plantathome}
SHIPPING_SERVICE_ENABLED=${SHIPPING_SERVICE_ENABLED:-false}
SHIPPING_SERVICE_URL=${SHIPPING_SERVICE_URL:-}
SHIPPING_SERVICE_API_KEY=${SHIPPING_SERVICE_API_KEY:-}
SHIPPING_SERVICE_CALLBACK_KEY=${SHIPPING_SERVICE_CALLBACK_KEY:-}
SHIPPING_SERVICE_TIMEOUT=${SHIPPING_SERVICE_TIMEOUT:-15}
COURIER_PROVIDER=${COURIER_PROVIDER:-}
BORZO_TOKEN=${BORZO_TOKEN:-}
BORZO_BASE_URL=${BORZO_BASE_URL:-}
BORZO_CALLBACK_TOKEN=${BORZO_CALLBACK_TOKEN:-}
BORZO_MATTER=${BORZO_MATTER:-}
BORZO_VEHICLE_TYPE_ID=${BORZO_VEHICLE_TYPE_ID:-}
SHIPROCKET_API_TOKEN=${SHIPROCKET_API_TOKEN:-}
SHIPROCKET_EMAIL=${SHIPROCKET_EMAIL:-}
SHIPROCKET_PASSWORD=${SHIPROCKET_PASSWORD:-}
SHIPROCKET_BASE_URL=${SHIPROCKET_BASE_URL:-}
SHIPROCKET_WEBHOOK_TOKEN=${SHIPROCKET_WEBHOOK_TOKEN:-}
MSG91_AUTH_KEY=${MSG91_AUTH_KEY:-}
MSG91_SENDER=${MSG91_SENDER:-}
MSG91_TEMPLATE_ID=${MSG91_TEMPLATE_ID:-}
MSG91_FLOW_ID=${MSG91_FLOW_ID:-}
WHATSAPP_ACCESS_TOKEN=${WHATSAPP_ACCESS_TOKEN:-}
WHATSAPP_PHONE_NUMBER_ID=${WHATSAPP_PHONE_NUMBER_ID:-}
WHATSAPP_API_VERSION=${WHATSAPP_API_VERSION:-}
WHATSAPP_OTP_TEMPLATE=${WHATSAPP_OTP_TEMPLATE:-}
WHATSAPP_OTP_LANG=${WHATSAPP_OTP_LANG:-}
WHATSAPP_OTP_HAS_BUTTON=${WHATSAPP_OTP_HAS_BUTTON:-}
WHATSAPP_NOTIFY_TEMPLATE=${WHATSAPP_NOTIFY_TEMPLATE:-}
WHATSAPP_NOTIFY_LANG=${WHATSAPP_NOTIFY_LANG:-}
MARVEL_FORCE_TRUST=${MARVEL_FORCE_TRUST:-}
MARKETPLACE_LEDGER=${MARKETPLACE_LEDGER:-}
GOOGLE_MAPS_SERVER_KEY=${GOOGLE_MAPS_SERVER_KEY:-}
ENVEOF

cd /var/www/html

echo "==> Ensuring storage directories exist with correct permissions..."
# /tmp/nginx_fastcgi_temp: spillover dir for the fastcgi_temp_path in nginx.conf
# (large PHP-FPM responses beyond the in-memory fastcgi_buffers land here instead
# of being truncated). World-writable like the existing client_body temp dir since
# the nginx worker (www-data) must be able to create files here.
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/public /tmp/nginx_client_body /tmp/nginx_fastcgi_temp
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true
chmod -R 777 /tmp/nginx_client_body /tmp/nginx_fastcgi_temp 2>/dev/null || true

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

  # F4 — module-based roles & permissions (employees + RBAC). Idempotent +
  # additive (givePermissionTo), so re-running every deploy never revokes.
  echo "==> [bg] Seeding module-based roles & permissions (F4)..."
  php artisan db:seed --class="Marvel\\Database\\Seeders\\RolePermissionSeeder" --force \
    || echo "[bg] WARNING: RolePermissionSeeder failed"

  # Phase B — example designations (permission templates). Idempotent
  # (updateOrCreate by slug); refreshes canonical defaults, never drops
  # admin-created designations.
  echo "==> [bg] Seeding designations (Phase B)..."
  php artisan db:seed --class="Marvel\\Database\\Seeders\\DesignationSeeder" --force \
    || echo "[bg] WARNING: DesignationSeeder failed"

  # Operations Control Center — one active global row per vertical (+ platform).
  # Idempotent firstOrCreate, never resets admin toggles; all-active = no-op.
  echo "==> [bg] Seeding service availability (Operations Control Center)..."
  php artisan db:seed --class="Marvel\\Database\\Seeders\\ServiceAvailabilitySeeder" --force \
    || echo "[bg] WARNING: ServiceAvailabilitySeeder failed"

  # Master Location System (Phase 2) — states + cities (from delivery_pincodes).
  # Idempotent + additive (firstOrCreate), never overwrites admin city status.
  echo "==> [bg] Seeding master locations (states + cities)..."
  php artisan db:seed --class="Marvel\\Database\\Seeders\\LocationSeeder" --force \
    || echo "[bg] WARNING: LocationSeeder failed"

  # v2 modular-monolith Identity (Phase 1) — RBAC roles/permissions grants
  # (idempotent, all envs) + demo users (non-production only). Additive; does
  # not touch the legacy marvel auth stack.
  echo "==> [bg] Seeding v2 Identity access (roles/permissions)..."
  php artisan db:seed --class="App\\Modules\\Identity\\Database\\Seeders\\IdentityAccessSeeder" --force \
    || echo "[bg] WARNING: IdentityAccessSeeder failed"

  # v2 Rules Engine (Phase 4) — the five Section-6 worked examples as DATA rows
  # (idempotent by name). Proof that behaviour is data, not code.
  echo "==> [bg] Seeding v2 Rules Engine examples..."
  php artisan db:seed --class="App\\Modules\\Rules\\Database\\Seeders\\RulesExampleSeeder" --force \
    || echo "[bg] WARNING: RulesExampleSeeder failed"

  # ── PlantAtHome data seed — enterprise three-tier approach ──────────────────
  # Tier 2: type + categories — updateOrCreate, safe for ALL environments
  # Tier 3: demo products    — truncate+insert, STAGING only (guarded in seeder)
  APP_ENV_VAL=$(grep "^APP_ENV=" /var/www/html/.env 2>/dev/null | cut -d= -f2 | tr -d '[:space:]')
  APP_ENV_VAL="${APP_ENV_VAL:-staging}"
  echo "==> [bg] Running PlantAtHome seeders (APP_ENV=${APP_ENV_VAL})..."

  cd /var/www/html

  php artisan db:seed --class="Marvel\\Database\\Seeders\\PlantAtHomeTypeSeeder" --force \
    || echo "[bg] WARNING: PlantAtHomeTypeSeeder failed"

  php artisan db:seed --class="Marvel\\Database\\Seeders\\PlantAtHomeCategorySeeder" --force \
    || echo "[bg] WARNING: PlantAtHomeCategorySeeder failed"

  echo "==> [bg] [4a/7] Real plant categories (193)..."
  php artisan db:seed --class="Marvel\\Database\\Seeders\\PlantAtHomeCategoryBulkSeeder" --force \
    || echo "[bg] WARNING: PlantAtHomeCategoryBulkSeeder failed"

  echo "==> [bg] [4b/7] Real plant catalog (1530 plants + attributes)..."
  php artisan db:seed --class="Marvel\\Database\\Seeders\\PlantAtHomePlantBulkSeeder" --force \
    || echo "[bg] WARNING: PlantAtHomePlantBulkSeeder failed"

  echo "==> [bg] [4c/7] Tools catalog (gardening tools for the Tools vertical)..."
  php artisan db:seed --class="Marvel\\Database\\Seeders\\PlantAtHomeToolsSeeder" --force \
    || echo "[bg] WARNING: PlantAtHomeToolsSeeder failed"

  if [ "$APP_ENV_VAL" = "staging" ]; then
    echo "==> [bg] Staging env — running Tier 3 demo product seed..."
    php artisan db:seed --class="Marvel\\Database\\Seeders\\PlantAtHomeProductSeeder" --force \
      || echo "[bg] WARNING: PlantAtHomeProductSeeder failed"
  else
    echo "==> [bg] Non-staging env (${APP_ENV_VAL}) — Tier 3 demo products skipped (production data preserved)."
  fi

  # Ensure the PlantAtHome shop exists and assign shop-less products to it so
  # plants can be edited/saved in the admin (shop_id is required). Idempotent.
  echo "==> [bg] Ensuring PlantAtHome shop + assigning products..."
  php artisan plantathome:assign-shop \
    || echo "[bg] WARNING: assign-shop failed"

  # Replace the 193 granular plant categories with ~10 curated type categories
  # and re-assign plants (clean, usable filter). Idempotent.
  echo "==> [bg] Categorising plants into curated type categories..."
  php artisan plantathome:categorize-plants \
    || echo "[bg] WARNING: categorize-plants failed"

  # Give the catalog real prices: convert plants to variable products with
  # Small/Medium/Large size pricing + stock; flat-price the demo tools/farmbox.
  # Deterministic + idempotent (preserves admin per-size edits).
  echo "==> [bg] Applying size-based pricing to plants..."
  php artisan plantathome:apply-size-pricing \
    || echo "[bg] WARNING: apply-size-pricing failed"

  # Rebuild image/gallery columns from the product_images library so every
  # downloaded photo shows up on the storefront + product edit page (idempotent).
  echo "==> [bg] Syncing plant image/gallery columns from the photo library..."
  php artisan plantathome:sync-image-columns \
    || echo "[bg] WARNING: sync-image-columns failed"

  # Backfill unique SKUs ({VERTICAL}-{CATEGORY}-{SEQ}) on any product without one.
  echo "==> [bg] Generating product SKUs..."
  php artisan plantathome:generate-skus \
    || echo "[bg] WARNING: generate-skus failed"

  # Force the license trust flag ON (prevents recurring "credentials was wrong"
  # if the redq license re-check flips it off). Idempotent.
  echo "==> [bg] Ensuring license trust flag..."
  php artisan plantathome:ensure-trust || echo "[bg] WARNING: ensure-trust failed (env MARVEL_FORCE_TRUST still applies)"

  php artisan config:clear || true
  php artisan route:clear  || true
  php artisan view:clear   || true
  echo "==> [bg] Setup done."
) &

echo "==> Starting nginx + php-fpm via supervisord on port ${PORT:-80}..."
/usr/bin/supervisord -c /etc/supervisord.conf &
SUPERVISORD_PID=$!

# Warm up settings cache so Railway health check + first ISR revalidation never see a cold 404
echo "==> Waiting for API to respond before warming settings cache..."
for i in $(seq 1 40); do
  sleep 3
  if curl -sf "http://localhost:${PORT:-80}/api/health" > /dev/null 2>&1; then
    echo "==> API ready — warming /api/settings?language=en cache..."
    curl -sf "http://localhost:${PORT:-80}/api/settings?language=en" > /dev/null 2>&1 || true
    echo "==> Settings cache warmed."
    break
  fi
done

wait $SUPERVISORD_PID

# media on S3 (staging) — rebuild marker
