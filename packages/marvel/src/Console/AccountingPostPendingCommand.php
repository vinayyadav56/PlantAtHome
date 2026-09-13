<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Order;
use Marvel\Enums\OrderStatus;
use Marvel\Services\Accounting\AccountingPostingService;

/**
 * Recoverable backstop for spec §45: any COMPLETED parent order that has no recognition
 * journal (a posting failed in non-strict mode, or the order completed before the
 * accounting switch) is recognised here. Idempotent — the journal source_key makes a
 * re-run a no-op. Chunked, bounded, dry-run capable (house sweep-command shape).
 */
class AccountingPostPendingCommand extends Command
{
    protected $signature = 'accounting:post-pending
        {--dry-run : List what would be posted; write nothing}
        {--limit=200 : Max orders per run}
        {--since= : Only orders completed on/after this date (default: accounting cutover date)}';

    protected $description = 'Post recognition journals for completed orders that have none (idempotent sweep)';

    public function handle(): int
    {
        $svc = AccountingPostingService::make();
        if (!$svc->enabled()) {
            $this->info('accounting disabled — nothing to do');
            return self::SUCCESS;
        }
        $since = $this->option('since') ?: $svc->config()->cutoverDate();
        $q = Order::whereNull('parent_id')
            ->where('order_status', OrderStatus::COMPLETED)
            ->where(fn ($w) => $w->whereNull('financial_status')->orWhere('financial_status', 'unrecognized'))
            ->orderBy('id');
        if ($since) {
            $q->where('updated_at', '>=', $since);
        }
        $orders = $q->limit(max(1, (int) $this->option('limit')))->get();
        $dry = (bool) $this->option('dry-run');
        $n = ['posted' => 0, 'flagged' => 0, 'errors' => 0];
        foreach ($orders as $order) {
            if ($dry) {
                $this->line("  would recognise order #{$order->id} ({$order->tracking_number})");
                continue;
            }
            try {
                $je = $svc->recognizeOrder($order, 'system:post-pending');
                $fresh = $order->fresh();
                if ($fresh->financial_status === 'requires_reconciliation') {
                    $n['flagged']++;
                    $this->warn("  order #{$order->id} flagged requires_reconciliation");
                } else {
                    $n['posted']++;
                    $this->info("  order #{$order->id} → " . ($je?->entry_number ?? 'no-op'));
                }
            } catch (\Throwable $e) {
                $n['errors']++;
                Log::error('accounting:post-pending failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                $this->error("  order #{$order->id} failed: {$e->getMessage()}");
            }
        }
        $this->info(sprintf('%schecked %d · posted %d · flagged %d · errors %d', $dry ? '[DRY-RUN] ' : '', $orders->count(), $n['posted'], $n['flagged'], $n['errors']));
        return self::SUCCESS;
    }
}
