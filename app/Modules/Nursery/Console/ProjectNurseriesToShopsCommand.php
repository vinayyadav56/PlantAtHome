<?php

namespace App\Modules\Nursery\Console;

use App\Modules\Nursery\Application\NurseryService;
use App\Modules\Nursery\Domain\NurseryStatus;
use App\Modules\Nursery\Infrastructure\Models\Nursery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * v2:project-nurseries-to-shops — idempotent V2→legacy backfill for ORPHANED
 * v2-native nurseries (legacy_id IS NULL, i.e. no legacy `shops` row). These
 * predate the create-path projection (or were created while the legacy `shops`
 * table was absent), so the LEGACY vendor tooling (add-from-catalogue /
 * inventory / pricing, which key on a numeric `shops.id` owned via
 * `shops.owner_id`) can't serve them — the page shows "Type to search" forever.
 *
 * Reuses NurseryService::projectNurseryToLegacyShop() — the SAME projection the
 * create path runs — so there is one source of truth. Self-healing: skips
 * already-linked nurseries, so it is safe to run on every deploy. Owner-less
 * nurseries are skipped (never insert a null shops.owner_id — it's NOT NULL/FK).
 *
 * ⚠️ Run this AFTER, and NEVER followed by, v2:backfill-nurseries on the same
 *    data — the reverse (legacy→V2) backfill maps shops.is_active → status and
 *    would clobber owner_user_uuid on nurseries this command just linked.
 */
class ProjectNurseriesToShopsCommand extends Command
{
    protected $signature = 'v2:project-nurseries-to-shops {--dry-run : Report what would change, write nothing} {--only= : Limit to one nursery by uuid or slug}';

    protected $description = 'Backfill orphaned v2-native nurseries (legacy_id null) into legacy shops so legacy vendor tooling works';

    public function handle(NurseryService $service): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = $this->option('only');

        $query = Nursery::query()->whereNull('legacy_id');
        if ($only) {
            $query->where(function ($q) use ($only) {
                $q->where('uuid', $only)->orWhere('slug', $only);
            });
        }

        // Snapshot the ids up-front so mutating legacy_id can't affect iteration.
        $ids = $query->orderBy('id')->pluck('id');
        $this->line('Found ' . $ids->count() . ' orphaned nurser' . ($ids->count() === 1 ? 'y' : 'ies') . ' (legacy_id null).');

        $projected = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($ids as $id) {
            $nursery = Nursery::find($id);
            if (! $nursery || $nursery->legacy_id !== null) {
                continue;
            }

            try {
                $ownerEmail = $nursery->owner_user_uuid
                    ? DB::table('identity_users')->where('uuid', $nursery->owner_user_uuid)->value('email')
                    : null;
                if (! $ownerEmail) {
                    $skipped++;
                    $this->warn("  skip  {$nursery->slug} — no resolvable owner (owner_user_uuid null / no email)");
                    continue;
                }

                if ($dry) {
                    $state = $nursery->status === NurseryStatus::ACTIVE ? 'active' : (string) $nursery->status;
                    $this->line("  would {$nursery->slug} → legacy shop (owner {$ownerEmail}, {$state})");
                    continue;
                }

                $legacyId = DB::transaction(function () use ($service, $nursery) {
                    // Lock + re-check inside the txn (guards concurrent runs).
                    $fresh = Nursery::whereKey($nursery->getKey())->lockForUpdate()->first();
                    if (! $fresh || $fresh->legacy_id !== null) {
                        return $fresh?->legacy_id;
                    }
                    $shopId = $service->projectNurseryToLegacyShop($fresh);
                    if ($shopId !== null) {
                        $fresh->legacy_id = $shopId;
                        $fresh->save();
                    }
                    return $shopId;
                });

                if ($legacyId === null) {
                    $skipped++;
                    $this->warn("  skip  {$nursery->slug} — projection returned null (legacy tables absent / owner unresolved)");
                    continue;
                }

                $projected++;
                $this->info("  ok    {$nursery->slug} → shops.id {$legacyId}");
            } catch (\Throwable $e) {
                $errors++;
                $this->error("  err   {$nursery->slug}: " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info(($dry ? '[dry-run] ' : '') . "done — projected: {$projected}, skipped: {$skipped}, errors: {$errors}");

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
