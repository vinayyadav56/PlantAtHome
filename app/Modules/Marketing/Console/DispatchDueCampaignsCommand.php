<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Console;

use App\Modules\Marketing\Application\CampaignService;
use App\Modules\Marketing\Domain\CampaignStatus;
use App\Modules\Marketing\Infrastructure\Models\Campaign;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Scheduler entry point (run every minute): launch any campaign whose scheduled
 * fire time has arrived. Launch only creates the run + enqueues work, so this
 * command is fast and never sends inline. Recurring campaigns re-arm their
 * next_run_at inside launch(). One bad campaign never blocks the rest.
 */
final class DispatchDueCampaignsCommand extends Command
{
    protected $signature = 'marketing:dispatch-due {--limit=200 : max campaigns to launch this tick}';

    protected $description = 'Launch marketing campaigns whose scheduled time has arrived.';

    public function handle(CampaignService $campaigns): int
    {
        $due = Campaign::query()
            ->where('status', CampaignStatus::SCHEDULED)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', Carbon::now())
            ->orderBy('next_run_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $launched = 0;
        foreach ($due as $campaign) {
            try {
                $campaigns->launch($campaign, $campaign->created_by_uuid, 'scheduled');
                $launched++;
            } catch (\Throwable $e) {
                $this->warn("Campaign {$campaign->uuid} failed to launch: {$e->getMessage()}");
                report($e);
            }
        }

        $this->info("Marketing: launched {$launched} due campaign(s).");

        return self::SUCCESS;
    }
}
