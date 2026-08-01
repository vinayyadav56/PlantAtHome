<?php

/**
 * opcache preload script.
 *
 * Preloading compiles classes ONCE into shared memory at php-fpm master start,
 * so worker processes skip both the compile and the autoloader's file lookup
 * for every preloaded class on every request.
 *
 * Wired up by `opcache.preload` + `opcache.preload_user` in the conf.d fragment
 * the production workflow writes. It is deliberately NOT enabled by default in
 * this repo — see the measurement note at the bottom.
 *
 * ── Safety, because the failure mode is severe ────────────────────────────────
 *
 * A fatal error in a preload script stops php-fpm from starting AT ALL. Not a
 * degraded request, not a 500 — the master refuses to boot. So every compile
 * here is individually guarded and a failure is skipped rather than raised.
 *
 * Two classes of file are excluded rather than guarded, because they can cause
 * damage that a try/catch does not catch:
 *
 *   - Anything that RUNS at include time rather than merely declaring things.
 *     Preload executes the file. `bootstrap/app.php` builds a container,
 *     migrations, routes, and config all evaluate immediately, and helper files
 *     with side effects would execute in a context with no request.
 *
 *   - Classes whose parents are not preloadable. PHP links preloaded classes
 *     eagerly, and a class extending something unavailable is dropped with a
 *     warning — noisy and pointless rather than dangerous, but excluded to keep
 *     startup output clean.
 *
 * ── Why this list ────────────────────────────────────────────────────────────
 *
 * Only code on the hot path for an HTTP request. Preloading the world costs
 * shared memory (which is scarce: production is a 1910MB box) and buys nothing
 * for classes a web request never touches — console commands, migrations,
 * seeders, tests, and the payment gateways for markets this business does not
 * serve.
 */

if (! function_exists('opcache_compile_file') || ! ini_get('opcache.enable')) {
    return;
}

$root = __DIR__;

/**
 * Directories worth preloading, relative to the app root.
 *
 * Deliberately NARROW. The first version of this list added all of Symfony,
 * ramsey, brick and psr alongside Illuminate and exhausted PHP's 128MB
 * memory_limit outright — a fatal, which during php-fpm startup means the
 * master never boots. Preloaded code also lives in opcache shared memory for
 * the process lifetime, and production has 1910MB total for the whole machine.
 *
 * So: only the Illuminate namespaces an HTTP request actually traverses, plus
 * this application's own code. Everything else is left to normal opcache
 * caching, which already avoids recompilation after the first request.
 */
$include = [
    'vendor/laravel/framework/src/Illuminate/Container',
    'vendor/laravel/framework/src/Illuminate/Contracts',
    'vendor/laravel/framework/src/Illuminate/Support',
    'vendor/laravel/framework/src/Illuminate/Collections',
    'vendor/laravel/framework/src/Illuminate/Http',
    'vendor/laravel/framework/src/Illuminate/Routing',
    'vendor/laravel/framework/src/Illuminate/Pipeline',
    'vendor/laravel/framework/src/Illuminate/Database',
    'vendor/laravel/framework/src/Illuminate/Events',
    'vendor/laravel/framework/src/Illuminate/Cache',
    'vendor/laravel/framework/src/Illuminate/Config',
    'vendor/laravel/framework/src/Illuminate/Encryption',
    'app',
    'packages/marvel/src',
];

/**
 * Path fragments that must never be compiled.
 *
 * `/Console/`, `/Migrations/`, `/Seeders/`, `/database/` — never on an HTTP
 * request, and migrations in particular are anonymous classes that execute.
 * `helpers.php`/`functions.php` and Symfony's polyfills declare functions
 * conditionally and can fatal on redeclare when compiled out of order.
 */
$exclude = [
    '/Console/', '/Migrations/', '/Seeders/', '/Factories/', '/database/',
    '/tests/', '/Tests/', '/Testing/',
    'helpers.php', 'functions.php', 'functions_include.php',
    '/polyfill-', '/Resources/stubs/', '/stubs/',
    // Illuminate pieces that are console- or filesystem-bound only.
    'Illuminate/Foundation/Console', 'Illuminate/Database/Console',
    'Illuminate/Queue/Console', 'Illuminate/Routing/Console',
];

$compiled = 0;
$skipped = 0;

foreach ($include as $dir) {
    $path = $root.'/'.$dir;
    if (! is_dir($path)) {
        continue;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $name = str_replace('\\', '/', $file->getPathname());

        foreach ($exclude as $bad) {
            if (str_contains($name, $bad)) {
                $skipped++;
                continue 2;
            }
        }

        try {
            // The @ is load-bearing: a class whose parent is not yet linked
            // emits a warning that would otherwise flood php-fpm's startup log
            // on every restart.
            @opcache_compile_file($file->getPathname());
            $compiled++;
        } catch (Throwable) {
            // Never let one unpreloadable file stop php-fpm from booting.
            $skipped++;
        }
    }
}

// error_log, NOT fwrite(STDERR): the preload context does not define the STDIO
// constants, and referencing STDERR here fatals — which in production means
// php-fpm refuses to start. Found exactly that way on the first run.
if (getenv('PRELOAD_VERBOSE')) {
    error_log("preload: compiled {$compiled}, skipped {$skipped}");
}
