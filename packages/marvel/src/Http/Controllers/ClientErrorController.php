<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * POST /client-errors — the storefront's error boundaries and window handlers report here.
 *
 * Until now a browser-side crash left no trace on the server: app/error.tsx console.error'd
 * and nothing else, so the checkout "Something went wrong" was diagnosed three times from
 * code-reading alone and never from a stack. Two sinks, deliberately:
 *
 *  - laravel.log, with the FULL payload (stack, URL, the persisted checkout/cart state the
 *    shopper cannot describe from memory) — greppable over SSM the moment it happens.
 *  - one row in request_log_exceptions, so it shows up in the existing Request Log viewer
 *    beside server-side exceptions. That table has no stack column and adding one is not
 *    worth a migration on the money path today; the row is the pointer, the log is the detail.
 *
 * Public, throttled, size-capped, and it must never fail loudly: a reporter that 4xx/5xx's just
 * teaches the client to stop sending.
 */
class ClientErrorController extends CoreController
{
    private const MAX_PER_MINUTE = 300; // global, on top of the per-IP throttle

    public function store(Request $request): JsonResponse
    {
        try {
            $minute = 'clienterr:m' . intdiv(time(), 60);
            if (Cache::increment($minute) > self::MAX_PER_MINUTE) {
                return response()->json(['ok' => true, 'dropped' => true]);
            }
            Cache::put($minute, Cache::get($minute), 120);

            $p = (array) $request->json()->all();
            $payload = [
                'source'         => mb_substr((string) ($p['source'] ?? 'unknown'), 0, 40),
                'message'        => mb_substr((string) ($p['message'] ?? ''), 0, 1000),
                'stack'          => mb_substr((string) ($p['stack'] ?? ''), 0, 6000),
                'digest'         => mb_substr((string) ($p['digest'] ?? ''), 0, 64),
                'url'            => mb_substr((string) ($p['url'] ?? ''), 0, 500),
                'user_agent'     => mb_substr((string) ($p['user_agent'] ?? $request->userAgent() ?? ''), 0, 300),
                'checkout_state' => mb_substr((string) ($p['checkout_state'] ?? ''), 0, 4000),
                'cart_state'     => mb_substr((string) ($p['cart_state'] ?? ''), 0, 4000),
                'ip'             => $request->ip(),
                'user_id'        => optional($request->user())->id,
            ];

            Log::warning('client.error', $payload);

            if (Schema::hasTable('request_log_exceptions')) {
                DB::table('request_log_exceptions')->insert([
                    'request_id' => null,
                    'class'      => mb_substr('ClientError:' . $payload['source'], 0, 191),
                    'message'    => mb_substr($payload['message'], 0, 500),
                    // `file` carries the page URL — the closest thing a browser error has to a file.
                    'file'       => mb_substr($payload['url'], 0, 255),
                    'line'       => 0,
                    'user_id'    => $payload['user_id'],
                    'path'       => '/client-errors',
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // recording an error must never become one
            Log::warning('client.error.record_failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }
}
