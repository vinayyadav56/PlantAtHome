<?php

namespace Marvel\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Marvel\Database\Models\DeliveryPartner;
use Marvel\Database\Models\DeliveryPartnerBalance;
use Marvel\Database\Models\DeliveryPartnerEarning;

/**
 * Delivery-partner earnings analytics + manual incentives. Groups the earnings
 * ledger by day/week/month/quarter/half_year/custom with a commission-vs-incentive
 * breakup and running totals. DP sees their own; admin can view any partner + grant
 * manual incentives.
 */
class DeliveryPartnerEarningsController extends CoreController
{
    /** DP self: my earnings analytics. */
    public function myEarnings(Request $request)
    {
        $dp = DeliveryPartner::where('user_id', $request->user()->id)->first();
        if (!$dp) {
            return response()->json(['message' => 'No delivery-partner profile.'], 404);
        }
        return $this->analytics($dp, $request);
    }

    /** Admin: any partner's earnings analytics (delivery_partner_id required). */
    public function partnerEarnings(Request $request)
    {
        $dp = DeliveryPartner::findOrFail($request->input('delivery_partner_id'));
        return $this->analytics($dp, $request);
    }

    /** Admin: grant a manual incentive (bonus) to a partner. */
    public function grantIncentive(Request $request)
    {
        $request->validate([
            'delivery_partner_id' => 'required|integer',
            'amount'              => 'required|numeric|min:0.01',
            'note'                => 'nullable|string',
        ]);
        $dp = DeliveryPartner::findOrFail($request->input('delivery_partner_id'));
        $amount = (float) $request->input('amount');

        $balance = DeliveryPartnerBalance::firstOrCreate(['delivery_partner_id' => $dp->id]);
        $earning = DeliveryPartnerEarning::create([
            'delivery_partner_id' => $dp->id,
            'order_id'            => null,
            'type'               => 'incentive',
            'source'             => 'manual',
            'amount'             => $amount,
            'note'               => $request->input('note') ?: 'Manual incentive',
            'earned_at'          => $request->filled('earned_at') ? Carbon::parse($request->input('earned_at')) : now(),
        ]);
        $balance->total_earnings  = (float) $balance->total_earnings + $amount;
        $balance->current_balance = (float) $balance->current_balance + $amount;
        $balance->save();

        return $earning;
    }

    /** Group the ledger into period buckets + totals + accumulated balance. */
    private function analytics(DeliveryPartner $dp, Request $request): array
    {
        $group = $request->input('group', 'month');
        [$from, $to] = $this->range($group, $request);

        $rows = DeliveryPartnerEarning::where('delivery_partner_id', $dp->id)
            ->whereBetween('earned_at', [$from, $to])
            ->orderBy('earned_at')
            ->get(['type', 'amount', 'earned_at', 'order_id', 'source', 'note']);

        $buckets = [];
        $tot = ['commission' => 0.0, 'incentive' => 0.0, 'total' => 0.0, 'deliveries' => 0];
        foreach ($rows as $r) {
            [$key, $label, $start] = $this->bucket(Carbon::parse($r->earned_at), $group);
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['label' => $label, 'start' => $start, 'commission' => 0.0, 'incentive' => 0.0, 'total' => 0.0, 'deliveries' => 0];
            }
            $isComm = $r->type === 'commission';
            $buckets[$key][$isComm ? 'commission' : 'incentive'] += (float) $r->amount;
            $buckets[$key]['total'] += (float) $r->amount;
            $tot[$isComm ? 'commission' : 'incentive'] += (float) $r->amount;
            $tot['total'] += (float) $r->amount;
            if ($isComm && $r->amount > 0) {
                $buckets[$key]['deliveries']++;
                $tot['deliveries']++;
            }
        }

        $list = array_values($buckets);
        usort($list, fn ($a, $b) => strcmp($a['start'], $b['start']));
        foreach ($list as &$b) {
            $b['commission'] = round($b['commission'], 2);
            $b['incentive']  = round($b['incentive'], 2);
            $b['total']      = round($b['total'], 2);
        }
        unset($b);

        $balance = $dp->balance;
        return [
            'delivery_partner_id' => $dp->id,
            'group'   => $group,
            'from'    => $from->toDateString(),
            'to'      => $to->toDateString(),
            'buckets' => $list,
            'totals'  => [
                'commission' => round($tot['commission'], 2),
                'incentive'  => round($tot['incentive'], 2),
                'total'      => round($tot['total'], 2),
                'deliveries' => $tot['deliveries'],
            ],
            'accumulated' => [
                'total_earnings'  => (float) optional($balance)->total_earnings,
                'current_balance' => (float) optional($balance)->current_balance,
                'withdrawn'       => (float) optional($balance)->withdrawn_amount,
            ],
        ];
    }

    /** Default date window per grouping (overridable by from/to). */
    private function range(string $group, Request $request): array
    {
        if ($request->filled('from') && $request->filled('to')) {
            return [Carbon::parse($request->input('from'))->startOfDay(), Carbon::parse($request->input('to'))->endOfDay()];
        }
        $now = Carbon::now()->endOfDay();
        $from = match ($group) {
            'day'       => Carbon::now()->subDays(29)->startOfDay(),
            'week'      => Carbon::now()->subWeeks(11)->startOfWeek(),
            'quarter'   => Carbon::now()->subQuarters(7)->startOfQuarter(),
            'half_year' => Carbon::now()->subYears(3)->startOfYear(),
            'custom'    => Carbon::now()->subDays(29)->startOfDay(),
            default     => Carbon::now()->subMonths(11)->startOfMonth(), // month
        };
        return [$from, $now];
    }

    /** [bucketKey, displayLabel, sortableStart] for an earned_at in a grouping. */
    private function bucket(Carbon $dt, string $group): array
    {
        switch ($group) {
            case 'day':
            case 'custom':
                return [$dt->format('Y-m-d'), $dt->format('d M'), $dt->format('Y-m-d')];
            case 'week':
                $s = $dt->copy()->startOfWeek();
                return ['W' . $s->format('Y-m-d'), 'Wk ' . $s->isoWeek() . ' · ' . $s->format('d M'), $s->format('Y-m-d')];
            case 'quarter':
                $q = (int) ceil($dt->month / 3);
                $s = Carbon::create($dt->year, ($q - 1) * 3 + 1, 1);
                return [$dt->year . '-Q' . $q, 'Q' . $q . ' ' . $dt->year, $s->format('Y-m-d')];
            case 'half_year':
                $h = $dt->month <= 6 ? 1 : 2;
                $s = Carbon::create($dt->year, $h === 1 ? 1 : 7, 1);
                return [$dt->year . '-H' . $h, ($h === 1 ? 'Jan–Jun ' : 'Jul–Dec ') . $dt->year, $s->format('Y-m-d')];
            default: // month
                return [$dt->format('Y-m'), $dt->format('M Y'), $dt->format('Y-m-01')];
        }
    }
}
