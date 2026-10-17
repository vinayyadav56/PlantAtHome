<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Marvel\Database\Models\PlantDoctorSetting;
use Marvel\Database\Models\PlantDoctorLog;
use Marvel\Database\Models\PlantDoctorConsultation;
use Marvel\Enums\Permission;

class PlantDoctorController extends CoreController
{
    // Server-side history: rows kept per user (oldest pruned) and the max
    // accepted size for the data-URI thumbnail (client sends ~<50KB).
    private const CONSULTATIONS_PER_USER = 50;
    private const THUMB_MAX_BYTES = 80_000;

    // What the staged service can say about a photo. Anything else is treated as `uncertain`,
    // which shows the customer no diagnosis — the fail-closed default.
    public const OUTCOMES = ['plant_detected', 'non_plant', 'uncertain', 'poor_quality', 'mixed_content'];
    // Service 4xx codes whose body (code + customer copy in the request language) is passed through.
    private const PASSTHROUGH_CODES = [
        'image_required', 'invalid_image', 'image_unreadable', 'unsupported_format', 'image_too_large', 'image_too_small',
    ];
    private const UNCERTAIN_COPY = "I can't tell for sure whether this is a plant I can assess. Please send a clear, close-up photo of the plant.";
    private const IMAGE_REQUIRED_COPY = 'Please add a photo — Dr. Planty diagnoses from what it can see.';
    private const INVALID_IMAGE_COPY = "That photo couldn't be used. Please upload a clear JPEG or PNG under 6 MB.";

    private function cfg(string $key, $default = null)
    {
        return config("services.plant_doctor.$key", $default);
    }

    /** USD per 1M tokens [input, output] for a model, or the fallback (logged) when it is unpriced. */
    private function tokenPrice(?string $model): array
    {
        $prices = (array) $this->cfg('prices', []);
        // OpenAI reports the dated snapshot it ran (gpt-5.4-mini-2026-03-17); the table is keyed by the base id.
        $base = $model !== null ? preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $model) : null;
        if ($base !== null && $base !== '' && isset($prices[$base])) {
            return $prices[$base];
        }
        if ($model !== null && $model !== '') {
            Log::warning('plant-doctor: unpriced model, billed at the fallback price', ['model' => $model]);
        }
        return (array) $this->cfg('fallback_price', [5.00, 20.00]);
    }

    private function usdToInr(): float
    {
        return (float) $this->cfg('usd_to_inr', 83.5);
    }

    private function modelOptions(): array
    {
        return array_values(array_map('strval', (array) $this->cfg('models', [])));
    }

    /** The stored diagnosis model if the admin's choice is one we price and allow; null otherwise. */
    private function allowedModel(?string $model): ?string
    {
        return $model !== null && in_array($model, $this->modelOptions(), true) ? $model : null;
    }

    private function setting(): ?PlantDoctorSetting
    {
        return PlantDoctorSetting::first();
    }

    /**
     * Hosts we are willing to have the Plant Doctor service fetch an image from: our own
     * media/CDN origins. Anything else is refused at this boundary rather than trusted to
     * the microservice, which has historically fetched whatever it was handed.
     */
    private function isAllowedImageUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $allowed = collect([
            (string) parse_url((string) config('app.url'), PHP_URL_HOST),
            (string) parse_url((string) config('filesystems.disks.s3.url'), PHP_URL_HOST),
        ])->filter()->map(fn ($h) => strtolower($h));

        try {
            $allowed->push(strtolower((string) parse_url(\Illuminate\Support\Facades\Storage::disk('s3')->url('probe'), PHP_URL_HOST)));
        } catch (\Throwable) {
            // No S3 disk configured — the app-url host still applies.
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && $allowed->filter()->contains(strtolower((string) ($parts['host'] ?? '')))
            && !isset($parts['port'])                       // a non-standard port is not our media host
            && !isset($parts['user']) && !isset($parts['pass']); // user:pass@ hides the real host
    }

    /**
     * A cheap, dependency-free check that the upload is a real image within our limits, so a
     * corrupt file, a 30 MB PNG or a 10×10 icon never costs a round trip to the service. The
     * service decodes for real afterwards; this only has to be right about what it refuses.
     * Returns a short reason for the log, or null when the image may be forwarded.
     */
    private function imageProblem(string $base64): ?string
    {
        $s = trim($base64);
        if (str_starts_with($s, 'data:')) {
            $comma = strpos($s, ',');
            $s = $comma === false ? '' : substr($s, $comma + 1);
        }
        $bin = base64_decode(preg_replace('/\s+/', '', $s) ?? '', true);
        if ($bin === false || strlen($bin) < 64) {
            return 'not base64';
        }
        if (strlen($bin) > (int) $this->cfg('max_image_bytes', 6 * 1024 * 1024)) {
            return strlen($bin) . ' bytes';
        }
        $info = @getimagesizefromstring($bin);
        if ($info === false) {
            return 'not an image';
        }
        [$w, $h] = $info;
        if (!in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return (string) ($info['mime'] ?? 'unknown mime');
        }
        if (min($w, $h) < (int) $this->cfg('min_edge', 160) || max($w, $h) > (int) $this->cfg('max_edge', 12000)) {
            return "{$w}x{$h}";
        }
        return null;
    }

    /**
     * Fail closed on whatever the service said. A body without a recognised `outcome` (an older
     * service, a half-written reply) is shown as `uncertain`; for every outcome other than
     * plant_detected the legacy diagnosis fields are blanked, so no client — new or old — can
     * render advice for a photo the gate did not pass.
     */
    public static function sanitise(array $body): array
    {
        $outcome = $body['outcome'] ?? null;
        if (!is_string($outcome) || !in_array($outcome, self::OUTCOMES, true)) {
            $outcome = 'uncertain';
            $body['outcome'] = 'uncertain';
            $body['rejection_reason'] = self::UNCERTAIN_COPY;
        }
        $guidance = $body['guidance'] ?? null;
        $body['guidance'] = is_array($guidance)
            ? ['ask_for' => array_values((array) ($guidance['ask_for'] ?? [])), 'message' => (string) ($guidance['message'] ?? '')]
            : ['ask_for' => [], 'message' => ''];

        if ($outcome !== 'plant_detected') {
            $body['is_plant'] = false;
            $body['diagnosis'] = [];
            $body['identification'] = ['common_name' => '', 'scientific_name' => '', 'confidence' => 0.0];
            $body['plant_name'] = '';
            $body['overall_health_score'] = 0.0;
            $body['immediate_action'] = '';
            $body['long_term_care'] = '';
            if (empty($body['rejection_reason']) || !is_string($body['rejection_reason'])) {
                $body['rejection_reason'] = self::UNCERTAIN_COPY;
            }
            return $body;
        }

        $body['is_plant'] = true;
        $body['rejection_reason'] = '';
        $body['diagnosis'] = is_array($body['diagnosis'] ?? null) ? array_values($body['diagnosis']) : [];
        $ident = is_array($body['identification'] ?? null) ? $body['identification'] : [];
        $body['identification'] = [
            'common_name' => (string) ($ident['common_name'] ?? ''),
            'scientific_name' => (string) ($ident['scientific_name'] ?? ''),
            'confidence' => max(0.0, min(1.0, (float) ($ident['confidence'] ?? 0))),
        ];
        $score = (float) ($body['overall_health_score'] ?? 0);
        if ($score > 1) {
            $score = $score <= 100 ? $score / 100 : 1.0;   // a 0–100 reply is a percentage
        }
        $body['overall_health_score'] = max(0.0, min(1.0, $score));
        $body['plant_name'] = (string) ($body['plant_name'] ?? '');
        $body['immediate_action'] = (string) ($body['immediate_action'] ?? '');
        $body['long_term_care'] = (string) ($body['long_term_care'] ?? '');
        return $body;
    }

    private function monthCostInr(): float
    {
        $usd = (float) PlantDoctorLog::where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_usd');
        return $usd * $this->usdToInr();
    }

    /** Cost of one diagnosis from the service's per-stage usage (or its totals when stages are absent). */
    private function costUsd(array $usage, ?string $defaultModel): array
    {
        $stages = is_array($usage['stages'] ?? null) ? $usage['stages'] : [];
        if (!$stages) {
            $stages = [[
                'model' => $usage['model'] ?? $defaultModel,
                'input_tokens' => $usage['input_tokens'] ?? 0,
                'output_tokens' => $usage['output_tokens'] ?? 0,
            ]];
        }
        $prompt = $completion = 0;
        $usd = 0.0;
        foreach ($stages as $stage) {
            $in = (int) ($stage['input_tokens'] ?? 0);
            $out = (int) ($stage['output_tokens'] ?? 0);
            [$pPrice, $cPrice] = $this->tokenPrice((string) ($stage['model'] ?? $defaultModel ?? ''));
            $usd += ($in * $pPrice + $out * $cPrice) / 1_000_000;
            $prompt += $in;
            $completion += $out;
        }
        return [$prompt, $completion, $usd];
    }

    /**
     * Customer-facing diagnosis. Validates the photo cheaply, proxies it to the Plant Doctor
     * microservice (admin-managed URL + X-Api-Key), fails closed on the reply, records the
     * outcome + cost, and returns the staged result. Gated by the enabled toggle and a monthly budget.
     */
    public function diagnose(Request $request): JsonResponse
    {
        $setting = $this->setting();
        if (!$setting || !$setting->enabled) {
            return response()->json(['message' => 'Plant Doctor is currently unavailable.', 'code' => 'disabled'], 503);
        }

        // Resolved through the Integration module (integration_providers → this feature's table →
        // env), so a rotated key is an admin action rather than a redeploy.
        $integrations = app(\Marvel\Integrations\IntegrationService::class);
        $serviceUrl = rtrim((string) $integrations->config('plant_doctor', 'service_url', config('services.plant_doctor.service_url')), '/');
        $serviceKey = $integrations->secret('plant_doctor', 'service_api_key', (string) config('services.plant_doctor.service_api_key'));
        if (empty($serviceUrl)) {
            return response()->json(['message' => 'Plant Doctor service is not configured.', 'code' => 'not_configured'], 503);
        }

        // Monthly budget guard — cap total spend regardless of traffic. Its own code, so the
        // storefront can tell it from the per-IP throttle's 429 and not offer a pointless retry.
        if ($setting->monthly_budget_inr > 0 && $this->monthCostInr() >= (float) $setting->monthly_budget_inr) {
            return response()->json([
                'message' => "Plant Doctor has reached this month's limit. Please try again next month.",
                'code' => 'budget_exhausted',
            ], 429);
        }

        $data = $request->validate([
            'image_base64' => ['nullable', 'string', 'max:' . (int) $this->cfg('max_image_base64_chars', 8 * 1024 * 1024)],
            'image_url' => 'nullable|string|max:2048',
            'symptoms' => 'nullable|string|max:2000',
            'plant_name' => 'nullable|string|max:120',
            'session_id' => 'nullable|string|max:64',
            'language' => 'nullable|string|max:12',
        ]);

        // A diagnosis needs a photo: the gate, the identification and the findings all come from
        // what the service can see. Words alone are never diagnosed (owner decision, 2026-10-17).
        if (empty($data['image_base64']) && empty($data['image_url'])) {
            return response()->json(['message' => self::IMAGE_REQUIRED_COPY, 'code' => 'image_required'], 422);
        }

        // SSRF boundary. image_url is customer-supplied and we hand it to a service that
        // fetches it server-side, so an unchecked value here is a request for the microservice
        // to read whatever the caller names — starting with the cloud metadata endpoint.
        // Allowlist, not a blocklist: blocking 169.254.169.254 only invites DNS rebinding and
        // decimal-encoded IPs. Same rule as LoraModelController::isAllowedMediaUrl.
        if (!empty($data['image_url']) && !$this->isAllowedImageUrl($data['image_url'])) {
            return response()->json([
                'message' => 'That image link is not supported. Upload the photo instead.',
                'code' => 'invalid_image',
            ], 422);
        }

        if (!empty($data['image_base64']) && ($problem = $this->imageProblem($data['image_base64'])) !== null) {
            Log::info('plant-doctor: photo refused before the service', ['reason' => $problem]);
            return response()->json(['message' => self::INVALID_IMAGE_COPY, 'code' => 'invalid_image'], 422);
        }

        $requestId = (string) ($request->attributes->get('_log_id') ?: Str::lower(Str::random(16)));
        $diagnosisModel = $this->allowedModel($setting->openai_model);
        $started = microtime(true);

        try {
            $resp = Http::timeout((int) $this->cfg('timeout', 90))
                ->withHeaders(array_filter(['X-Api-Key' => $serviceKey, 'X-Request-Id' => $requestId]))
                ->post($serviceUrl . '/plant-diagnosis', [
                    'image_base64' => $data['image_base64'] ?? null,
                    'image_url' => $data['image_url'] ?? null,
                    'symptoms' => $data['symptoms'] ?? null,
                    'plant_name' => $data['plant_name'] ?? null,
                    'language' => $data['language'] ?? 'en',
                    'session_id' => $data['session_id'] ?? null,
                    'request_id' => $requestId,
                    'diagnosis_model' => $diagnosisModel,
                    'use_plant_id' => (bool) $setting->plant_id_enabled,
                ]);
        } catch (\Throwable $e) {
            Log::warning('plant-doctor: service unreachable', ['request_id' => $requestId, 'error' => get_class($e)]);
            return response()->json(['message' => 'Could not reach the Plant Doctor service.', 'code' => 'upstream'], 502);
        }

        if (!$resp->successful()) {
            $code = (string) $resp->json('code', '');
            if ($resp->status() >= 400 && $resp->status() < 500 && in_array($code, self::PASSTHROUGH_CODES, true)) {
                return response()->json([
                    'message' => (string) ($resp->json('message') ?: self::INVALID_IMAGE_COPY),
                    'code' => $code,
                ], $resp->status());
            }
            Log::warning('plant-doctor: service error', ['request_id' => $requestId, 'status' => $resp->status(), 'code' => $code]);
            return response()->json(['message' => 'Diagnosis failed. Please try again.', 'code' => 'upstream'], 502);
        }

        $body = self::sanitise((array) $resp->json());
        $outcome = $body['outcome'];
        $isPlant = $outcome === 'plant_detected';
        $identification = $body['identification'];
        $stageIdent = is_array($body['stage_results']['identification'] ?? null) ? $body['stage_results']['identification'] : [];
        $confidenceLevel = $isPlant && in_array($stageIdent['confidence'] ?? null, ['high', 'medium', 'low'], true)
            ? $stageIdent['confidence'] : null;
        $gateRule = (string) ($body['stage_results']['gate']['rule'] ?? '');

        [$prompt, $completion, $costUsd] = $this->costUsd((array) ($body['usage'] ?? []), $diagnosisModel);

        $diagnoses = $isPlant ? $body['diagnosis'] : [];
        $conditions = array_filter(array_map(fn ($d) => is_array($d) ? ($d['condition'] ?? null) : null, $diagnoses));
        $severityRank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $topSeverity = null;
        $topRank = 0;
        foreach ($diagnoses as $d) {
            $s = is_array($d) ? ($d['severity'] ?? null) : null;
            if ($s && ($severityRank[$s] ?? 0) > $topRank) {
                $topRank = $severityRank[$s];
                $topSeverity = $s;
            }
        }

        $conditionSummary = $isPlant
            ? ($conditions ? implode(', ', array_slice($conditions, 0, 5)) : null)
            : 'Rejected: ' . Str::limit((string) $body['rejection_reason'], 120);

        PlantDoctorLog::create([
            'user_id' => $request->user('sanctum')?->id,
            'session_id' => $data['session_id'] ?? null,
            'plant_name' => $isPlant ? ($body['plant_name'] ?: ($data['plant_name'] ?? null)) : null,
            'condition_summary' => $conditionSummary,
            'top_severity' => $topSeverity,
            'overall_health_score' => (float) $body['overall_health_score'],
            'image_url' => $data['image_url'] ?? null,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
            'cost_usd' => $costUsd,
            'cost_inr' => $costUsd * $this->usdToInr(),
            'is_plant' => $isPlant,
            'identified_species' => $isPlant ? ($identification['scientific_name'] ?: ($identification['common_name'] ?: null)) : null,
            'id_confidence' => $isPlant ? $identification['confidence'] : null,
            'outcome' => $outcome,
            'gate_rule' => $gateRule !== '' ? Str::limit($gateRule, 48, '') : null,
            'confidence_level' => $confidenceLevel,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'created_at' => now(),
        ]);

        return response()->json(['data' => $body]);
    }

    /** Auth — the current user's saved consultations, newest first. */
    public function consultations(Request $request): JsonResponse
    {
        $rows = PlantDoctorConsultation::where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return response()->json(['data' => $rows]);
    }

    /**
     * Auth — save a diagnosis to the current user's history. user_id always
     * comes from the token, never from input; the per-user row count is capped.
     */
    public function storeConsultation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plant_name' => 'nullable|string|max:191',
            'thumb' => 'nullable|string',
            'diagnosis' => 'required|array',
            'health_score' => 'nullable|integer|min:0|max:100',
            'worst_severity' => 'nullable|string|max:16',
        ]);

        // Size-cap the data-URI thumbnail server-side (client sends ~<50KB).
        if (!empty($data['thumb']) && strlen($data['thumb']) > self::THUMB_MAX_BYTES) {
            return response()->json(['message' => 'Thumbnail is too large.'], 422);
        }

        $userId = $request->user()->id;
        $row = PlantDoctorConsultation::create([
            'user_id' => $userId,
            'plant_name' => $data['plant_name'] ?? null,
            'thumb' => $data['thumb'] ?? null,
            'diagnosis' => $data['diagnosis'],
            'health_score' => $data['health_score'] ?? null,
            'worst_severity' => $data['worst_severity'] ?? null,
        ]);

        // Prune the oldest rows beyond the per-user cap.
        $excess = PlantDoctorConsultation::where('user_id', $userId)
            ->orderByDesc('id')
            ->skip(self::CONSULTATIONS_PER_USER)
            ->take(self::CONSULTATIONS_PER_USER)
            ->pluck('id');
        if ($excess->isNotEmpty()) {
            PlantDoctorConsultation::whereIn('id', $excess)->delete();
        }

        return response()->json(['data' => $row], 201);
    }

    /** Auth — delete one of the current user's consultations (404 otherwise). */
    public function deleteConsultation(Request $request, int $id): JsonResponse
    {
        $deleted = PlantDoctorConsultation::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->delete();
        if (!$deleted) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json(['message' => 'Deleted.']);
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
        $options = $this->modelOptions();
        return response()->json(['data' => [
            'enabled' => $setting ? (bool) $setting->enabled : false,
            'service_url' => $setting->service_url ?? null,
            // Never return the stored key; just whether one is set.
            'has_service_api_key' => !empty($setting->service_api_key),
            // The diagnosis model the service is asked to use. A stored value we no longer price
            // (an old Claude id, say) reads as the first option, which is what the service will run.
            'openai_model' => $this->allowedModel($setting->openai_model ?? null) ?? ($options[0] ?? null),
            'model_options' => $options,
            'monthly_budget_inr' => (float) ($setting->monthly_budget_inr ?? 0),
            'plant_id_enabled' => $setting ? (bool) $setting->plant_id_enabled : false,
            'current_month_cost_inr' => round($usd * $this->usdToInr(), 2),
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
        if (array_key_exists('openai_model', $fields) && $this->allowedModel((string) $fields['openai_model']) === null) {
            return response()->json(['message' => 'Choose one of the supported diagnosis models.', 'code' => 'invalid_model'], 422);
        }
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
        $rate = $this->usdToInr();

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

        // Trust monitoring: what the gate did with the month's photos, and how many of the
        // diagnoses it did allow came with a low-confidence identification.
        $outcomes = PlantDoctorLog::whereBetween('created_at', [$start, $end])
            ->whereNotNull('outcome')
            ->selectRaw('outcome, COUNT(*) as c')
            ->groupBy('outcome')
            ->pluck('c', 'outcome')
            ->map(fn ($c) => (int) $c)
            ->all();
        $rejected = (int) PlantDoctorLog::whereBetween('created_at', [$start, $end])
            ->where('is_plant', false)->count();
        $lowConfidence = (int) PlantDoctorLog::whereBetween('created_at', [$start, $end])
            ->where('is_plant', true)
            ->where(function ($q) {
                $q->where('confidence_level', 'low')
                    ->orWhere(fn ($qq) => $qq->whereNull('confidence_level')->where('id_confidence', '<', 0.5));
            })->count();

        return response()->json(['data' => [
            'month' => $month,
            'total_cost_usd' => round($totalUsd, 6),
            'total_cost_inr' => round($totalUsd * $rate, 4),
            'total_calls' => $totalCalls,
            'total_tokens' => (int) $rows->sum('toks'),
            'rejected_count' => $rejected,
            'rejection_rate' => $totalCalls > 0 ? round($rejected / $totalCalls, 4) : 0,
            'low_confidence_count' => $lowConfidence,
            'outcomes' => $outcomes,
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
            'id', 'user_id', 'plant_name', 'condition_summary', 'top_severity',
            'overall_health_score', 'image_url', 'total_tokens',
            'cost_inr', 'created_at', 'is_plant', 'outcome', 'gate_rule', 'identified_species', 'confidence_level', 'duration_ms',
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
