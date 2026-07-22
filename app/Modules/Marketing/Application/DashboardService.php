<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application;

use App\Modules\Marketing\Domain\NotificationStatus;
use App\Modules\Marketing\Infrastructure\Models\Audience;
use App\Modules\Marketing\Infrastructure\Models\Campaign;
use App\Modules\Marketing\Infrastructure\Models\CampaignRun;
use App\Modules\Marketing\Infrastructure\Models\MarketingNotification;
use App\Modules\Marketing\Infrastructure\Models\Template;
use Illuminate\Support\Carbon;

/** Top-of-module dashboard: inventory counts + recent activity + send volume. */
final class DashboardService
{
    /** @return array<string,mixed> */
    public function overview(): array
    {
        return [
            'audiences'   => Audience::count(),
            'templates'   => [
                'total'    => Template::count(),
                'by_channel' => Template::selectRaw('channel, COUNT(*) as c')->groupBy('channel')->pluck('c', 'channel')->map(fn ($c) => (int) $c)->all(),
            ],
            'campaigns'   => [
                'total'     => Campaign::count(),
                'by_status' => Campaign::selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status')->map(fn ($c) => (int) $c)->all(),
            ],
            'messages'    => [
                'total'    => (int) MarketingNotification::count(),
                'sent'     => (int) MarketingNotification::whereIn('status', [NotificationStatus::SENT, NotificationStatus::DELIVERED, NotificationStatus::READ])->count(),
                'failed'   => (int) MarketingNotification::where('status', NotificationStatus::FAILED)->count(),
                'today'    => (int) MarketingNotification::where('sent_at', '>=', Carbon::now()->startOfDay())->count(),
                'last_7d'  => (int) MarketingNotification::where('sent_at', '>=', Carbon::now()->subDays(7))->count(),
                'last_30d' => (int) MarketingNotification::where('sent_at', '>=', Carbon::now()->subDays(30))->count(),
            ],
            'recent_campaigns' => Campaign::orderByDesc('id')->limit(6)->get()->map(fn (Campaign $c) => [
                'uuid'       => $c->uuid,
                'name'       => $c->name,
                'status'     => $c->status,
                'channels'   => $c->channels ?? [],
                'last_run_at' => $c->last_run_at?->toIso8601String(),
                'next_run_at' => $c->next_run_at?->toIso8601String(),
            ])->all(),
            'recent_runs' => CampaignRun::with('campaign:id,uuid,name')->orderByDesc('id')->limit(8)->get()->map(fn (CampaignRun $r) => [
                'uuid'           => $r->uuid,
                'campaign'       => $r->campaign?->name,
                'status'         => $r->status,
                'trigger'        => $r->trigger,
                'total_messages' => (int) $r->total_messages,
                'counts'         => $r->counts ?? [],
                'started_at'     => $r->started_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
