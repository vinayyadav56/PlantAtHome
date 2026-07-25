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
    public function logs(Request $request): JsonResponse
    {
        $q = RequestLog::query()->latest('id');
        if ($m = $request->query('method')) {
            $q->where('method', strtoupper($m));
        }
        if ($request->boolean('errors')) {
            $q->where('status', '>=', 400);
        }
        if ($s = $request->query('status')) {
            $q->where('status', (int) $s);
        }
        if ($term = $request->query('q')) {
            $q->where('path', 'like', '%' . $term . '%');
        }
        return response()->json($q->paginate(min((int) $request->query('limit', 50), 200)));
    }

    public function logSettings(): JsonResponse
    {
        $s = RequestLogSetting::firstOrCreate([], ['enabled' => true, 'retention_days' => 7, 'log_get' => false]);
        return response()->json(['data' => [
            'enabled' => (bool) $s->enabled,
            'retention_days' => (int) $s->retention_days,
            'log_get' => (bool) $s->log_get,
            'total_logs' => RequestLog::count(),
            'errors_24h' => RequestLog::where('status', '>=', 400)->where('created_at', '>=', now()->subDay())->count(),
        ]]);
    }

    public function updateLogSettings(Request $request): JsonResponse
    {
        $s = RequestLogSetting::firstOrFail();
        $s->fill($request->only(['enabled', 'retention_days', 'log_get']))->save();
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
            return response()->json([
                'config' => $config,
                'sent'   => false,
                'mailer' => $mailer,
                'error'  => mb_substr($e->getMessage(), 0, 500),
            ], 500);
        }
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
}
