<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\PlantDoctorSetting;
use Marvel\Database\Models\PlantDoctorLog;
use Marvel\Enums\Permission;

class PlantDoctorController extends CoreController
{
    // Claude (Anthropic) pricing, USD per 1M tokens [input, output]. The Plant Doctor
    // microservice runs on Claude vision; the service returns the exact model + token usage.
    private const MODEL_PRICES = [
        'claude-opus-4-8'   => [5.00, 25.00],
        'claude-opus-4-7'   => [5.00, 25.00],
        'claude-sonnet-4-6' => [3.00, 15.00],
        'claude-haiku-4-5'  => [1.00, 5.00],
    ];
    private const DEFAULT_MODEL = 'claude-opus-4-8';
    private const USD_TO_INR = 83.5;
    // Below this identification confidence we count a diagnosis as "low confidence" for monitoring.
    private const LOW_CONFIDENCE = 0.5;

    private function tokenPrice(?string $model): array
    {
        return self::MODEL_PRICES[$model] ?? self::MODEL_PRICES[self::DEFAULT_MODEL];
    }

    private function setting(): ?PlantDoctorSetting
    {
        return PlantDoctorSetting::first();
    }

    private function monthCostInr(): float
    {
        $usd = (float) PlantDoctorLog::where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_usd');
        return $usd * self::USD_TO_INR;
    }

    /**
     * Customer-facing diagnosis. Proxies the image/symptoms to the Plant Doctor
     * microservice (admin-managed URL + X-Api-Key), records the result + cost,
     * and returns the diagnosis. Gated by the enabled toggle and a monthly budget.
     */
    public function diagnose(Request $request): JsonResponse
    {
        $setting = $this->setting();
        if (!$setting || !$setting->enabled) {
            return response()->json(['message' => 'Plant Doctor is currently unavailable.'], 503);
        }

        $serviceUrl = rtrim($setting->service_url ?: (string) env('PLANT_DOCTOR_SERVICE_URL'), '/');
        $serviceKey = $setting->service_api_key ?: (string) env('PLANT_DOCTOR_SERVICE_API_KEY');
        if (empty($serviceUrl)) {
            return response()->json(['message' => 'Plant Doctor service is not configured.'], 503);
        }

        // Monthly budget guard — cap total spend regardless of traffic.
        if ($setting->monthly_budget_inr > 0 && $this->monthCostInr() >= (float) $setting->monthly_budget_inr) {
            return response()->json(['message' => 'Plant Doctor is busy right now. Please try again later.'], 429);
        }

        $data = $request->validate([
            'image_base64' => 'nullable|string',
            'image_url' => 'nullable|string',
            'symptoms' => 'nullable|string',
            'plant_name' => 'nullable|string',
            'session_id' => 'nullable|string',
            'language' => 'nullable|string|max:12',
        ]);

        if (empty($data['image_base64']) && empty($data['image_url']) && empty($data['symptoms'])) {
            return response()->json(['message' => 'Provide a photo or describe the symptoms.'], 422);
        }

        try {
            $resp = Http::timeout(90)
                ->withHeaders(array_filter(['X-Api-Key' => $serviceKey]))
                ->post($serviceUrl . '/plant-diagnosis', [
                    'image_base64' => $data['image_base64'] ?? null,
                    'image_url' => $data['image_url'] ?? null,
                    'symptoms' => $data['symptoms'] ?? null,
                    'plant_name' => $data['plant_name'] ?? null,
                    'language' => $data['language'] ?? 'en',
                ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not reach the Plant Doctor service.'], 502);
        }

        if (!$resp->successful()) {
            return response()->json(['message' => 'Diagnosis failed. Please try again.'], 502);
        }

        $body = $resp->json();

        // Trust gate result from the service.
        $isPlant = (bool) ($body['is_plant'] ?? true);
        $identification = $body['identification'] ?? [];
        $idConfidence = isset($identification['confidence']) ? (float) $identification['confidence'] : null;
        $identifiedSpecies = $identification['scientific_name'] ?? null;

        // Usage (input/output tokens + model). Null on the short-circuit rejection path = no LLM cost.
        $usage = $body['usage'] ?? [];
        $prompt = (int) ($usage['input_tokens'] ?? 0);
        $completion = (int) ($usage['output_tokens'] ?? 0);
        [$pPrice, $cPrice] = $this->tokenPrice($usage['model'] ?? ($setting->openai_model ?: self::DEFAULT_MODEL));
        $costUsd = ($prompt * $pPrice + $completion * $cPrice) / 1000000;

        $diagnoses = $isPlant ? ($body['diagnosis'] ?? []) : [];
        $conditions = array_filter(array_map(fn ($d) => $d['condition'] ?? null, $diagnoses));
        $severityRank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $topSeverity = null;
        $topRank = 0;
        foreach ($diagnoses as $d) {
            $s = $d['severity'] ?? null;
            if ($s && ($severityRank[$s] ?? 0) > $topRank) {
                $topRank = $severityRank[$s];
                $topSeverity = $s;
            }
        }

        $conditionSummary = $isPlant
            ? ($conditions ? implode(', ', array_slice($conditions, 0, 5)) : null)
            : 'Rejected: ' . \Illuminate\Support\Str::limit((string) ($body['rejection_reason'] ?? 'not a plant'), 120);

        PlantDoctorLog::create([
            'user_id' => optional($request->user())->id,
            'session_id' => $data['session_id'] ?? null,
            'plant_name' => $isPlant ? ($body['plant_name'] ?? ($data['plant_name'] ?? null)) : null,
            'condition_summary' => $conditionSummary,
            'top_severity' => $topSeverity,
            'overall_health_score' => (float) ($body['overall_health_score'] ?? 0),
            'image_url' => $data['image_url'] ?? null,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
            'cost_usd' => $costUsd,
            'cost_inr' => $costUsd * self::USD_TO_INR,
            'is_plant' => $isPlant,
            'identified_species' => $identifiedSpecies,
            'id_confidence' => $idConfidence,
            'created_at' => now(),
        ]);

        return response()->json(['data' => $body]);
    }

    /** Public — the storefront reads this to know if the feature is on. */
    public function getSettings(): JsonResponse
    {
        $setting = $this->setting();
        return response()->json(['data' => [
            'enabled' => $setting ? (bool) $setting->enabled : false,
        ]]);
    }

    /** Admin only — full settings for the management panel. */
    public function getAdminSettings(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }
        $setting = $this->setting();
        $usd = (float) PlantDoctorLog::where('created_at', '>=', now()->startOfMonth())->sum('cost_usd');
        return response()->json(['data' => [
            'enabled' => $setting ? (bool) $setting->enabled : false,
            'service_url' => $setting->service_url ?? null,
            // Never return the stored key; just whether one is set.
            'has_service_api_key' => !empty($setting->service_api_key),
            // Stored in the legacy `openai_model` column but it now holds a Claude model id.
            'openai_model' => $setting->openai_model ?: self::DEFAULT_MODEL,
            'monthly_budget_inr' => (float) ($setting->monthly_budget_inr ?? 0),
            'plant_id_enabled' => $setting ? (bool) $setting->plant_id_enabled : false,
            'current_month_cost_inr' => round($usd * self::USD_TO_INR, 2),
        ]]);
    }

    /** Admin only — update settings. */
    public function updateSettings(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }
        $setting = PlantDoctorSetting::firstOrFail();
        $fields = $request->only([
            'enabled', 'service_url', 'openai_model', 'monthly_budget_inr', 'plant_id_enabled',
        ]);
        // Only overwrite the key when a non-empty value is explicitly provided.
        if ($request->filled('service_api_key')) {
            $fields['service_api_key'] = $request->input('service_api_key');
        }
        $setting->fill($fields)->save();

        return response()->json(['message' => 'Settings updated.']);
    }

    /** Admin only — daily diagnosis count + cost for a month. */
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

        $rows = PlantDoctorLog::whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) as d, COUNT(*) as cnt, COALESCE(SUM(cost_usd),0) as usd, COALESCE(SUM(total_tokens),0) as toks')
            ->groupBy('d')->orderBy('d')->get();

        $daily = $rows->map(fn ($r) => [
            'date' => (string) $r->d,
            'count' => (int) $r->cnt,
            'cost_usd' => round((float) $r->usd, 6),
            'cost_inr' => round((float) $r->usd * $rate, 4),
        ])->values();

        $totalUsd = (float) $rows->sum('usd');
        $totalCalls = (int) $rows->sum('cnt');

        // Trust monitoring: how many uploads were rejected (not a plant / unusable) and how many
        // diagnoses came back below our confidence bar.
        $rejected = (int) PlantDoctorLog::whereBetween('created_at', [$start, $end])
            ->where('is_plant', false)->count();
        $lowConfidence = (int) PlantDoctorLog::whereBetween('created_at', [$start, $end])
            ->where('is_plant', true)->whereNotNull('id_confidence')
            ->where('id_confidence', '<', self::LOW_CONFIDENCE)->count();

        return response()->json(['data' => [
            'month' => $month,
            'total_cost_usd' => round($totalUsd, 6),
            'total_cost_inr' => round($totalUsd * $rate, 4),
            'total_calls' => $totalCalls,
            'total_tokens' => (int) $rows->sum('toks'),
            'rejected_count' => $rejected,
            'rejection_rate' => $totalCalls > 0 ? round($rejected / $totalCalls, 4) : 0,
            'low_confidence_count' => $lowConfidence,
            'daily_stats' => $daily,
        ]]);
    }

    /** Admin only — recent diagnoses. */
    public function getLogs(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }
        $limit = min((int) $request->query('limit', 50), 200);
        $logs = PlantDoctorLog::latest('created_at')->limit($limit)->get([
            'id', 'plant_name', 'condition_summary', 'top_severity',
            'overall_health_score', 'image_url', 'total_tokens',
            'cost_inr', 'created_at',
        ]);

        return response()->json(['data' => $logs, 'count' => $logs->count()]);
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
