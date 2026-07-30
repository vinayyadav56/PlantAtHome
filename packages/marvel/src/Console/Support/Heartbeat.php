<?php

namespace Marvel\Console\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-command liveness marker in `platform_heartbeats` (the same table the
 * Kernel's `scheduler` beat uses). Surfaced by GET /api/v1/platform/status so a
 * scheduled command that silently stops running — a skipped overlap mutex, a
 * thrown-and-swallowed exception — is visible without shell access to the box.
 * Never throws: a diagnostic must not be able to fail the command it observes.
 */
final class Heartbeat
{
    public static function beat(string $name): void
    {
        try {
            if (!Schema::hasTable('platform_heartbeats')) {
                return;
            }
            DB::table('platform_heartbeats')->updateOrInsert(
                ['name' => $name],
                ['beat_at' => now()],
            );
        } catch (\Throwable $e) {
            // Observability only — swallow.
        }
    }
}
