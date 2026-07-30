<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\Http;
use Marvel\Database\Models\CarePlan;
use Marvel\Database\Models\CareReminder;
use Marvel\Database\Models\CarePlanSetting;

/**
 * Generates + persists AI care plans by proxying the care-plan microservice. Shared by the
 * customer "start care" endpoint and the OrderDelivered listener. Gated by the admin settings
 * (enabled + monthly INR budget); the AI itself only runs once ANTHROPIC credits + the service
 * are configured.
 */
class CarePlanService
{
    private const MODEL_PRICES = [
        'claude-opus-4-8'   => [5.00, 25.00],
        'claude-opus-4-7'   => [5.00, 25.00],
        'claude-sonnet-4-6' => [3.00, 15.00],
        'claude-haiku-4-5'  => [1.00, 5.00],
    ];
    private const DEFAULT_MODEL = 'claude-opus-4-8';
    private const USD_TO_INR = 83.5;

    public function setting(): ?CarePlanSetting
    {
        return CarePlanSetting::first();
    }

    public function isEnabled(): bool
    {
        $s = $this->setting();
        return $s && $s->enabled;
    }

    public function monthCostInr(): float
    {
        return (float) CarePlan::where('created_at', '>=', now()->startOfMonth())->sum('cost_inr');
    }

    private function withinBudget(CarePlanSetting $setting): bool
    {
        return !($setting->monthly_budget_inr > 0 && $this->monthCostInr() >= (float) $setting->monthly_budget_inr);
    }

    private function tokenPrice(?string $model): array
    {
        return self::MODEL_PRICES[$model] ?? self::MODEL_PRICES[self::DEFAULT_MODEL];
    }

    /**
     * Generate (or return the existing) care plan for a plant. Returns the CarePlan with its
     * reminders, or null when disabled / over budget / unreachable.
     *
     * @param array $args user_id, plant_name (required); order_id, product_id, scientific_name,
     *                    region, language, image_url, source (optional).
     */
    public function generateForPlant(array $args): ?CarePlan
    {
        $setting = $this->setting();
        if (!$setting || !$setting->enabled) {
            return null;
        }
        if (!$this->withinBudget($setting)) {
            return null;
        }
        // Resolved through the Integration module (integration_providers → care_plan_settings →
        // env), so a rotated key is an admin action rather than a redeploy.
        $integrations = app(\Marvel\Integrations\IntegrationService::class);
        $serviceUrl = rtrim((string) $integrations->config('care_plan', 'service_url', env('CARE_PLAN_SERVICE_URL')), '/');
        $serviceKey = $integrations->secret('care_plan', 'service_api_key', (string) env('CARE_PLAN_SERVICE_API_KEY'));
        if (empty($serviceUrl) || empty($args['user_id']) || empty($args['plant_name'])) {
            return null;
        }

        // One active plan per user + product (idempotent across re-deliveries / retries).
        if (!empty($args['product_id'])) {
            $existing = CarePlan::where('user_id', $args['user_id'])
                ->where('product_id', $args['product_id'])
                ->whereNull('deleted_at')
                ->first();
            if ($existing) {
                return $existing->load('reminders');
            }
        }

        try {
            $resp = Http::timeout(90)
                ->withHeaders(array_filter(['X-Api-Key' => $serviceKey]))
                ->post($serviceUrl . '/care-plan', [
                    'plant_name' => $args['plant_name'],
                    'scientific_name' => $args['scientific_name'] ?? null,
                    'region' => $args['region'] ?? null,
                    'language' => $args['language'] ?? 'en',
                ]);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$resp->successful()) {
            return null;
        }

        $body = $resp->json();
        $usage = $body['usage'] ?? [];
        $prompt = (int) ($usage['input_tokens'] ?? 0);
        $completion = (int) ($usage['output_tokens'] ?? 0);
        [$pPrice, $cPrice] = $this->tokenPrice($usage['model'] ?? self::DEFAULT_MODEL);
        $costUsd = ($prompt * $pPrice + $completion * $cPrice) / 1000000;

        $plan = CarePlan::create([
            'user_id' => $args['user_id'],
            'order_id' => $args['order_id'] ?? null,
            'product_id' => $args['product_id'] ?? null,
            'plant_name' => $body['plant_name'] ?? $args['plant_name'],
            'scientific_name' => $body['scientific_name'] ?? ($args['scientific_name'] ?? null),
            'language' => $args['language'] ?? 'en',
            'image_url' => $args['image_url'] ?? null,
            'plan_json' => $body,
            'status' => 'active',
            'source' => $args['source'] ?? 'delivery',
            'total_tokens' => $prompt + $completion,
            'cost_usd' => $costUsd,
            'cost_inr' => $costUsd * self::USD_TO_INR,
        ]);

        foreach ($body['reminders'] ?? [] as $r) {
            CareReminder::create([
                'care_plan_id' => $plan->id,
                'user_id' => $args['user_id'],
                'type' => $r['type'] ?? 'health_check',
                'title' => $r['title'] ?? 'Care reminder',
                'note' => $r['note'] ?? null,
                'interval_days' => (int) ($r['interval_days'] ?? 7),
                'next_due_at' => now()->addDays((int) ($r['first_due_offset_days'] ?? 0)),
                'active' => true,
            ]);
        }

        return $plan->load('reminders');
    }
}
