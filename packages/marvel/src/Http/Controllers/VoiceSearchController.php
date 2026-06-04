<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\VoiceSearchSetting;
use Marvel\Database\Models\VoiceSearchLog;
use Marvel\Enums\Permission;

class VoiceSearchController extends CoreController
{
    // OpenAI chat pricing, USD per 1M tokens [prompt, completion].
    private const MODEL_PRICES = [
        'gpt-4o-mini'  => [0.15, 0.60],
        'gpt-4o'       => [2.50, 10.00],
        'gpt-4.1-mini' => [0.40, 1.60],
        'gpt-4.1'      => [2.00, 8.00],
    ];
    private const USD_TO_INR = 83.5;

    private function tokenPrice(?string $model): array
    {
        return self::MODEL_PRICES[$model] ?? self::MODEL_PRICES['gpt-4o-mini'];
    }

    /** Public — the storefront reads this to know if the feature is on. */
    public function getSettings(): JsonResponse
    {
        $setting = VoiceSearchSetting::first();
        if (!$setting) {
            return response()->json(['data' => ['enabled' => false]]);
        }

        // SQL aggregate (no row hydration). INR derived from precise USD sum.
        $row = VoiceSearchLog::where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('COUNT(*) as calls, COALESCE(SUM(cost_usd),0) as usd')
            ->first();
        $usd = (float) ($row->usd ?? 0);

        return response()->json(['data' => [
            'enabled' => (bool) $setting->enabled,
            'monthly_budget_inr' => (float) $setting->monthly_budget_inr,
            'openai_model' => $setting->openai_model,
            'current_month_cost_usd' => round($usd, 6),
            'current_month_cost_inr' => round($usd * self::USD_TO_INR, 4),
            'current_month_calls' => (int) ($row->calls ?? 0),
        ]]);
    }

    /** Admin only — toggle the feature, set budget/model. */
    public function updateSettings(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }
        $setting = VoiceSearchSetting::firstOrFail();
        $setting->fill($request->only(['enabled', 'monthly_budget_inr', 'openai_model']))->save();

        return response()->json(['data' => $setting, 'message' => 'Settings updated.']);
    }

    /** Admin only — daily call/cost breakdown for a month (SQL grouped). */
    public function getStats(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }
        $month = $request->query('month');
        if (!$month || !preg_match('/^\d{4}-\d{2}$/', (string) $month)) {
            $month = now()->format('Y-m');
        }
        $start = \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $rate = self::USD_TO_INR;
        $rows = VoiceSearchLog::whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as cnt, COALESCE(SUM(cost_usd),0) as usd, COALESCE(SUM(total_tokens),0) as toks')
            ->groupBy('d')->orderBy('d')->get();

        $daily = $rows->map(fn ($r) => [
            'date' => (string) $r->d,
            'count' => (int) $r->cnt,
            'cost_usd' => round((float) $r->usd, 6),
            'cost_inr' => round((float) $r->usd * $rate, 4),
        ])->values();

        $totalUsd = (float) $rows->sum('usd');

        return response()->json(['data' => [
            'month' => $month,
            'total_cost_usd' => round($totalUsd, 6),
            'total_cost_inr' => round($totalUsd * $rate, 4),
            'total_calls' => (int) $rows->sum('cnt'),
            'total_tokens' => (int) $rows->sum('toks'),
            'daily_stats' => $daily,
        ]]);
    }

    /** Admin only — recent query log. */
    public function getLogs(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }
        $limit = min((int) $request->query('limit', 50), 500);
        $logs = VoiceSearchLog::latest('created_at')->limit($limit)
            ->get(['id', 'transcript', 'search_text', 'category', 'total_tokens', 'cost_usd', 'cost_inr', 'created_at']);

        return response()->json(['data' => $logs, 'count' => $logs->count()]);
    }

    /**
     * Server-to-server ingest from the storefront's Next.js API route after it
     * calls OpenAI. Prices the tokens using the configured model and records the
     * query. Guarded by a shared secret when VOICE_SEARCH_INGEST_SECRET is set.
     */
    public function storeLog(Request $request): JsonResponse
    {
        // Fail closed outside local/testing: this endpoint records billable usage,
        // so an unauthenticated public POST would let anyone poison cost data. If
        // the shared secret isn't configured on a deployed env, refuse rather than
        // accept anonymous logs. (Search itself is unaffected — only cost logging.)
        $secret = env('VOICE_SEARCH_INGEST_SECRET');
        if (empty($secret)) {
            if (!app()->environment(['local', 'testing'])) {
                return response()->json(['message' => 'Ingest secret not configured'], 503);
            }
        } elseif (!hash_equals($secret, (string) $request->header('X-Ingest-Secret'))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'transcript' => 'required|string',
            'search_text' => 'nullable|string',
            'category' => 'nullable|string',
            'prompt_tokens' => 'nullable|integer',
            'completion_tokens' => 'nullable|integer',
            'session_id' => 'nullable|string',
        ]);

        $prompt = (int) ($data['prompt_tokens'] ?? 0);
        $completion = (int) ($data['completion_tokens'] ?? 0);

        $model = optional(VoiceSearchSetting::first())->openai_model;
        [$pPrice, $cPrice] = $this->tokenPrice($model);
        $costUsd = ($prompt * $pPrice + $completion * $cPrice) / 1000000;

        $log = VoiceSearchLog::create([
            'session_id' => $data['session_id'] ?? null,
            'transcript' => $data['transcript'],
            'search_text' => $data['search_text'] ?? null,
            'category' => $data['category'] ?? null,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
            'cost_usd' => $costUsd,
            'cost_inr' => $costUsd * self::USD_TO_INR,
            'created_at' => now(),
        ]);

        return response()->json(['data' => ['id' => $log->id], 'message' => 'Logged.']);
    }

    /** Returns a 403 JsonResponse unless the caller is a super admin, else null. */
    private function denyUnlessAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        return null;
    }
}
