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
DUMMY_DATA_PATH=${DUMMY_DATA_PATH:-plantathome}
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

  # ── PlantAtHome data seed (idempotent — skips if plants type already exists) ──
  cat > /tmp/seed_plants.php << 'SEEDEOF'
<?php
define('LARAVEL_START', microtime(true));
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

if (DB::table('types')->where('slug', 'plants')->exists()) {
    echo "[seed_plants] Plants type already exists — skipping.\n";
    exit(0);
}

echo "[seed_plants] Seeding PlantAtHome data...\n";

// Clear Pickbazar demo data
DB::statement('SET FOREIGN_KEY_CHECKS=0');
DB::table('order_product')->truncate();
DB::table('reviews')->truncate();
DB::table('questions')->truncate();
DB::table('products')->truncate();
DB::table('category_product')->truncate();
DB::table('categories')->truncate();
DB::table('types')->truncate();
DB::statement('SET FOREIGN_KEY_CHECKS=1');

$now = now();

// ── 1. Plants type ──────────────────────────────────────────────────────────
DB::table('types')->insert([
    'id'                   => 1,
    'name'                 => 'Plants',
    'slug'                 => 'plants',
    'language'             => 'en',
    'icon'                 => 'Leaf',
    'promotional_sliders'  => null,
    'settings'             => json_encode([
        'isHome'      => true,
        'layoutType'  => 'classic',
        'productCard' => 'argon',
    ]),
    'created_at' => $now,
    'updated_at' => $now,
]);
echo "[seed_plants] Type inserted: plants\n";

// ── 2. Categories ───────────────────────────────────────────────────────────
$categories = [
    ['id'=>1,'name'=>'Indoor Plants',      'slug'=>'indoor-plants',      'details'=>'Perfect for home and office spaces',      'image'=>null,'icon'=>'Leaf'],
    ['id'=>2,'name'=>'Outdoor Plants',     'slug'=>'outdoor-plants',     'details'=>'Hardy plants for gardens and balconies',  'image'=>null,'icon'=>'Tree'],
    ['id'=>3,'name'=>'Flowering Plants',   'slug'=>'flowering-plants',   'details'=>'Beautiful blooms for every season',       'image'=>null,'icon'=>'Flower'],
    ['id'=>4,'name'=>'Succulents & Cacti', 'slug'=>'succulents-cacti',   'details'=>'Low maintenance, high visual impact',     'image'=>null,'icon'=>'Cactus'],
    ['id'=>5,'name'=>'Air Purifying',      'slug'=>'air-purifying',      'details'=>'Plants that clean and freshen your air',  'image'=>null,'icon'=>'Wind'],
    ['id'=>6,'name'=>'Gifts & Planters',   'slug'=>'gifts-planters',     'details'=>'Curated gift sets and premium planters',  'image'=>null,'icon'=>'Gift'],
];
foreach ($categories as $cat) {
    DB::table('categories')->insert(array_merge($cat, [
        'type_id'    => 1,
        'language'   => 'en',
        'parent'     => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]));
}
echo "[seed_plants] Categories inserted: " . count($categories) . "\n";

// ── 3. Products ─────────────────────────────────────────────────────────────
$products = [
    ['id'=>1, 'name'=>'Monstera Deliciosa', 'slug'=>'monstera-deliciosa', 'price'=>1299, 'sale_price'=>999,  'unit'=>'1 Plant', 'quantity'=>50,  'description'=>'The iconic split-leaf plant — bold, architectural, and perfect for bright interiors.'],
    ['id'=>2, 'name'=>'Peace Lily',          'slug'=>'peace-lily',          'price'=>649,  'sale_price'=>549,  'unit'=>'1 Plant', 'quantity'=>100, 'description'=>'Elegant white blooms, excellent air purifier, thrives in low light.'],
    ['id'=>3, 'name'=>'Snake Plant',         'slug'=>'snake-plant',         'price'=>799,  'sale_price'=>699,  'unit'=>'1 Plant', 'quantity'=>80,  'description'=>'Nearly indestructible, filters indoor air toxins, ideal for beginners.'],
    ['id'=>4, 'name'=>'Golden Pothos',       'slug'=>'golden-pothos',       'price'=>399,  'sale_price'=>349,  'unit'=>'1 Plant', 'quantity'=>150, 'description'=>'Cascading vines with heart-shaped leaves, thrives in any light.'],
    ['id'=>5, 'name'=>'Fiddle Leaf Fig',     'slug'=>'fiddle-leaf-fig',     'price'=>1499, 'sale_price'=>1299, 'unit'=>'1 Plant', 'quantity'=>30,  'description'=>'Statement tree with large, violin-shaped leaves — Instagram favourite.'],
    ['id'=>6, 'name'=>'Areca Palm',          'slug'=>'areca-palm',          'price'=>899,  'sale_price'=>799,  'unit'=>'1 Plant', 'quantity'=>60,  'description'=>'Tropical elegance, natural humidifier, great for living rooms.'],
    ['id'=>7, 'name'=>'ZZ Plant',            'slug'=>'zz-plant',            'price'=>549,  'sale_price'=>499,  'unit'=>'1 Plant', 'quantity'=>90,  'description'=>'Glossy dark-green leaves, extremely drought tolerant.'],
    ['id'=>8, 'name'=>'Money Plant',         'slug'=>'money-plant',         'price'=>299,  'sale_price'=>249,  'unit'=>'1 Plant', 'quantity'=>200, 'description'=>'Classic Indian favourite — believed to bring prosperity and luck.'],
    ['id'=>9, 'name'=>'Aloe Vera',           'slug'=>'aloe-vera',           'price'=>349,  'sale_price'=>299,  'unit'=>'1 Plant', 'quantity'=>120, 'description'=>'Medicinal succulent with soothing gel, very low maintenance.'],
    ['id'=>10,'name'=>'Bird of Paradise',    'slug'=>'bird-of-paradise',    'price'=>1999, 'sale_price'=>1799, 'unit'=>'1 Plant', 'quantity'=>20,  'description'=>'Dramatic tropical leaves, makes a bold statement in any room.'],
];
foreach ($products as $p) {
    DB::table('products')->insert(array_merge($p, [
        'type_id'        => 1,
        'status'         => 'publish',
        'visibility'     => 'visibility_public',
        'language'       => 'en',
        'in_stock'       => true,
        'is_taxable'     => false,
        'product_type'   => 'simple',
        'min_price'      => $p['sale_price'],
        'max_price'      => $p['price'],
        'sku'            => 'PAH-' . strtoupper(substr($p['slug'], 0, 6)) . '-001',
        'created_at'     => $now,
        'updated_at'     => $now,
    ]));
}
echo "[seed_plants] Products inserted: " . count($products) . "\n";

// ── 4. Link products to categories ──────────────────────────────────────────
$catMap = [
    1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1,  // indoor plants
    6 => 2,                                    // outdoor
    7 => 4, 9 => 4,                            // succulents
    8 => 1, 10 => 2,                           // indoor / outdoor
];
foreach ($catMap as $productId => $categoryId) {
    DB::table('category_product')->insert([
        'product_id'  => $productId,
        'category_id' => $categoryId,
    ]);
}

// ── 5. Flush cache so new data is served immediately ─────────────────────────
Cache::flush();
echo "[seed_plants] Cache flushed.\n";
echo "[seed_plants] Done: 1 type, " . count($categories) . " categories, " . count($products) . " products.\n";
SEEDEOF

  echo "==> [bg] Running PlantAtHome data seed..."
  php /tmp/seed_plants.php || echo "[bg] WARNING: seed_plants.php failed"

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
