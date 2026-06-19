<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\AnalyticsEvent;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Visitor;
use Marvel\Enums\Permission;

/**
 * Visitor / Live Activity NOC (Phase 3) metrics. Reads the visitors +
 * analytics_events tables (storefront-fed) plus existing orders/users for the
 * activity feed. Every read is failure-safe (missing tables → empty) so the NOC
 * works even before storefront tracking is switched on.
 */
class VisitorMetricsService
{
    private const ONLINE_WINDOW_MIN = 5;

    /** Who's online now + the live user table. */
    public function liveVisitors(int $windowMin = self::ONLINE_WINDOW_MIN): array
    {
        $since = Carbon::now()->subMinutes($windowMin);

        $online = Visitor::where('last_seen', '>=', $since)->count();
        $customers = Visitor::where('last_seen', '>=', $since)->whereNotNull('user_id')->count();

        $byDevice = Visitor::where('last_seen', '>=', $since)
            ->select('device', DB::raw('COUNT(*) as c'))
            ->groupBy('device')->pluck('c', 'device');

        $visitors = Visitor::where('last_seen', '>=', $since)
            ->orderByDesc('last_seen')->limit(60)->get()
            ->map(fn (Visitor $v) => [
                'visitor_id'      => $v->visitor_id,
                'is_customer'     => !is_null($v->user_id),
                'current_page'    => $v->current_page,
                'previous_page'   => $v->previous_page,
                'device'          => $v->device,
                'browser'         => $v->browser,
                'city'            => $v->city,
                'country'         => $v->country,
                'page_views'      => (int) $v->page_views,
                'time_on_site_sec' => ($v->first_seen && $v->last_seen) ? $v->last_seen->diffInSeconds($v->first_seen) : 0,
                'last_seen'       => $v->last_seen,
            ]);

        return [
            'online'    => $online,
            'customers' => $customers,
            'guests'    => max(0, $online - $customers),
            'by_device' => $byDevice,
            'visitors'  => $visitors,
        ];
    }

    /** The page/event timeline for one visitor (customer journey). */
    public function visitorJourney(string $visitorId): array
    {
        $visitor = Visitor::where('visitor_id', $visitorId)->first();
        $events = AnalyticsEvent::where('visitor_id', $visitorId)
            ->orderByDesc('id')->limit(120)
            ->get(['type', 'url', 'label', 'value', 'created_at'])
            ->reverse()->values();

        return ['visitor' => $visitor, 'events' => $events];
    }

    /** Conversion funnel (distinct visitors per step) over the window. */
    public function funnel(int $days = 1): array
    {
        $since = Carbon::now()->subDays($days);
        $step = fn (string $type) => (int) AnalyticsEvent::where('created_at', '>=', $since)
            ->where('type', $type)->distinct('visitor_id')->count('visitor_id');

        $visitors = (int) Visitor::where('last_seen', '>=', $since)->count();
        $payment = $step('payment_complete');

        return [
            'visitors'         => $visitors,
            'product_views'    => $step('product_view'),
            'add_to_cart'      => $step('add_to_cart'),
            'checkout_start'   => $step('checkout_start'),
            'payment_complete' => $payment,
            'conversion_pct'   => $visitors > 0 ? round(($payment / $visitors) * 100, 2) : 0,
        ];
    }

    /**
     * Merged recent-activity stream for the NOC. Pulls from existing tables
     * (orders, signups) AND storefront behaviour events, normalised + time-sorted.
     */
    public function activityFeed(int $limit = 40): array
    {
        $items = collect();

        // Orders (always available).
        DB::table('orders')->whereNull('parent_id')->whereNull('deleted_at')
            ->select('id', 'tracking_number', 'order_status', 'payment_status', 'paid_total', 'created_at')
            ->orderByDesc('id')->limit(25)->get()
            ->each(function ($o) use ($items) {
                $items->push([
                    'stream'   => 'orders',
                    'title'    => "Order #{$o->tracking_number}",
                    'subtitle' => str_replace('order-', '', (string) $o->order_status),
                    'value'    => (float) $o->paid_total,
                    'ref_id'   => $o->id,
                    'time'     => $o->created_at,
                ]);
                if (in_array($o->payment_status, ['payment-success', 'payment-wallet'], true)) {
                    $items->push([
                        'stream'   => 'payments',
                        'title'    => "Payment received",
                        'subtitle' => "#{$o->tracking_number}",
                        'value'    => (float) $o->paid_total,
                        'ref_id'   => $o->id,
                        'time'     => $o->created_at,
                    ]);
                }
            });

        // Signups (customers + vendors) — recent users with their primary role.
        User::with('roles:id,name')->orderByDesc('id')->limit(12)->get()
            ->each(function (User $u) use ($items) {
                $role = optional($u->roles->first())->name;
                $stream = $role === Permission::STORE_OWNER ? 'vendors' : 'customers';
                $items->push([
                    'stream'   => $stream,
                    'title'    => $stream === 'vendors' ? 'New vendor' : 'New customer',
                    'subtitle' => $u->name ?? $u->email,
                    'ref_id'   => $u->id,
                    'time'     => $u->created_at,
                ]);
            });

        // Storefront behaviour (high-signal events) — present once tracking is live.
        AnalyticsEvent::whereIn('type', AnalyticsEvent::KEY_EVENTS)
            ->orderByDesc('id')->limit(25)->get()
            ->each(function (AnalyticsEvent $e) use ($items) {
                $items->push([
                    'stream'   => 'behaviour',
                    'title'    => $this->humanEvent($e->type),
                    'subtitle' => $e->label ?? $e->url,
                    'value'    => $e->value !== null ? (float) $e->value : null,
                    'ref_id'   => null,
                    'time'     => $e->created_at,
                ]);
            });

        return $items
            ->filter(fn ($i) => !empty($i['time']))
            ->sortByDesc(fn ($i) => Carbon::parse($i['time'])->timestamp)
            ->take($limit)
            ->values()
            ->all();
    }

    private function humanEvent(string $type): string
    {
        return [
            'add_to_cart'      => 'Added to cart',
            'checkout_start'   => 'Started checkout',
            'payment_start'    => 'Started payment',
            'payment_complete' => 'Completed payment',
            'order_placed'     => 'Placed order',
        ][$type] ?? ucfirst(str_replace('_', ' ', $type));
    }
}
