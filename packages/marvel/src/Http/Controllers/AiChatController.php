<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Marvel\Database\Models\AiChatSetting;
use Marvel\Database\Models\AiChatConversation;
use Marvel\Enums\Permission;

/**
 * "Ask AI" per-plant chat.
 *
 * The chat hot path does NOT pass through here — the storefront talks to the
 * async FastAPI microservice directly (so PHP-FPM never blocks on an LLM call).
 * Laravel only: (1) serves the public feature flag, (2) receives the one
 * transcript persist per conversation from the service, and (3) serves admin
 * settings/stats/transcripts. Mirrors PlantDoctorController.
 */
class AiChatController extends CoreController
{
    // OpenAI pricing, USD per 1M tokens [prompt, completion].
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

    private function setting(): ?AiChatSetting
    {
        return AiChatSetting::first();
    }

    private function monthCostInr(): float
    {
        return (float) AiChatConversation::where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_inr');
    }

    /** Public — storefront reads this to render the "Ask AI" tag + cap. */
    public function getSettings(): JsonResponse
    {
        $setting = $this->setting();
        return response()->json(['data' => [
            'enabled' => $setting ? (bool) $setting->enabled : false,
            'max_prompts' => (int) ($setting->max_prompts ?? 10),
        ]]);
    }

    /**
     * Internal — the microservice posts the full transcript once per chat
     * (on /end or its idle-sweep), authenticated by a shared X-Api-Key.
     * Idempotent: upserts on conversation_id so a double-flush is harmless.
     */
    public function persist(Request $request): JsonResponse
    {
        $setting = $this->setting();
        $expected = ($setting && $setting->service_api_key)
            ? $setting->service_api_key
            : (string) env('AI_CHAT_SERVICE_API_KEY');
        if (empty($expected) || $request->header('X-Api-Key') !== $expected) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $data = $request->validate([
            'conversation_id' => 'required|string',
            'user_id' => 'nullable|integer',
            'plant_id' => 'nullable|integer',
            'plant_name' => 'nullable|string',
            'language' => 'nullable|string',
            'prompt_count' => 'nullable|integer',
            'transcript' => 'nullable|array',
            'prompt_tokens' => 'nullable|integer',
            'completion_tokens' => 'nullable|integer',
            'started_at' => 'nullable|integer',
            'ended_at' => 'nullable|integer',
        ]);

        $prompt = (int) ($data['prompt_tokens'] ?? 0);
        $completion = (int) ($data['completion_tokens'] ?? 0);
        [$pPrice, $cPrice] = $this->tokenPrice($setting->openai_model ?? 'gpt-4o-mini');
        $costUsd = ($prompt * $pPrice + $completion * $cPrice) / 1000000;

        AiChatConversation::updateOrCreate(
            ['conversation_id' => $data['conversation_id']],
            [
                'user_id' => $data['user_id'] ?? null,
                'plant_id' => $data['plant_id'] ?? null,
                'plant_name' => $data['plant_name'] ?? null,
                'language' => $data['language'] ?? null,
                'prompt_count' => (int) ($data['prompt_count'] ?? 0),
                'transcript' => $data['transcript'] ?? [],
                'prompt_tokens' => $prompt,
                'completion_tokens' => $completion,
                'total_tokens' => $prompt + $completion,
                'cost_usd' => $costUsd,
                'cost_inr' => $costUsd * self::USD_TO_INR,
                'started_at' => !empty($data['started_at']) ? now()->setTimestamp($data['started_at']) : now(),
                'ended_at' => !empty($data['ended_at']) ? now()->setTimestamp($data['ended_at']) : now(),
                'created_at' => now(),
            ]
        );

        return response()->json(['message' => 'saved']);
    }

    /** Admin — full settings for the management panel. */
    public function getAdminSettings(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }
        $setting = $this->setting();
        return response()->json(['data' => [
            'enabled' => $setting ? (bool) $setting->enabled : false,
            'service_url' => $setting->service_url ?? null,
            'has_service_api_key' => !empty($setting->service_api_key),
            'openai_model' => $setting->openai_model ?? 'gpt-4o-mini',
            'monthly_budget_inr' => (float) ($setting->monthly_budget_inr ?? 0),
            'max_prompts' => (int) ($setting->max_prompts ?? 10),
            'daily_user_cap' => (int) ($setting->daily_user_cap ?? 60),
            'current_month_cost_inr' => round($this->monthCostInr(), 2),
        ]]);
    }

    /** Admin — update settings. */
    public function updateSettings(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }
        $setting = AiChatSetting::firstOrFail();
        $fields = $request->only([
            'enabled', 'service_url', 'openai_model',
            'monthly_budget_inr', 'max_prompts', 'daily_user_cap',
        ]);
        if ($request->filled('service_api_key')) {
            $fields['service_api_key'] = $request->input('service_api_key');
        }
        $setting->fill($fields)->save();

        return response()->json(['message' => 'Settings updated.']);
    }

    /** Admin — monthly rollup: conversations, tokens, cost (daily breakdown). */
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

        $rows = AiChatConversation::whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as cnt, COALESCE(SUM(cost_inr),0) as inr, COALESCE(SUM(total_tokens),0) as toks, COALESCE(SUM(prompt_count),0) as prompts')
            ->groupBy('d')->orderBy('d')->get();

        $daily = $rows->map(fn ($r) => [
            'date' => (string) $r->d,
            'count' => (int) $r->cnt,
            'prompts' => (int) $r->prompts,
            'cost_inr' => round((float) $r->inr, 4),
        ])->values();

        return response()->json(['data' => [
            'month' => $month,
            'total_conversations' => (int) $rows->sum('cnt'),
            'total_prompts' => (int) $rows->sum('prompts'),
            'total_tokens' => (int) $rows->sum('toks'),
            'total_cost_inr' => round((float) $rows->sum('inr'), 4),
            'daily_stats' => $daily,
        ]]);
    }

    /** Admin — paginated conversations, filterable by user_id + date. */
    public function getConversations(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }
        $limit = min((int) $request->query('limit', 20), 100);
        $query = AiChatConversation::with('customer:id,name,email')
            ->latest('created_at');

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->query('user_id'));
        }
        if ($request->filled('plant_id')) {
            $query->where('plant_id', (int) $request->query('plant_id'));
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->query('to') . ' 23:59:59');
        }

        $page = $query->paginate($limit)->withQueryString();
        // Don't ship full transcripts in the list view — only in the detail call.
        $page->getCollection()->transform(function ($c) {
            $c->makeHidden('transcript');
            return $c;
        });

        return response()->json($page);
    }

    /** Admin — a single conversation with its full transcript. */
    public function getConversation(Request $request, $id): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }
        $conv = AiChatConversation::with('customer:id,name,email')
            ->where('id', $id)
            ->orWhere('conversation_id', $id)
            ->first();
        if (!$conv) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json(['data' => $conv]);
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
