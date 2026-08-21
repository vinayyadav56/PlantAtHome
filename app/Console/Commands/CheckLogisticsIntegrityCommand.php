<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The allocation invariant, checked against live data.
 *
 * For every assigned order line:
 *
 *     ordered quantity  ==  allocated to shipments  +  cancelled
 *
 * Allocation is spread across rows now (a line can ride two parcels), so nothing in the
 * schema can express this as a constraint — a UNIQUE or a CHECK sees one row at a time.
 * This is the safety net instead: it reports, never repairs, because every drift here has
 * a cause worth understanding rather than papering over.
 *
 * Reports, exits non-zero when anything is off, so it can be a cron alarm.
 */
class CheckLogisticsIntegrityCommand extends Command
{
    protected $signature = 'logistics:check-integrity {--limit=50 : rows to print per problem class}';

    protected $description = 'Verify shipment allocations reconcile against ordered quantities';

    public function handle(): int
    {
        if (!Schema::hasTable('shipment_items')) {
            $this->warn('shipment_items does not exist yet — nothing to check.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $problems = 0;

        $problems += $this->report(
            'Lines allocated to MORE units than were ordered',
            $this->overAllocated($limit),
            fn ($r) => "order_item {$r->id} (order {$r->order_id}): ordered {$r->order_quantity}, allocated {$r->allocated}",
            $this->overAllocatedCount(),
        );

        $problems += $this->report(
            'Assigned lines allocated to FEWER units than were ordered',
            $this->underAllocated($limit),
            fn ($r) => "order_item {$r->id} (order {$r->order_id}): ordered {$r->order_quantity}, allocated {$r->allocated}",
            $this->underAllocatedCount(),
        );

        $problems += $this->report(
            'Allocations pointing at a shipment that no longer exists',
            $this->orphanAllocations($limit),
            fn ($r) => "shipment_item {$r->id} -> missing shipment {$r->shipment_id}",
        );

        $problems += $this->report(
            'Shipments carrying nothing',
            $this->emptyShipments($limit),
            fn ($r) => "shipment {$r->id} (order {$r->order_id}, status {$r->status}) has no allocations",
        );

        if ($problems === 0) {
            $this->info('Allocations reconcile.');

            return self::SUCCESS;
        }

        $this->error("{$problems} integrity problem(s) found.");

        return self::FAILURE;
    }

    /**
     * @param  callable(object):string $line
     * @param  int|null $total the real number of affected rows, when it exceeds what was fetched
     */
    private function report(string $title, $rows, callable $line, ?int $total = null): int
    {
        if ($rows->isEmpty()) {
            return 0;
        }
        $total = $total ?? $rows->count();
        $this->newLine();
        // The header states the TRUE total; the list below is a page of it. Reporting the
        // page size made a five-figure corruption read as "50 problems".
        $this->error("{$title}: {$total}");
        foreach ($rows as $row) {
            $this->line('  ' . $line($row));
        }
        if ($total > $rows->count()) {
            $this->line('  … ' . ($total - $rows->count()) . ' more (raise --limit to list them)');
        }

        return $total;
    }

    private function allocatedExpr(): string
    {
        return '(select coalesce(sum(si.quantity), 0) from shipment_items si where si.order_item_id = order_items.id)';
    }

    private function cancelledExpr(): string
    {
        // The column is recent; on a box mid-migration treat it as zero rather than failing.
        return Schema::hasColumn('order_items', 'cancelled_quantity')
            ? 'coalesce(order_items.cancelled_quantity, 0)'
            : '0';
    }

    private function overAllocatedCount(): int
    {
        return (int) DB::table('order_items')
            ->whereRaw("{$this->allocatedExpr()} + {$this->cancelledExpr()} > order_items.order_quantity")
            ->count();
    }

    private function underAllocatedCount(): int
    {
        return (int) DB::table('order_items')
            ->whereNotNull('order_items.assigned_shop_id')
            ->whereNotIn('order_items.item_status', ['cancelled', 'refunded'])
            ->whereRaw("{$this->allocatedExpr()} + {$this->cancelledExpr()} < order_items.order_quantity")
            ->count();
    }

    private function overAllocated(int $limit)
    {
        return DB::table('order_items')
            ->selectRaw("order_items.id, order_items.order_id, order_items.order_quantity, {$this->allocatedExpr()} as allocated")
            ->whereRaw("{$this->allocatedExpr()} + {$this->cancelledExpr()} > order_items.order_quantity")
            ->limit($limit)->get();
    }

    private function underAllocated(int $limit)
    {
        // Only ASSIGNED lines: an unassigned line legitimately has no parcel yet, and a
        // cancelled one has been accounted for.
        return DB::table('order_items')
            ->selectRaw("order_items.id, order_items.order_id, order_items.order_quantity, {$this->allocatedExpr()} as allocated")
            ->whereNotNull('order_items.assigned_shop_id')
            ->whereNotIn('order_items.item_status', ['cancelled', 'refunded'])
            ->whereRaw("{$this->allocatedExpr()} + {$this->cancelledExpr()} < order_items.order_quantity")
            ->limit($limit)->get();
    }

    private function orphanAllocations(int $limit)
    {
        return DB::table('shipment_items')
            ->select('shipment_items.id', 'shipment_items.shipment_id')
            ->leftJoin('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->whereNull('shipments.id')
            ->limit($limit)->get();
    }

    /**
     * Parcels carrying nothing — but only ones that SHOULD carry something.
     *
     * Scoped deliberately. The backfill only created allocations where an order line already
     * pointed at a shipment, so every historical parcel from before the ledger existed has
     * none and would be reported forever, leaving this alarm permanently red and therefore
     * ignored. Terminal parcels are excluded for the same reason: whatever they carried has
     * already happened and is not actionable.
     */
    private function emptyShipments(int $limit)
    {
        $ledgerStart = DB::table('shipment_items')->min('created_at');

        return DB::table('shipments')
            ->select('shipments.id', 'shipments.order_id', 'shipments.status')
            ->whereIn('shipments.status', ['pending', 'assigned', 'packed'])
            ->when($ledgerStart, fn ($q) => $q->where('shipments.created_at', '>=', $ledgerStart))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('shipment_items')
                ->whereColumn('shipment_items.shipment_id', 'shipments.id'))
            ->limit($limit)->get();
    }
}
