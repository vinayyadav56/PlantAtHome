<?php

namespace Marvel\Integrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Append-only operational log for integration activity.
 *
 * Answers "is this provider broken, and since when" — nothing more. There is deliberately no
 * request/response body: partner exchanges carry customer addresses and phone numbers, and auth
 * headers carry the credential itself. The Go service's exchange viewer already covers on-demand
 * debugging with masking applied; duplicating it here would turn an ops log into the most sensitive
 * table in the database.
 *
 * Every write is best-effort. A logging failure must never break the operation being logged.
 */
class IntegrationLog
{
    public const ACTION_TEST      = 'test_connection';
    public const ACTION_SYNC      = 'credential_sync';
    public const ACTION_HEALTH    = 'health_check';

    public const STATUS_OK     = 'ok';
    public const STATUS_FAILED = 'failed';

    public static function record(
        string $slug,
        string $action,
        string $status,
        array $detail = []
    ): void {
        try {
            if (!Schema::hasTable('integration_logs')) {
                return;
            }

            DB::table('integration_logs')->insert([
                'provider_slug' => $slug,
                'environment'   => (string) (config('integrations.environment') ?: 'production'),
                'action'        => $action,
                'status'        => $status,
                'http_status'   => isset($detail['http_status']) ? (int) $detail['http_status'] : null,
                'duration_ms'   => isset($detail['duration_ms']) ? (int) $detail['duration_ms'] : null,
                'error_code'    => isset($detail['error_code']) ? substr((string) $detail['error_code'], 0, 64) : null,
                // Truncated hard: a vendor error occasionally echoes the request back, and an echoed
                // request can contain the credential that was just rejected.
                'error_message' => isset($detail['error_message']) ? substr((string) $detail['error_message'], 0, 500) : null,
                'user_id'       => optional(request()?->user())->id,
                'created_at'    => now(),
            ]);
        } catch (Throwable) {
            // best-effort
        }
    }

    /** Delete entries older than the retention window. Returns the number removed. */
    public static function prune(int $days = 90): int
    {
        try {
            if (!Schema::hasTable('integration_logs')) {
                return 0;
            }

            return DB::table('integration_logs')
                ->where('created_at', '<', now()->subDays($days))
                ->delete();
        } catch (Throwable) {
            return 0;
        }
    }
}
