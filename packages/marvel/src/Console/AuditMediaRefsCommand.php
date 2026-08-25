<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only census of raw-S3-host references across the media URL registry.
 * Exit 1 when any remain — CI-gateable as the "rewrite is complete" check
 * before media:gc --include-adopted or a CDN-only serving policy.
 */
class AuditMediaRefsCommand extends Command
{
    protected $signature = 'media:audit-refs';

    protected $description = 'Count remaining raw S3 host references in media URL columns (exit 1 if any).';

    public function handle(): int
    {
        // Host fragment, not the full URL — catches any region/scheme variant.
        $needle = config('filesystems.disks.s3.bucket') . '.s3.';

        $rows = [];
        $total = 0;
        foreach (RewriteMediaUrlsCommand::REGISTRY as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    continue;
                }
                $n = DB::table($table)->where($column, 'like', "%{$needle}%")->count();
                if ($n) {
                    $rows[] = [$table, $column, $n];
                    $total += $n;
                }
            }
        }

        $this->table(['table', 'column', 'raw-host rows'], $rows ?: [['—', '—', 0]]);
        if ($total) {
            $this->error("{$total} value(s) still reference {$needle}* — run media:rewrite-urls.");
            return self::FAILURE;
        }
        $this->info('No raw S3 host references remain.');
        return self::SUCCESS;
    }
}
