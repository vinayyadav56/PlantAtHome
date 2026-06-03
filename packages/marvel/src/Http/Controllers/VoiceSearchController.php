<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\VoiceSearchSetting;
use Marvel\Database\Models\VoiceSearchLog;

class VoiceSearchController extends CoreController
{
    public function getSettings(): JsonResponse
    {
        try {
            $setting = VoiceSearchSetting::firstOrFail();
            $thisMonth = now()->startOfMonth();
            $logs = VoiceSearchLog::where('created_at', '>=', $thisMonth)->get();
            $costUsd = $logs->sum('cost_usd');
            $costInr = $logs->sum('cost_inr');

            return $this->respondSuccess([
                'enabled' => $setting->enabled,
                'monthly_budget_inr' => (float) $setting->monthly_budget_inr,
                'openai_model' => $setting->openai_model,
                'current_month_cost_usd' => round($costUsd, 4),
                'current_month_cost_inr' => round($costInr, 2),
                'current_month_calls' => $logs->count(),
            ]);
        } catch (\Throwable $e) {
            return $this->respondError('Failed to fetch settings: ' . $e->getMessage(), [], 500);
        }
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorize('update', VoiceSearchSetting::class);

        try {
            $setting = VoiceSearchSetting::firstOrFail();
            $setting->update($request->only(['enabled', 'monthly_budget_inr', 'openai_model']));

            return $this->respondSuccess(['message' => 'Settings updated.', 'setting' => $setting]);
        } catch (\Throwable $e) {
            return $this->respondError('Failed to update settings: ' . $e->getMessage(), [], 500);
        }
    }

    public function getStats(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VoiceSearchLog::class);

        try {
            $month = $request->query('month');
            if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) {
                $month = now()->format('Y-m');
            }
            $start = \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            $logs = VoiceSearchLog::whereBetween('created_at', [$start, $end])->get();
            $dailyStats = $logs->groupBy(fn ($l) => $l->created_at->format('Y-m-d'))->map(fn ($group) => [
                'date' => $group->first()->created_at->format('Y-m-d'),
                'count' => $group->count(),
                'cost_usd' => round($group->sum('cost_usd'), 4),
                'cost_inr' => round($group->sum('cost_inr'), 2),
            ])->values();

            return $this->respondSuccess([
                'month' => $month,
                'total_cost_usd' => round($logs->sum('cost_usd'), 4),
                'total_cost_inr' => round($logs->sum('cost_inr'), 2),
                'total_calls' => $logs->count(),
                'total_tokens' => $logs->sum('total_tokens'),
                'daily_stats' => $dailyStats,
            ]);
        } catch (\Throwable $e) {
            return $this->respondError('Failed to fetch stats: ' . $e->getMessage(), [], 500);
        }
    }

    public function getLogs(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VoiceSearchLog::class);

        try {
            $limit = min($request->query('limit', 50), 500);
            $logs = VoiceSearchLog::latest('created_at')->limit($limit)->get(['id', 'transcript', 'search_text', 'category', 'total_tokens', 'cost_usd', 'cost_inr', 'created_at']);

            return $this->respondSuccess([
                'data' => $logs,
                'count' => $logs->count(),
            ]);
        } catch (\Throwable $e) {
            return $this->respondError('Failed to fetch logs: ' . $e->getMessage(), [], 500);
        }
    }

    public static function logQuery(string $transcript, ?string $searchText, ?string $category, int $promptTokens, int $completionTokens): void
    {
        try {
            $totalTokens = $promptTokens + $completionTokens;
            // gpt-4o-mini pricing: $0.15/1M prompt tokens, $0.60/1M completion tokens
            $costUsd = ($promptTokens / 1000000) * 0.15 + ($completionTokens / 1000000) * 0.60;
            $exchangeRate = 83.5; // INR per USD
            $costInr = $costUsd * $exchangeRate;

            VoiceSearchLog::create([
                'session_id' => request()->id(),
                'transcript' => $transcript,
                'search_text' => $searchText,
                'category' => $category,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'cost_usd' => $costUsd,
                'cost_inr' => $costInr,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Failed to log voice search: ' . $e->getMessage());
        }
    }
}
