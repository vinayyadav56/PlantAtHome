<?php
/**
 * Per-endpoint cost harness.
 *
 * Boots the application once, then dispatches real HTTP requests through the
 * kernel in-process and records what each one actually costs: query count,
 * duplicate queries, DB time, wall time, peak memory and response bytes.
 *
 * In-process on purpose. The point is to attribute cost to application logic,
 * not to measure the network — a curl loop tells you the total and nothing
 * about why. Wall time here EXCLUDES php-fpm startup, nginx and TCP, so treat
 * it as service time, not as user-perceived latency.
 *
 *   php tests/Performance/bench.php --env=perf [--iterations=N] [--json=path]
 *                                   [--auth] [--warm] [--only=substr]
 *
 * Exits non-zero if any endpoint returns a non-2xx, so it can gate CI.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../vendor/autoload.php';

// ---------------------------------------------------------------- args
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}
$iterations = (int) ($args['iterations'] ?? 5);
$jsonOut    = $args['json'] ?? null;
$only       = $args['only'] ?? null;
$warm       = isset($args['warm']);
$withAuth   = isset($args['auth']);

// `--env=perf` has to be visible to the framework before it boots.
if (!empty($args['env'])) {
    $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $args['env'];
    putenv('APP_ENV=' . $args['env']);
}

$app = require __DIR__ . '/../../bootstrap/app.php';
/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
// Facades are unavailable until the kernel bootstraps; we use DB/cache below
// before dispatching anything, so bootstrap explicitly rather than relying on
// the first handle() to do it.
$kernel->bootstrap();

// ---------------------------------------------------------------- scenarios
// Read-path only. Every one of these is confirmed to touch zero third parties,
// which is what makes it safe to hammer.
$scenarios = [
    ['name' => 'settings',            'uri' => '/api/settings?language=en'],
    ['name' => 'types',               'uri' => '/api/types?limit=100&language=en'],
    ['name' => 'categories.root',     'uri' => '/api/categories?parent=null&limit=100&language=en'],
    ['name' => 'categories.home',     'uri' => '/api/categories?type=plants&parent=null&limit=12&home=1&language=en'],
    ['name' => 'products.list.20',    'uri' => '/api/products?limit=20&language=en'],
    ['name' => 'products.list.100',   'uri' => '/api/products?limit=100&language=en'],
    ['name' => 'products.by_type',    'uri' => '/api/products?limit=20&type=plants&language=en'],
    ['name' => 'products.page50',     'uri' => '/api/products?limit=20&page=50&language=en'],
    ['name' => 'products.facets',     'uri' => '/api/products/filter-facets?type=plants&language=en'],
    ['name' => 'popular-products',    'uri' => '/api/popular-products?limit=10&language=en'],
    ['name' => 'best-selling',        'uri' => '/api/best-selling-products?limit=10&language=en'],
    ['name' => 'top-rated',           'uri' => '/api/top-rated-products?limit=10&language=en'],
];

// PDP needs a real slug from the seeded catalogue.
$slug = DB::table('products')->where('status', 'publish')->value('slug');
if ($slug) {
    $scenarios[] = ['name' => 'product.show', 'uri' => '/api/products/' . $slug . '?language=en'];
}

if ($only) {
    $scenarios = array_values(array_filter(
        $scenarios,
        fn ($s) => str_contains($s['name'], $only)
    ));
}

// ---------------------------------------------------------------- probe
/**
 * Dispatch one request and return its cost.
 *
 * DB::listen is registered per-probe and the log reset each time, so a query
 * belongs to exactly one measurement.
 */
function probe(Kernel $kernel, string $uri, bool $auth): array
{
    $queries = [];
    DB::flushQueryLog();
    DB::listen(function ($q) use (&$queries) {
        $queries[] = ['sql' => $q->sql, 'time' => $q->time];
    });

    $request = Request::create($uri, 'GET');
    $request->headers->set('Accept', 'application/json');
    // A bearer token — even an empty one — disables the response cache, so the
    // anonymous path must send no Authorization header at all.
    if ($auth) {
        $request->headers->set('Authorization', 'Bearer perf-harness-token');
    }

    gc_collect_cycles();
    $memBefore = memory_get_usage(true);
    $t0        = hrtime(true);

    $response = $kernel->handle($request);
    $body     = $response->getContent();

    $wallMs = (hrtime(true) - $t0) / 1e6;
    $memMb  = (memory_get_usage(true) - $memBefore) / 1048576;

    // Duplicate = byte-identical SQL executed more than once in one request.
    // That is the N+1 signature: same statement, different bindings collapses
    // to the same normalised string because bindings are not interpolated here.
    $counts = array_count_values(array_column($queries, 'sql'));
    $dupes  = array_filter($counts, fn ($n) => $n > 1);
    arsort($dupes);

    return [
        'status'      => $response->getStatusCode(),
        'wall_ms'     => round($wallMs, 2),
        'db_ms'       => round(array_sum(array_column($queries, 'time')), 2),
        'queries'     => count($queries),
        'dupe_groups' => count($dupes),
        'dupe_total'  => array_sum($dupes) - count($dupes), // wasted executions
        'top_dupes'   => array_slice(
            array_map(
                fn ($sql, $n) => ['n' => $n, 'sql' => substr(preg_replace('/\s+/', ' ', $sql), 0, 150)],
                array_keys($dupes),
                array_values($dupes)
            ),
            0,
            3
        ),
        'bytes'       => strlen($body),
        'mem_mb'      => round($memMb, 2),
    ];
}

// ---------------------------------------------------------------- run
$results = [];
$failed  = 0;

fwrite(STDERR, sprintf(
    "bench: %d scenarios x %d iterations | cache=%s | auth=%s | %s\n",
    count($scenarios),
    $iterations,
    config('cache.default'),
    $withAuth ? 'yes' : 'no',
    $warm ? 'warm cache' : 'cold cache each run'
));

foreach ($scenarios as $s) {
    if ($warm) {
        // Prime first, then measure. Without this the first iteration populates
        // the cache and the rest read it, so query count came from a cold run
        // while wall time came from warm ones — two different systems in one row.
        probe($kernel, $s['uri'], $withAuth);
    } else {
        // Cold: the response cache must not carry between iterations, or every
        // run after the first measures the cache and not the endpoint.
        cache()->flush();
    }

    $runs = [];
    for ($i = 0; $i < $iterations; $i++) {
        $runs[] = probe($kernel, $s['uri'], $withAuth);
        if (!$warm) {
            cache()->flush();
        }
    }

    $walls = array_column($runs, 'wall_ms');
    sort($walls);
    $first = $runs[0];

    if ($first['status'] < 200 || $first['status'] >= 300) {
        $failed++;
    }

    $results[] = [
        'name'        => $s['name'],
        'uri'         => $s['uri'],
        'status'      => $first['status'],
        'queries'     => $first['queries'],
        'dupe_groups' => $first['dupe_groups'],
        'dupe_total'  => $first['dupe_total'],
        'top_dupes'   => $first['top_dupes'],
        'bytes'       => $first['bytes'],
        'db_ms'       => $first['db_ms'],
        'mem_mb'      => $first['mem_mb'],
        'wall_min'    => $walls[0],
        'wall_med'    => $walls[intdiv(count($walls), 2)],
        'wall_max'    => $walls[count($walls) - 1],
    ];

    printf(
        "%-22s %3d  q=%-4d dup=%-4d %7.1fms db=%6.1fms %8s  %s\n",
        $s['name'],
        $first['status'],
        $first['queries'],
        $first['dupe_total'],
        $walls[intdiv(count($walls), 2)],
        $first['db_ms'],
        number_format($first['bytes'] / 1024, 1) . 'KB',
        $first['status'] >= 300 ? '  <-- NON-2xx' : ''
    );
}

if ($jsonOut) {
    file_put_contents($jsonOut, json_encode([
        'generated_at' => date('c'),
        'env'          => app()->environment(),
        'cache_driver' => config('cache.default'),
        'db'           => config('database.connections.' . config('database.default') . '.database'),
        'php'          => PHP_VERSION,
        'iterations'   => $iterations,
        'warm'         => $warm,
        'auth'         => $withAuth,
        'results'      => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fwrite(STDERR, "wrote {$jsonOut}\n");
}

exit($failed > 0 ? 1 : 0);
