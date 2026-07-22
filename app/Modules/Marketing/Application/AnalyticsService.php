<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application;

use App\Modules\Marketing\Domain\NotificationStatus;
use App\Modules\Marketing\Infrastructure\Models\Campaign;
use App\Modules\Marketing\Infrastructure\Models\CampaignRun;
use App\Modules\Marketing\Infrastructure\Models\DeliveryLog;
use App\Modules\Marketing\Infrastructure\Models\MarketingNotification;
use Illuminate\Support\Carbon;

/**
 * Campaign analytics: status tallies, per-channel breakdown, delivery rates, and
 * a daily time-series for the dashboard charts. Open/CTR/conversion are exposed
 * as forward-looking placeholders (they need click/open tracking, not wired yet).
 */
final class AnalyticsService
{
    /** @return array<string,mixed> */
    public function forCampaign(Campaign $campaign): array
    {
        $byStatus = $this->tally(MarketingNotification::where('campaign_id', $campaign->id));
        $total = array_sum($byStatus);
        $sent = ($byStatus[NotificationStatus::SENT] ?? 0)
            + ($byStatus[NotificationStatus::DELIVERED] ?? 0)
            + ($byStatus[NotificationStatus::READ] ?? 0);
        $delivered = ($byStatus[NotificationStatus::DELIVERED] ?? 0) + ($byStatus[NotificationStatus::READ] ?? 0);
        $read = $byStatus[NotificationStatus::READ] ?? 0;
        $failed = $byStatus[NotificationStatus::FAILED] ?? 0;

        return [
            'campaign_uuid' => $campaign->uuid,
            'totals'        => $this->totalsBlock($byStatus, $total),
            'by_channel'    => $this->byChannel($campaign),
            'rates'         => [
                'sent_rate'      => $this->rate($sent, $total),
                'delivered_rate' => $this->rate($delivered, $sent),
                'read_rate'      => $this->rate($read, $sent),
                'failure_rate'   => $this->rate($failed, $total),
                // forward-looking — need open/click tracking pixels + links
                'open_rate'       => null,
                'click_rate'      => null,
                'conversion_rate' => null,
            ],
            'runs'          => $this->recentRuns($campaign),
            'timeseries'    => $this->timeseries($campaign),
        ];
    }

    /** @param array<string,int> $byStatus @return array<string,int> */
    private function totalsBlock(array $byStatus, int $total): array
    {
        $block = ['total' => $total];
        foreach (NotificationStatus::all() as $status) {
            $block[$status] = $byStatus[$status] ?? 0;
        }

        return $block;
    }

    /** @return array<int, array<string,mixed>> */
    private function byChannel(Campaign $campaign): array
    {
        $rows = MarketingNotification::where('campaign_id', $campaign->id)
            ->selectRaw('channel, status, COUNT(*) as c')
            ->groupBy('channel', 'status')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->channel] ??= ['channel' => $row->channel, 'total' => 0];
            $out[$row->channel][$row->status] = (int) $row->c;
            $out[$row->channel]['total'] += (int) $row->c;
        }

        return array_values($out);
    }

    /** @return array<int, array<string,mixed>> */
    private function recentRuns(Campaign $campaign): array
    {
        return CampaignRun::where('campaign_id', $campaign->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (CampaignRun $r) => [
                'uuid'             => $r->uuid,
                'status'           => $r->status,
                'trigger'          => $r->trigger,
                'total_recipients' => (int) $r->total_recipients,
                'total_messages'   => (int) $r->total_messages,
                'counts'           => $r->counts ?? [],
                'started_at'       => $r->started_at?->toIso8601String(),
                'finished_at'      => $r->finished_at?->toIso8601String(),
            ])->all();
    }

    /**
     * Daily sent/delivered/read/failed for the last 30 days, from the delivery
     * log — the shape the admin apexcharts line/bar chart consumes.
     *
     * @return array<int, array<string,mixed>>
     */
    private function timeseries(Campaign $campaign, int $days = 30): array
    {
        $since = Carbon::now()->subDays($days)->startOfDay();

        // Count DISTINCT notifications reaching each status per day — the delivery
        // log is append-only (many rows per notification, incl. logged-but-not-
        // applied transitions), so COUNT(*) would inflate the series.
        $rows = DeliveryLog::where('campaign_id', $campaign->id)
            ->where('occurred_at', '>=', $since)
            ->selectRaw('DATE(occurred_at) as d, status, COUNT(DISTINCT notification_id) as c')
            ->groupBy('d', 'status')
            ->get();

        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row->d] ??= ['date' => $row->d, 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0];
            if (array_key_exists($row->status, $byDate[$row->d])) {
                $byDate[$row->d][$row->status] += (int) $row->c;
            }
        }
        ksort($byDate);

        return array_values($byDate);
    }

    private function tally($query): array
    {
        return $query->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 2) : 0.0;
    }
}
