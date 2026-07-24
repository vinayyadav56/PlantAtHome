<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
