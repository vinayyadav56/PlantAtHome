<?php

namespace Marvel\Services\TestDataCleanup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operational logs and AI/diagnostic byproducts that accumulate during testing. These carry no
 * business meaning once the test data they describe is gone. A date window is supported so a
 * live system can trim history without losing recent context.
 */
class MiscCleaner implements CleanerContract
{
    private const TABLES = [
        'plant_doctor_logs', 'plant_doctor_consultations', 'ai_chat_conversations', 'ai_chat_messages',
        'voice_search_logs', 'analytics_events', 'visitors', 'request_logs', 'request_log_exceptions',
        'email_logs', 'notify_logs', 'device_tokens', 'partner_webhook_events',
        'service_availability_logs', 'integration_logs', 'marketing_delivery_logs', 'marketing_queue_logs',
    ];

    public function key(): string { return 'misc'; }

    public function label(): string { return 'Logs & Diagnostics'; }

    public function description(): string
    {
        return 'Clears operational logs and AI/diagnostic byproducts (request logs, analytics, '
             . 'plant-doctor and chat history, email/notification logs, device tokens). No '
             . 'business records are affected.';
    }

    public function stats(): array
    {
        $out = [];
        foreach (self::TABLES as $t) {
            if (Schema::hasTable($t)) {
                $out[$t] = DB::table($t)->count();
            }
        }
        return $out;
    }

    public function plan(array $scope): CleanupPlan
    {
        $plan = new CleanupPlan($this->key(), $scope);
        $tables = (array) ($scope['tables'] ?? self::TABLES);
        $before = $scope['before'] ?? null;

        foreach ($tables as $table) {
            if (!in_array($table, self::TABLES, true) || !Schema::hasTable($table)) {
                continue; // allowlist: this module can only ever touch known log tables
            }
            $q = DB::table($table);
            if ($before && Schema::hasColumn($table, 'created_at')) {
                $q->where('created_at', '<', $before);
            }
            $plan->step($table, $q->pluck('id')->all(), 'id');
        }
        return $plan;
    }
}
