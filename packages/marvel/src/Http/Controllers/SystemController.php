<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
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
}
