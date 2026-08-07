<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        // Slim DB capture so exceptions are visible in the admin observability
        // center, correlated to their request row via the middleware's ULID.
        // Console/queue throwables land too (request_id null) — free queue-failure
        // visibility. Guarded against self-DoS: one row per exception signature
        // per 10s, and a global 120/min cap, so an error storm cannot flood the
        // table or add a write to every failing request. Insert failures are
        // swallowed (table may predate the migration).
        $this->reportable(function (Throwable $e) {
            try {
                $sig = 'exlog:' . substr(md5(get_class($e) . $e->getFile() . $e->getLine()), 0, 16);
                if (!\Illuminate\Support\Facades\Cache::add($sig, 1, 10)) {
                    return;
                }
                $minute = 'exlog:m' . intdiv(time(), 60);
                if (\Illuminate\Support\Facades\Cache::increment($minute) > 120) {
                    return;
                }
                \Illuminate\Support\Facades\Cache::put($minute, \Illuminate\Support\Facades\Cache::get($minute), 120);

                $request = app()->runningInConsole() ? null : request();
                \Illuminate\Support\Facades\DB::table('request_log_exceptions')->insert([
                    'request_id' => $request?->attributes->get('_log_id'),
                    'class' => mb_substr(get_class($e), 0, 191),
                    'message' => mb_substr($e->getMessage(), 0, 500),
                    'file' => mb_substr($e->getFile(), 0, 255),
                    'line' => (int) $e->getLine(),
                    'user_id' => $request?->user()?->id,
                    'path' => $request ? mb_substr('/' . ltrim($request->path(), '/'), 0, 255) : null,
                    'created_at' => now(),
                ]);
            } catch (\Throwable) {
                // capturing an exception must never throw one
            }
        });
    }

    /**
     * Convert an authentication exception into a response.
     *
     * Tokenless requests to API routes get a clean 401 JSON instead of bubbling up as a 500
     * (e.g. when a permission/can: gate runs before auth:sanctum on a guest user).
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return parent::unauthenticated($request, $exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * Lets the framework + any self-rendering domain exception (e.g. MarvelException) render
     * normally FIRST, then — only for api/* requests that would otherwise leak Laravel's HTML
     * error page — converts that error response to JSON. Existing JSON responses, validation
     * errors, and non-api/web routes are left exactly as-is.
     */
    public function render($request, Throwable $e)
    {
        $response = parent::render($request, $e);

        if (
            $request->is('api/*')
            && $response instanceof Response
            && !($response instanceof JsonResponse)
            && $response->getStatusCode() >= 400
        ) {
            $status = $response->getStatusCode();
            $messages = [
                400 => 'Bad request.',
                401 => 'Unauthenticated.',
                403 => 'This action is unauthorized.',
                404 => 'Not found.',
                405 => 'Method not allowed.',
                419 => 'Page expired.',
                429 => 'Too many requests.',
            ];
            $message = $messages[$status]
                ?? (config('app.debug') ? $e->getMessage() : 'Server Error.');

            return response()->json(['message' => $message], $status);
        }

        return $response;
    }
}
