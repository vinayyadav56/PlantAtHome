<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Marvel\Database\Models\VoiceSearchSetting;
use Marvel\Database\Models\VoiceSearchLog;
use Marvel\Enums\Permission;

class VoiceSearchController extends CoreController
{
    // gpt-4o-mini pricing (USD per token) and INR conversion.
    private const PRICE_PROMPT_PER_TOKEN = 0.15 / 1000000;
    private const PRICE_COMPLETION_PER_TOKEN = 0.60 / 1000000;
    private const USD_TO_INR = 83.5;

    /** Public — the storefront reads this to know if the feature is on. */
    public function getSettings(): JsonResponse
    {
        $setting = VoiceSearchSetting::first();
        if (!$setting) {
            return response()->json(['data' => ['enabled' => false]]);
        }
        $thisMonth = now()->startOfMonth();
        $logs = VoiceSearchLog::where('created_at', '>=', $thisMonth)->get();

        return response()->json(['data' => [
            'enabled' => (bool) $setting->enabled,
            'monthly_budget_inr' => (float) $setting->monthly_budget_inr,
            'openai_model' => $setting->openai_model,
            'current_month_cost_usd' => round((float) $logs->sum('cost_usd'), 4),
            'current_month_cost_inr' => round((float) $logs->sum('cost_inr'), 2),
            'current_month_calls' => $logs->count(),
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

    /** Admin only — daily call/cost breakdown for a month. */
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

        $logs = VoiceSearchLog::whereBetween('created_at', [$start, $end])->get();
        $daily = $logs->groupBy(fn ($l) => $l->created_at->format('Y-m-d'))->map(fn ($g) => [
            'date' => $g->first()->created_at->format('Y-m-d'),
            'count' => $g->count(),
            'cost_usd' => round((float) $g->sum('cost_usd'), 4),
            'cost_inr' => round((float) $g->sum('cost_inr'), 2),
        ])->values();

        return response()->json(['data' => [
            'month' => $month,
            'total_cost_usd' => round((float) $logs->sum('cost_usd'), 4),
            'total_cost_inr' => round((float) $logs->sum('cost_inr'), 2),
            'total_calls' => $logs->count(),
            'total_tokens' => (int) $logs->sum('total_tokens'),
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
     * calls OpenAI. Computes cost from token counts and records the query.
     * Guarded by a shared secret when VOICE_SEARCH_INGEST_SECRET is configured;
     * open otherwise (staging default) so it works without extra setup.
     */
    public function storeLog(Request $request): JsonResponse
    {
        $secret = env('VOICE_SEARCH_INGEST_SECRET');
        if (!empty($secret) && $request->header('X-Ingest-Secret') !== $secret) {
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
        $costUsd = $prompt * self::PRICE_PROMPT_PER_TOKEN + $completion * self::PRICE_COMPLETION_PER_TOKEN;

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
