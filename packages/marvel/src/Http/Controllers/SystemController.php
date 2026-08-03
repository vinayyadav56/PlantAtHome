<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\AdminTask;
use Marvel\Database\Models\RequestLog;
use Marvel\Database\Models\RequestLogSetting;

class SystemController extends CoreController
{
    // ------------------------------------------------------- PENDING TASKS
    public function tasks(Request $request): JsonResponse
    {
        $q = AdminTask::query();
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        $rows = $q->orderByRaw("FIELD(status,'pending','done')")
            ->orderByRaw("FIELD(priority,'high','medium','low')")
            ->latest('id')->get();
        return response()->json([
            'data' => $rows,
            'pending_count' => AdminTask::where('status', 'pending')->count(),
            'done_count' => AdminTask::where('status', 'done')->count(),
        ]);
    }

    public function storeTask(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:191',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:64',
            'priority' => 'nullable|string|max:16',
        ]);
        $task = AdminTask::create($data + ['status' => 'pending']);
        return response()->json(['data' => $task], 201);
    }

    public function updateTask(Request $request, $id): JsonResponse
    {
        $task = AdminTask::findOrFail($id);
        $task->fill($request->only(['title', 'description', 'category', 'priority', 'status']));
        if ($request->input('status') === 'done' && !$task->completed_at) {
            $task->completed_at = now();
        }
        if ($request->input('status') === 'pending') {
            $task->completed_at = null;
        }
        $task->save();
        return response()->json(['data' => $task]);
    }

    public function destroyTask($id): JsonResponse
    {
        AdminTask::where('id', $id)->delete();
        return response()->json(['message' => 'Deleted']);
    }

    // -------------------------------------------------------- REQUEST LOGS

    /** Columns the LIST returns — never the mediumText bodies (those are drawer-only). */
    private const LOG_LIST_COLUMNS = [
        'id', 'request_id', 'method', 'path', 'module', 'status',
        'user_id', 'ip', 'device', 'duration_ms', 'created_at',
    ];

    /**
     * Every list query is bounded by a created_at window (default 24h, clamped
     * to retention). That bound is what makes the composite indexes usable and
     * keeps the free-text LIKE an in-window scan instead of a full-table one.
     */
    private function logWindow(Request $request): array
    {
        $retention = max(1, (int) (RequestLogSetting::first()?->retention_days ?? 30));
        $to = $request->query('to') ? \Carbon\Carbon::parse($request->query('to'))->endOfDay() : now();
        $from = $request->query('from')
            ? \Carbon\Carbon::parse($request->query('from'))->startOfDay()
            : now()->subDay();
        $floor = now()->subDays($retention);
        if ($from->lt($floor)) {
            $from = $floor;
        }
        return [$from, $to];
    }

    public function logs(Request $request): JsonResponse
    {
        [$from, $to] = $this->logWindow($request);
        $q = RequestLog::query()
            ->select(self::LOG_LIST_COLUMNS)
            ->whereBetween('created_at', [$from, $to])
            ->latest('id');
        if ($m = $request->query('method')) {
            $q->where('method', strtoupper($m));
        }
        if ($request->boolean('errors')) {
            $q->where('status', '>=', 400);
        }
        if ($s = $request->query('status')) {
            $q->where('status', (int) $s);
        }
        if ($mod = $request->query('module')) {
            $q->where('module', strtolower($mod));
        }
        if ($uid = $request->query('user_id')) {
            $q->where('user_id', (int) $uid);
        }
        if ($ip = $request->query('ip')) {
            $q->where('ip', $ip);
        }
        if ($term = $request->query('q')) {
            $q->where('path', 'like', '%' . $term . '%');
        }
        return response()->json($q->paginate(min((int) $request->query('limit', 50), 100)));
    }

    /** Full row + correlated exceptions + geo — the detail drawer's single fetch. */
    public function logDetail($id): JsonResponse
    {
        $row = RequestLog::findOrFail($id);
        $exceptions = [];
        if ($row->request_id && Schema::hasTable('request_log_exceptions')) {
            $exceptions = DB::table('request_log_exceptions')
                ->where('request_id', $row->request_id)
                ->orderBy('id')
                ->get(['class', 'message', 'file', 'line', 'created_at']);
        }
        // Geo comes from the ip_locations cache the scheduled enricher maintains —
        // null until the ~10-min sweep has seen this IP, and that is fine.
        $location = null;
        if ($row->ip && Schema::hasTable('ip_locations')) {
            $location = DB::table('ip_locations')->where('ip', $row->ip)
                ->first(['country_code', 'country', 'region', 'city', 'isp']);
        }
        return response()->json(['data' => array_merge($row->toArray(), [
            'exceptions' => $exceptions,
            'location' => $location,
        ])]);
    }

    /**
     * CSV export of the CURRENT filter set — same filters as logs(), hard cap
     * 10k rows, never the body columns. Streamed so memory stays flat.
     */
    public function exportLogs(Request $request)
    {
        [$from, $to] = $this->logWindow($request);
        $q = RequestLog::query()
            ->select(self::LOG_LIST_COLUMNS)
            ->whereBetween('created_at', [$from, $to])
            ->latest('id');
        if ($m = $request->query('method')) {
            $q->where('method', strtoupper($m));
        }
        if ($request->boolean('errors')) {
            $q->where('status', '>=', 400);
        }
        if ($s = $request->query('status')) {
            $q->where('status', (int) $s);
        }
        if ($mod = $request->query('module')) {
            $q->where('module', strtolower($mod));
        }
        if ($uid = $request->query('user_id')) {
            $q->where('user_id', (int) $uid);
        }
        if ($ip = $request->query('ip')) {
            $q->where('ip', $ip);
        }
        if ($term = $request->query('q')) {
            $q->where('path', 'like', '%' . $term . '%');
        }

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fputcsv($out, self::LOG_LIST_COLUMNS);
            $q->limit(10000)->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, array_map(
                        fn ($col) => $row->{$col} instanceof \DateTimeInterface
                            ? $row->{$col}->format('Y-m-d H:i:s')
                            : $row->{$col},
                        self::LOG_LIST_COLUMNS
                    ));
                }
            });
            fclose($out);
        }, 'request-logs-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * The dashboard numbers: totals, status classes, a time-bucketed series and
     * the top error/slow paths. Cached 15s so N open admin tabs share one
     * computation; every GROUP BY is bounded by the window and rides the
     * (status, created_at) / created_at indexes.
     */
    public function logsSummary(Request $request): JsonResponse
    {
        $window = in_array($request->query('window'), ['1h', '24h', '7d'], true) ? $request->query('window') : '24h';
        $payload = Cache::remember("request_log_summary:$window", 15, function () use ($window) {
            [$from, $bucketExpr] = match ($window) {
                '1h' => [now()->subHour(), "DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:00')"],
                '7d' => [now()->subDays(7), "DATE_FORMAT(created_at, '%Y-%m-%dT00:00:00')"],
                default => [now()->subDay(), "DATE_FORMAT(created_at, '%Y-%m-%dT%H:00:00')"],
            };
            $base = RequestLog::where('created_at', '>=', $from);

            $totals = (clone $base)->selectRaw(
                'COUNT(*) c, SUM(status >= 400) e, AVG(duration_ms) avg_ms, MAX(duration_ms) max_ms, SUM(duration_ms > 1000) slow'
            )->first();

            $classes = (clone $base)->selectRaw("CONCAT(FLOOR(status/100), 'xx') k, COUNT(*) c")
                ->whereNotNull('status')->groupBy('k')->pluck('c', 'k');

            $buckets = (clone $base)->selectRaw("$bucketExpr t, COUNT(*) c, SUM(status >= 400) e")
                ->groupBy('t')->orderBy('t')->get();

            $topErrors = (clone $base)->where('status', '>=', 400)
                ->selectRaw('path, COUNT(*) c')->groupBy('path')->orderByDesc('c')->limit(8)
                ->get(['path', 'c']);

            $topSlow = (clone $base)->whereNotNull('duration_ms')
                ->selectRaw('path, AVG(duration_ms) avg_ms, COUNT(*) c')
                ->groupBy('path')->orderByDesc('avg_ms')->limit(8)->get();

            $count = (int) ($totals->c ?? 0);
            return [
                'window' => $window,
                'totals' => [
                    'count' => $count,
                    'errors' => (int) ($totals->e ?? 0),
                    'error_rate' => $count > 0 ? round(($totals->e ?? 0) / $count * 100, 2) : 0,
                    'avg_ms' => (int) round($totals->avg_ms ?? 0),
                    'max_ms' => (int) ($totals->max_ms ?? 0),
                    'slow_count' => (int) ($totals->slow ?? 0),
                ],
                'status_classes' => $classes,
                'per_bucket' => $buckets->map(fn ($b) => ['t' => $b->t, 'count' => (int) $b->c, 'errors' => (int) $b->e]),
                'top_error_paths' => $topErrors->map(fn ($r) => ['path' => $r->path, 'count' => (int) $r->c]),
                'top_slow_paths' => $topSlow->map(fn ($r) => ['path' => $r->path, 'avg_ms' => (int) round($r->avg_ms), 'count' => (int) $r->c]),
            ];
        });
        return response()->json(['data' => $payload]);
    }

    /** Captured exceptions (request-correlated AND console/queue ones). */
    public function logExceptions(Request $request): JsonResponse
    {
        if (!Schema::hasTable('request_log_exceptions')) {
            return response()->json(['data' => [], 'total' => 0]);
        }
        [$from, $to] = $this->logWindow($request);
        $q = DB::table('request_log_exceptions')
            ->whereBetween('created_at', [$from, $to])
            ->orderByDesc('id');
        if ($c = $request->query('class')) {
            $q->where('class', 'like', '%' . $c . '%');
        }
        return response()->json($q->paginate(min((int) $request->query('limit', 50), 100)));
    }

    public function logSettings(): JsonResponse
    {
        $s = RequestLogSetting::firstOrCreate([], ['enabled' => true, 'retention_days' => 30, 'log_get' => false]);
        // TABLE_ROWS is an estimate, but the old exact COUNT(*) was an unbounded
        // full count on every settings fetch — the estimate is the honest trade.
        $approx = 0;
        try {
            $row = DB::selectOne(
                'SELECT TABLE_ROWS r FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                ['request_logs']
            );
            $approx = (int) ($row->r ?? 0);
        } catch (\Throwable) {
        }
        return response()->json(['data' => [
            'enabled' => (bool) $s->enabled,
            'retention_days' => (int) $s->retention_days,
            'log_get' => (bool) $s->log_get,
            'store_response_bodies' => (bool) ($s->store_response_bodies ?? false),
            'total_logs' => $approx,
            'errors_24h' => RequestLog::where('status', '>=', 400)->where('created_at', '>=', now()->subDay())->count(),
        ]]);
    }

    public function updateLogSettings(Request $request): JsonResponse
    {
        $s = RequestLogSetting::firstOrFail();
        $s->fill($request->only(['enabled', 'retention_days', 'log_get', 'store_response_bodies']))->save();
        Cache::forget('request_log_settings');
        return response()->json(['data' => $s]);
    }

    public function clearLogs(): JsonResponse
    {
        RequestLog::truncate();
        return response()->json(['message' => 'Logs cleared']);
    }

    // ------------------------------------------------------- SCHEMA OPS
    // Container platforms (Railway) give us no shell; the boot script swallows
    // `migrate` failures. These two endpoints make schema state visible and
    // repairable from the admin (SUPER_ADMIN group).

    /** Mail diagnostics: resolved config + an optional SYNC test send (SUPER_ADMIN). */
    public function mailDiagnostics(Request $request): JsonResponse
    {
        $config = [
            'default_mailer'       => config('mail.default'),
            'sendgrid_key_present' => (bool) config('mail.mailers.sendgrid.key'),
            'from_address'         => config('mail.from.address'),
            'from_name'            => config('mail.from.name'),
            'smtp_host'            => config('mail.mailers.smtp.host'),
        ];

        $to = $request->input('to');
        if (!$to) {
            return response()->json(['config' => $config, 'note' => 'Pass ?to=email&mailer=sendgrid|smtp to run a sync test send.']);
        }

        // A synchronous send surfaces the real transport error (a queued send
        // hides it in a worker). Default to the sendgrid HTTPS transport.
        $mailer = $request->input('mailer')
            ?: (config('mail.mailers.sendgrid.key') ? 'sendgrid' : config('mail.default'));

        try {
            Mail::mailer($mailer)->raw(
                'PlantAtHome mail diagnostics test — sent at ' . now()->toDateTimeString() . " via [{$mailer}].",
                function ($m) use ($to) {
                    $m->to($to)->subject('PlantAtHome mail test');
                }
            );

            return response()->json([
                'config'    => $config,
                'sent'      => true,
                'mailer'    => $mailer,
                'to'        => $to,
                'note'      => 'Transport accepted the message. If it does not arrive, the sender is likely unverified in SendGrid or it landed in spam.',
            ]);
        } catch (\Throwable $e) {
            // Return 200 so the real transport error survives (Marvel's handler
            // rewrites 500 bodies to a generic "Server Error.").
            return response()->json([
                'config'     => $config,
                'sent'       => false,
                'mailer'     => $mailer,
                'error_type' => get_class($e),
                'error'      => mb_substr($e->getMessage(), 0, 800),
            ]);
        }
    }

    /**
     * Ask SendGrid directly (via its v3 API with the configured key) what
     * senders/domains are verified, and attempt a raw send returning
     * SendGrid's real HTTP status + body. This bypasses Laravel's mail
     * transport + Marvel's 500 masking, so the true cause of non-delivery
     * (almost always "from address is not a verified Sender Identity") is
     * visible. Everything returns at 200.
     */
    public function sendgridDiagnostics(Request $request): JsonResponse
    {
        $key  = config('mail.mailers.sendgrid.key');
        $from = config('mail.from.address');
        if (!$key) {
            return response()->json(['error' => 'No SendGrid key configured.']);
        }

        $get = function (string $path) use ($key) {
            try {
                $res = \Illuminate\Support\Facades\Http::withToken($key)
                    ->timeout(15)->get('https://api.sendgrid.com/v3/' . $path);

                return ['status' => $res->status(), 'body' => $res->json() ?? mb_substr($res->body(), 0, 300)];
            } catch (\Throwable $e) {
                return ['error' => mb_substr($e->getMessage(), 0, 200)];
            }
        };

        $out = [
            'configured_from'  => $from,
            'verified_senders' => $get('verified_senders'),
            'domain_auth'      => $get('whitelabel/domains'),
        ];

        // Optional raw send → SendGrid's real accept/reject for the current from.
        if ($to = $request->input('to')) {
            try {
                $res = \Illuminate\Support\Facades\Http::withToken($key)
                    ->timeout(20)
                    ->post('https://api.sendgrid.com/v3/mail/send', [
                        'personalizations' => [['to' => [['email' => $to]]]],
                        'from'    => ['email' => $from, 'name' => config('mail.from.name')],
                        'subject' => 'PlantAtHome SendGrid diagnostic',
                        'content' => [['type' => 'text/plain', 'value' => 'Diagnostic send at ' . now()->toDateTimeString()]],
                    ]);
                $out['raw_send'] = [
                    'http_status' => $res->status(), // 202 = accepted; 403 = unverified sender
                    'body'        => $res->successful() ? '(accepted)' : (mb_substr($res->body(), 0, 500) ?: '(empty)'),
                ];
            } catch (\Throwable $e) {
                $out['raw_send'] = ['error' => mb_substr($e->getMessage(), 0, 300)];
            }
        }

        return response()->json($out);
    }

    /** Read-only: pending migrations + presence of recently-added tables. */
    public function schemaStatus(): JsonResponse
    {
        $ran = collect(DB::table('migrations')->orderByDesc('id')->limit(15)->pluck('migration'));
        $all = collect(glob(base_path('packages/marvel/database/migrations/*.php')))
            ->map(fn ($f) => basename($f, '.php'));
        $ranAll  = collect(DB::table('migrations')->pluck('migration'));
        $pending = $all->reject(fn ($m) => $ranAll->contains($m))->values();

        return response()->json([
            'pending_marvel_migrations' => $pending,
            'last_ran'                  => $ran,
            'tables'                    => [
                'location_capture_requests' => Schema::hasTable('location_capture_requests'),
                'instant_images'            => Schema::hasTable('instant_images'),
                'image_batches'             => Schema::hasTable('image_batches'),
                'users.location_verified'   => Schema::hasColumn('users', 'location_verified'),
                'shops.location_verified'   => Schema::hasColumn('shops', 'location_verified'),
            ],
            // Disk forensics: who is eating the volume (MB, incl. index + free).
            'table_sizes' => DB::select(
                "SELECT table_name AS t,
                        ROUND((data_length + index_length) / 1048576, 1) AS mb,
                        ROUND(data_free / 1048576, 1) AS free_mb,
                        table_rows AS approx_rows
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                 ORDER BY (data_length + index_length) DESC
                 LIMIT 20"
            ),
        ]);
    }

    /**
     * Binlog forensics + purge. On small MySQL volumes (Railway) the binary
     * logs — not the schema — are the classic disk-filler. Purging is safe on
     * this environment (no replication, no point-in-time recovery); it needs
     * a privileged DB user (Railway's default root user qualifies). Surfaces
     * a clear error if the grant is missing so the fallback (growing the
     * volume in the dashboard) is an informed decision.
     */
    /** Server-level disk forensics: tablespace files + log settings + version. */
    public function dbDiagnostics(): JsonResponse
    {
        $safe = function (string $sql) {
            try {
                return collect(DB::select($sql))->map(fn ($r) => (array) $r);
            } catch (\Throwable $e) {
                return ['error' => mb_substr($e->getMessage(), 0, 200)];
            }
        };

        return response()->json([
            'version'   => $safe('SELECT @@version AS v'),
            'files'     => $safe(
                "SELECT file_name, tablespace_name,
                        ROUND((total_extents * extent_size) / 1048576, 1) AS size_mb
                 FROM information_schema.FILES
                 ORDER BY (total_extents * extent_size) DESC
                 LIMIT 15"
            ),
            'log_flags' => $safe(
                'SELECT @@general_log AS general_log, @@slow_query_log AS slow_query_log,
                        @@log_bin AS log_bin, @@innodb_temp_data_file_path AS temp_path'
            ),
        ]);
    }

    public function purgeBinlogs(): JsonResponse
    {
        try {
            $before   = collect(DB::select('SHOW BINARY LOGS'))->map(fn ($r) => (array) $r);
            $beforeMb = round($before->sum(fn ($r) => (float) ($r['File_size'] ?? 0)) / 1048576, 1);

            DB::statement('PURGE BINARY LOGS BEFORE DATE_SUB(NOW(), INTERVAL 1 HOUR)');

            $after   = collect(DB::select('SHOW BINARY LOGS'))->map(fn ($r) => (array) $r);
            $afterMb = round($after->sum(fn ($r) => (float) ($r['File_size'] ?? 0)) / 1048576, 1);

            return response()->json([
                'ok'           => true,
                'before_mb'    => $beforeMb,
                'after_mb'     => $afterMb,
                'files_before' => $before->count(),
                'files_after'  => $after->count(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 400)], 500);
        }
    }

    /**
     * Emergency space reclaim on shell-less platforms. DROP frees disk without
     * needing free space (unlike TRUNCATE/DELETE, which deadlock on a full
     * volume). Only expendable operational tables are allowed, and each is
     * recreated empty immediately via its migration.
     */
    public function pruneTable(Request $request): JsonResponse
    {
        $allowed = [
            'request_logs' => 'packages/marvel/database/migrations',
            'failed_jobs'  => 'database/migrations',
        ];
        $table = (string) $request->input('table');
        if (!isset($allowed[$table])) {
            return response()->json(['ok' => false, 'error' => 'Table not in the prune allowlist.'], 422);
        }
        if (!Schema::hasTable($table)) {
            return response()->json(['ok' => false, 'error' => 'Table does not exist.'], 422);
        }

        Schema::drop($table);

        // Recreate empty from its own migration file.
        $file = collect(glob(base_path($allowed[$table] . '/*.php')))
            ->first(fn ($f) => str_contains(basename($f), 'create_' . $table . '_table'));
        if ($file) {
            (require $file)->up();
        }

        return response()->json([
            'ok'        => true,
            'table'     => $table,
            'recreated' => Schema::hasTable($table),
        ]);
    }

    /** Run pending migrations NOW and return the real output (boot swallows it). */
    public function runMigrations(): JsonResponse
    {
        try {
            Artisan::call('migrate', ['--force' => true]);

            return response()->json(['ok' => true, 'output' => Artisan::output()]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'     => false,
                'error'  => $e->getMessage(),
                'output' => Artisan::output(),
            ], 500);
        }
    }

    /**
     * Run one allowlisted maintenance command in-request and return what it
     * actually did — output, exit code, and the VERBATIM exception if it threw.
     *
     * Why this exists: the scheduler swallows per-event exceptions (by design —
     * one bad command must not stop the others), so a scheduled sweep can fail
     * every minute for hours while `schedule:run` looks perfectly healthy. On a
     * host with no shell (Railway) there was previously no way to see that
     * exception at all. Strictly allowlisted to idempotent, safe-to-rerun
     * maintenance commands — never a free-form command runner.
     */
    public const RUNNABLE_COMMANDS = [
        // Clears orphaned scheduler overlap mutexes. `withoutOverlapping()`
        // registers a SKIP FILTER, so a lock left behind by a killed process
        // makes schedule:run drop the event with no exception and no log line —
        // the command simply never runs. Shortening the TTL in code cannot
        // release a lock that already exists, and on a database cache store the
        // row survives redeploys, so this is the only way to clear it without a
        // shell. Safe: the scheduler recreates locks on the next tick.
        'schedule:clear-cache'      => \Illuminate\Console\Scheduling\ScheduleClearCacheCommand::class,
        'images:sweep-batches'      => \Marvel\Console\SweepImageBatchesCommand::class,
        'content:sweep-batches'     => \Marvel\Console\SweepContentBatchesCommand::class,
        'inventory:release-expired' => \App\Modules\Inventory\Console\ReleaseExpiredReservationsCommand::class,
        'outbox:relay'              => \App\Console\Commands\OutboxRelayCommand::class,
        'marketing:dispatch-due'    => \App\Modules\Marketing\Console\DispatchDueCampaignsCommand::class,
    ];

    public function runCommand(Request $request): JsonResponse
    {
        $command = (string) $request->input('command', '');
        if (!array_key_exists($command, self::RUNNABLE_COMMANDS)) {
            return response()->json([
                'ok'      => false,
                'error'   => 'Command is not on the allowlist.',
                'allowed' => array_keys(self::RUNNABLE_COMMANDS),
            ], 422);
        }

        $started = microtime(true);
        try {
            // Register the concrete command first. Marvel registers its commands
            // from bootForConsole(), and the module providers do the same, so in
            // an HTTP request NONE of them exist — calling by name would report
            // "command does not exist" and look exactly like the real bug this
            // endpoint is meant to diagnose. Register explicitly, don't infer.
            app(\Illuminate\Contracts\Console\Kernel::class)
                ->registerCommand(app(self::RUNNABLE_COMMANDS[$command]));

            $exit   = Artisan::call($command, $command === 'outbox:relay' ? ['--once' => true] : []);
            $output = Artisan::output();

            return response()->json([
                'ok'          => $exit === 0,
                'command'     => $command,
                'exit_code'   => $exit,
                'output'      => $output,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (\Throwable $e) {
            // The whole point: surface the exception the scheduler would eat.
            return response()->json([
                'ok'          => false,
                'command'     => $command,
                'error'       => $e->getMessage(),
                'exception'   => get_class($e),
                'at'          => $e->getFile() . ':' . $e->getLine(),
                'trace'       => collect($e->getTrace())->take(12)->map(fn ($f) => trim(
                    ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? '')
                ))->values(),
                'output'      => Artisan::output(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ], 500);
        }
    }
}
