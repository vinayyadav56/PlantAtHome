<?php

namespace App\Modules\Search\Console;

use App\Modules\Search\Application\SearchService;
use Illuminate\Console\Command;

/**
 * Full reconcile of the search projection from the catalog (Section 12's nightly
 * reconcile — catches any drift the event stream missed).
 */
class ReindexSearchCommand extends Command
{
    protected $signature = 'search:reindex';

    protected $description = 'Rebuild the search projection from the master catalog';

    public function handle(SearchService $search): int
    {
        $count = $search->reindexAll();
        $this->info("search:reindex indexed {$count} product(s).");

        return self::SUCCESS;
    }
}
