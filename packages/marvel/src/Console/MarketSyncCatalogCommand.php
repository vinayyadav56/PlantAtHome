<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Marvel\Services\MarketIntelligenceService;

/**
 * One-shot competitor-catalogue sync (same sequence verified on staging):
 *   1. import the FULL NurseryLive catalogue (plants only, ~2,663 names)
 *   2. import the intelligence-service sample across all sources incl. combos
 *   3. de-duplicate drafts against the pre-existing catalogue (normalized names)
 *
 * Everything lands as unpriced DRAFTS in the master shop (storefront-invisible);
 * existing products are never touched; all steps are idempotent, so re-runs are
 * safe no-ops. Runnable on prod via prod-data-op.yml (market-sync[-dry]).
 */
class MarketSyncCatalogCommand extends Command
{
    protected $signature = 'market:sync-catalog {--dry-run : preview counts only, write nothing}';

    protected $description = 'Import competitor plant names as drafts (full NurseryLive + intelligence sample) and de-duplicate.';

    public function handle(MarketIntelligenceService $svc): int
    {
        $dry = (bool) $this->option('dry-run');

        // [1/3] Full NurseryLive catalogue (Shopify feed, plants only).
        $p1 = $svc->importPreview('nurserylive_full', false);
        $this->info(sprintf(
            '[1/3] NurseryLive FULL: %d candidates, %d already in catalogue, %d to create',
            $p1['total_candidates'], $p1['already_in_catalog'], $p1['to_create'],
        ));
        if (! $dry && $p1['to_create'] > 0) {
            $r = $svc->importNames('nurserylive_full', false);
            $this->info("      created {$r['created']} drafts");
        }

        // [2/3] Intelligence-service sample, all sources incl. combos/bundles.
        $p2 = $svc->importPreview(null, true);
        $this->info(sprintf(
            '[2/3] Intelligence sample (all sources + combos): %d candidates, %d already in, %d to create',
            $p2['total_candidates'], $p2['already_in_catalog'], $p2['to_create'],
        ));
        if (! $dry && $p2['to_create'] > 0) {
            $r = $svc->importNames(null, true);
            $this->info("      created {$r['created']} drafts");
        }

        // [3/3] De-duplicate drafts vs the pre-existing catalogue.
        $p3 = $svc->dedupePreview();
        $this->info("[3/3] Duplicate drafts: {$p3['duplicates_found']}");
        foreach (array_slice($p3['sample'], 0, 15) as $line) {
            $this->line('      '.$line);
        }
        if (! $dry && $p3['duplicates_found'] > 0) {
            $r = $svc->dedupeDrafts();
            $this->info("      removed {$r['removed']} duplicates — {$r['remaining_drafts']} unique drafts remain");
        }

        $this->info($dry ? 'DRY RUN — nothing written.' : 'Catalogue sync complete.');

        return self::SUCCESS;
    }
}
