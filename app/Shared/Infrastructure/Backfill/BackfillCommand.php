<?php

namespace App\Shared\Infrastructure\Backfill;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Base class for legacy→v2 backfill commands (v2:backfill-*).
 *
 * Legacy (marvel) tables and v2 tables share one MySQL database, so a backfill
 * reads the legacy table directly on the default connection and upserts into
 * the v2 table keyed on `legacy_id`. Deterministic uuids (LegacyUuid) make
 * cross-module references computable and re-runs idempotent.
 *
 * Flags every subclass gets for free (declare them in $signature):
 *   --dry-run        print the row plan, write nothing
 *   --verify         compare source count vs backfilled count + sample rows
 *   --since=         only rows with source updated_at >= the given timestamp
 *   --since-cursor   delta from the last cursor position (tailing sync)
 *
 * Subclasses implement steps(): a list of BackfillStep definitions, each
 * mapping one legacy table to one v2 table.
 */
abstract class BackfillCommand extends Command
{
    /**
     * @return array<int, array{
     *   resource: string,        // cursor key, e.g. "nurseries"
     *   source: string,          // legacy table
     *   target: string,          // v2 table
     *   map: callable(object): ?array,  // legacy row -> v2 columns (null = skip row)
     *   verify?: array<string>,  // column pairs "legacy=>v2" sampled during --verify
     * }>
     */
    abstract protected function steps(): array;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $verify = (bool) $this->option('verify');

        foreach ($this->steps() as $step) {
            if ($verify) {
                $this->verifyStep($step);
                continue;
            }

            $this->runStep($step, $dryRun);
        }

        return self::SUCCESS;
    }

    private function runStep(array $step, bool $dryRun): void
    {
        $source = $step['source'];
        $target = $step['target'];
        $query = DB::table($source)->orderBy('id');

        $since = $this->sinceTimestamp($step['resource']);
        if ($since !== null) {
            $query->where('updated_at', '>=', $since);
        }

        $total = (clone $query)->count();
        $this->info(sprintf('[%s] %s -> %s: %d source rows%s', $step['resource'], $source, $target, $total, $dryRun ? ' (dry-run)' : ''));

        if ($dryRun) {
            $query->limit(5)->get()->each(function ($row) use ($step) {
                $mapped = ($step['map'])($row);
                $this->line('  sample: '.json_encode($mapped === null ? ['SKIPPED' => $row->id] : array_intersect_key($mapped, array_flip(array_slice(array_keys($mapped), 0, 6)))));
            });

            return;
        }

        $upserted = 0;
        $skipped = 0;
        $maxUpdatedAt = null;

        $query->chunkById(200, function ($rows) use ($step, $target, &$upserted, &$skipped, &$maxUpdatedAt) {
            $batch = [];
            foreach ($rows as $row) {
                $mapped = ($step['map'])($row);
                if ($mapped === null) {
                    $skipped++;
                    continue;
                }
                $batch[] = $mapped;
                if (isset($row->updated_at) && ($maxUpdatedAt === null || $row->updated_at > $maxUpdatedAt)) {
                    $maxUpdatedAt = $row->updated_at;
                }
            }

            if ($batch !== []) {
                // Upsert keyed on legacy_id: re-runs update in place, never duplicate.
                $update = array_diff(array_keys($batch[0]), ['legacy_id', 'uuid', 'created_at']);
                DB::table($target)->upsert($batch, ['legacy_id'], array_values($update));
                $upserted += count($batch);
            }
        });

        $this->info(sprintf('  upserted %d, skipped %d', $upserted, $skipped));

        DB::table('backfill_cursors')->upsert([[
            'resource' => $step['resource'],
            'last_source_updated_at' => $maxUpdatedAt,
            'last_run_at' => Carbon::now(),
            'rows_upserted' => $upserted,
            'updated_at' => Carbon::now(),
            'created_at' => Carbon::now(),
        ]], ['resource'], ['last_source_updated_at', 'last_run_at', 'rows_upserted', 'updated_at']);
    }

    private function verifyStep(array $step): void
    {
        $source = $step['source'];
        $target = $step['target'];

        $sourceCount = DB::table($source)->count();
        $targetCount = DB::table($target)->whereNotNull('legacy_id')->count();

        $status = $targetCount >= $sourceCount ? 'OK' : 'MISMATCH';
        $this->info(sprintf('[%s] VERIFY %s: source=%d backfilled=%d -> %s', $step['resource'], $source, $sourceCount, $targetCount, $status));

        // Sample rows: recompute the mapping for random source rows and diff
        // the mapped values against what's stored.
        $samples = DB::table($source)->inRandomOrder()->limit(5)->get();
        foreach ($samples as $row) {
            $mapped = ($step['map'])($row);
            if ($mapped === null) {
                continue;
            }
            $stored = DB::table($target)->where('legacy_id', $row->id)->first();
            if (! $stored) {
                $this->error(sprintf('  row legacy_id=%d MISSING in %s', $row->id, $target));
                continue;
            }
            $diffs = [];
            foreach ($mapped as $col => $value) {
                if (in_array($col, ['created_at', 'updated_at'], true)) {
                    continue;
                }
                $storedValue = $stored->{$col} ?? null;
                if ((string) $storedValue !== (string) $value && ! ($storedValue === null && $value === null)) {
                    $diffs[] = sprintf('%s: mapped=%s stored=%s', $col, var_export($value, true), var_export($storedValue, true));
                }
            }
            if ($diffs === []) {
                $this->line(sprintf('  row legacy_id=%d matches', $row->id));
            } else {
                $this->warn(sprintf('  row legacy_id=%d DIFFS: %s', $row->id, implode('; ', $diffs)));
            }
        }
    }

    private function sinceTimestamp(string $resource): ?string
    {
        if ($this->option('since')) {
            return (string) $this->option('since');
        }

        if ($this->option('since-cursor')) {
            $cursor = DB::table('backfill_cursors')->where('resource', $resource)->value('last_source_updated_at');

            return $cursor !== null ? (string) $cursor : null;
        }

        return null;
    }
}
