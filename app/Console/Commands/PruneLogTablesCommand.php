<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\RequestLogSetting;

/**
 * The ONE retention story for every log-ish table.
 *
 * Before this command, only integration_logs had scheduled pruning. request_logs
 * relied on an opportunistic 1-in-50 delete inside the logging middleware — which
 * stopped entirely the moment logging was disabled, freezing the table at its
 * peak size. Everything else (analytics_events, voice/plant-doctor logs,
 * notify_logs, availability/coverage audits, outbox, processed_events) grew
 * monotonically forever.
 *
 * Deletes are chunked with a breather between chunks so a large backlog never
 * holds long locks on the small production box. integration_logs is deliberately
 * absent — integrations:health already prunes it.
 */
class PruneLogTablesCommand extends Command
{
    protected $signature = 'logs:prune {--dry-run : Report what would be deleted without deleting}';
    protected $description = 'Prune log/audit tables per retention policy (request logs use the admin setting)';

    private const CHUNK = 5000;

    /** table => [days, timestamp column, optional extra where [col => value]] */
    private const TABLES = [
        'request_logs'              => ['days' => null, 'col' => 'created_at'], // null = admin setting
        'request_log_exceptions'    => ['days' => 30, 'col' => 'created_at'],
        'analytics_events'          => ['days' => 30, 'col' => 'created_at'],
        'voice_search_logs'         => ['days' => 30, 'col' => 'created_at'],
        'plant_doctor_logs'         => ['days' => 30, 'col' => 'created_at'],
        'notify_logs'               => ['days' => 30, 'col' => 'created_at'],
        'email_logs'                => ['days' => 90, 'col' => 'created_at'],
        'service_availability_logs' => ['days' => 90, 'col' => 'created_at'],
        'coverage_audit_logs'       => ['days' => 90, 'col' => 'created_at'],
        // Only PUBLISHED outbox rows are prunable — pending/failed are work.
        'outbox'                    => ['days' => 14, 'col' => 'created_at', 'where' => ['status' => 'published']],
        'processed_events'          => ['days' => 14, 'col' => 'processed_at'],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        foreach (self::TABLES as $table => $cfg) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (!Schema::hasColumn($table, $cfg['col'])) {
                // Schema drift: warn and move on — one odd table must not stop
                // retention for every table after it in the list.
                $this->warn(sprintf('%-26s skipped: no %s column', $table, $cfg['col']));
                continue;
            }

            $days = $cfg['days'] ?? $this->requestLogRetentionDays();
            $cutoff = now()->subDays(max(1, $days));

            $base = DB::table($table)->where($cfg['col'], '<', $cutoff);
            foreach (($cfg['where'] ?? []) as $col => $val) {
                $base = $base->where($col, $val);
            }

            if ($dry) {
                $this->line(sprintf('%-26s would delete %d rows (older than %dd)', $table, (clone $base)->count(), $days));
                continue;
            }

            $total = 0;
            do {
                $deleted = (clone $base)->limit(self::CHUNK)->delete();
                $total += $deleted;
                if ($deleted === self::CHUNK) {
                    usleep(100_000); // breathe between chunks — no long locks on a 2-core box
                }
            } while ($deleted === self::CHUNK);

            if ($total > 0) {
                $this->info(sprintf('%-26s pruned %d rows (older than %dd)', $table, $total, $days));
            }
        }

        return self::SUCCESS;
    }

    private function requestLogRetentionDays(): int
    {
        try {
            return (int) (RequestLogSetting::first()?->retention_days ?? 30);
        } catch (\Throwable) {
            return 30;
        }
    }
}
