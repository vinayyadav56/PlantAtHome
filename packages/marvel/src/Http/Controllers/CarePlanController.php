<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Marvel\Database\Models\CarePlan;
use Marvel\Database\Models\CareReminder;
use Marvel\Database\Models\CarePlanSetting;
use Marvel\Services\CarePlanService;
use Marvel\Enums\Permission;

class CarePlanController extends CoreController
{
    private const USD_TO_INR = 83.5;

    public function __construct(private CarePlanService $service = new CarePlanService())
    {
    }

    /** Public — the app reads this to know if the care tracker is on. */
    public function getSettings(): JsonResponse
    {
        $s = CarePlanSetting::first();
        return response()->json(['data' => [
            'enabled' => $s ? (bool) $s->enabled : false,
        ]]);
    }

    /** Customer — my active care plans (with reminders). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $plans = CarePlan::with(['reminders' => fn ($q) => $q->where('active', true)->orderBy('next_due_at')])
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->latest()
            ->get();

        return response()->json(['data' => $plans]);
    }

    /** Customer — a single care plan. */
    public function show(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $plan = CarePlan::with(['reminders' => fn ($q) => $q->orderBy('next_due_at')])
            ->where('user_id', $user->id)
            ->findOrFail($id);

        return response()->json(['data' => $plan]);
    }

    /**
     * Customer — start a care plan for a delivered plant (the manual path; the OrderDelivered
     * listener does this automatically when auto_on_delivery is on). Returns the plan + reminders.
     */
    public function generate(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$this->service->isEnabled()) {
            return response()->json(['message' => 'Care plans are currently unavailable.'], 503);
        }

        $data = $request->validate([
            'plant_name'      => 'required|string|max:191',
            'product_id'      => 'nullable|integer',
            'order_id'        => 'nullable|integer',
            'scientific_name' => 'nullable|string|max:191',
            'image_url'       => 'nullable|string',
            'language'        => 'nullable|string|max:12',
        ]);

        // Region for climate-aware advice — from the user's first saved address, best-effort.
        $region = null;
        $addr = optional($user->address)->first();
        if ($addr && isset($addr->address)) {
            $a = is_array($addr->address) ? $addr->address : (array) $addr->address;
            $region = trim(implode(', ', array_filter([$a['city'] ?? null, $a['state'] ?? null]))) ?: null;
        }

        $plan = $this->service->generateForPlant([
            'user_id'         => $user->id,
            'order_id'        => $data['order_id'] ?? null,
            'product_id'      => $data['product_id'] ?? null,
            'plant_name'      => $data['plant_name'],
            'scientific_name' => $data['scientific_name'] ?? null,
            'image_url'       => $data['image_url'] ?? null,
            'region'          => $region,
            'language'        => $data['language'] ?? 'en',
            'source'          => 'manual',
        ]);

        if (!$plan) {
            return response()->json(['message' => 'Could not create a care plan right now. Please try again later.'], 502);
        }

        return response()->json(['data' => $plan]);
    }

    /** Customer — mark a reminder done; reschedule its next due date by interval_days. */
    public function markReminderDone(Request $request, $id, $reminderId): JsonResponse
    {
        $user = $request->user();
        // Ensure the plan + reminder belong to this user.
        CarePlan::where('user_id', $user->id)->findOrFail($id);
        $reminder = CareReminder::where('care_plan_id', $id)
            ->where('user_id', $user->id)
            ->findOrFail($reminderId);

        $reminder->last_done_at = now();
        $reminder->next_due_at = now()->addDays(max(1, (int) $reminder->interval_days));
        $reminder->save();

        return response()->json(['data' => $reminder]);
    }

    /** Customer — archive a plant (stops its reminders). */
    public function archive(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $plan = CarePlan::where('user_id', $user->id)->findOrFail($id);
        $plan->status = 'archived';
        $plan->save();
        CareReminder::where('care_plan_id', $plan->id)->update(['active' => false]);

        return response()->json(['message' => 'Plant archived.']);
    }

    // ── Admin ──────────────────────────────────────────────────

    public function getAdminSettings(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }
        $s = CarePlanSetting::first();
        $usd = (float) CarePlan::where('created_at', '>=', now()->startOfMonth())->sum('cost_usd');
        return response()->json(['data' => [
            'enabled' => $s ? (bool) $s->enabled : false,
            'auto_on_delivery' => $s ? (bool) $s->auto_on_delivery : false,
            'service_url' => $s->service_url ?? null,
            'has_service_api_key' => !empty($s->service_api_key),
            'model' => $s->model ?? 'claude-opus-4-8',
            'monthly_budget_inr' => (float) ($s->monthly_budget_inr ?? 0),
            'current_month_cost_inr' => round($usd * self::USD_TO_INR, 2),
            'total_plans' => CarePlan::count(),
        ]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }
        $setting = CarePlanSetting::firstOrFail();
        $fields = $request->only(['enabled', 'auto_on_delivery', 'service_url', 'model', 'monthly_budget_inr']);
        if ($request->filled('service_api_key')) {
            $fields['service_api_key'] = $request->input('service_api_key');
        }
        $setting->fill($fields)->save();

        return response()->json(['message' => 'Settings updated.']);
    }

    private function denyUnlessAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        return null;
    }
}
