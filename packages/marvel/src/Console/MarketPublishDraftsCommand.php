<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Marvel\Services\MarketIntelligenceService;

/**
 * Approve/publish the imported competitor-catalogue drafts (master-shop,
 * unpriced, never vendor proposals) so they enter the live catalogue that
 * vendors can attach rates to. Idempotent. Runnable on prod via
 * prod-data-op.yml (market-publish[-dry]).
 */
class MarketPublishDraftsCommand extends Command
{
    protected $signature = 'market:publish-drafts {--dry-run : count only, write nothing}';

    protected $description = 'Publish imported competitor-catalogue drafts into the live master catalogue.';

    public function handle(MarketIntelligenceService $svc): int
    {
        $r = $svc->publishDrafts((bool) $this->option('dry-run'));
        $this->info(sprintf(
            'matched %d imported drafts — published %d; drafts remaining %d; total published now %d',
            $r['matched'], $r['published'], $r['remaining_drafts'], $r['total_published'],
        ));
        $this->info($this->option('dry-run') ? 'DRY RUN — nothing written.' : 'Publish complete.');

        return self::SUCCESS;
    }
}
