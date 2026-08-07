<?php

namespace Marvel\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Shop;
use Marvel\Services\EmailService;
use Marvel\Services\VendorKycService;

/**
 * The nightly KYC clock tick.
 *
 * Two passes over vendors that still owe documents:
 *  1. WARN — deadline inside the warning window and not warned yet: one email,
 *     stamped on kyc_reminded_at so the same vendor is never mailed twice.
 *  2. HOLD — deadline in the past: the account goes on hold (which also drops
 *     the vendor from availability/assignment — see VendorKycService::hold).
 *
 * Idempotent by construction: warned vendors are stamped, held vendors leave
 * the query (approval_status changes), so re-runs are no-ops.
 */
class SweepKycDeadlinesCommand extends Command
{
    protected $signature = 'marvel:sweep-kyc-deadlines
        {--dry-run : Report what would happen without changing anything}';

    protected $description = 'Warn vendors whose KYC deadline is near; put overdue vendors on hold';

    public function handle(VendorKycService $kyc, EmailService $email): int
    {
        if (!Schema::hasColumn('shops', 'documents_due_at')) {
            $this->warn('shops.documents_due_at missing — migration not applied; nothing to do.');
            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $now = Carbon::now();
        $warnFrom = $now->copy()->addDays($kyc->warnBeforeDays());

        // ── Pass 1: warnings ────────────────────────────────────────────────
        $toWarn = Shop::whereNotNull('documents_due_at')
            ->where('documents_due_at', '>', $now)
            ->where('documents_due_at', '<=', $warnFrom)
            ->whereNull('kyc_reminded_at')
            ->where('approval_status', '!=', Shop::STATUS_ON_HOLD)
            ->get();

        foreach ($toWarn as $shop) {
            $missing = $kyc->missingDocuments($shop);
            if (empty($missing)) {
                // Documents arrived but the clock was never cleared (e.g. a
                // direct DB write) — heal instead of nagging.
                if (!$dry) {
                    $kyc->syncDeadline($shop);
                    $shop->save();
                }
                continue;
            }

            $this->line(sprintf(
                '%s shop #%d "%s" — due %s, missing: %s',
                $dry ? '[dry] would warn' : 'warning',
                $shop->id,
                $shop->name,
                $shop->documents_due_at->toDateString(),
                implode(', ', $missing),
            ));

            if ($dry) {
                continue;
            }

            $to = $this->vendorEmail($shop);
            if ($to) {
                // EmailService never throws; a failed send just isn't stamped,
                // so the next sweep retries it.
                $sent = $email->send('vendor.kyc_deadline', $to, [
                    'shop_name' => $shop->name,
                    'due_date'  => $shop->documents_due_at->format('j F Y'),
                    'missing'   => implode(', ', $missing),
                ]);
                if ($sent) {
                    $shop->kyc_reminded_at = $now;
                    $shop->save();
                }
            } else {
                // No address at all — stamp anyway so the sweep doesn't spin
                // on an unmailable vendor every night.
                $shop->kyc_reminded_at = $now;
                $shop->save();
            }
        }

        // ── Pass 2: holds ───────────────────────────────────────────────────
        $overdue = Shop::whereNotNull('documents_due_at')
            ->where('documents_due_at', '<=', $now)
            ->where('approval_status', '!=', Shop::STATUS_ON_HOLD)
            ->get();

        foreach ($overdue as $shop) {
            $missing = $kyc->missingDocuments($shop);
            if (empty($missing)) {
                if (!$dry) {
                    $kyc->syncDeadline($shop);
                    $shop->save();
                }
                continue;
            }

            $this->line(sprintf(
                '%s shop #%d "%s" — was due %s, missing: %s',
                $dry ? '[dry] would hold' : 'holding',
                $shop->id,
                $shop->name,
                $shop->documents_due_at->toDateString(),
                implode(', ', $missing),
            ));

            if (!$dry) {
                $kyc->hold($shop, 'KYC documents not received by ' . $shop->documents_due_at->toDateString());
            }
        }

        $this->info(sprintf(
            'Sweep done: %d warned, %d held%s.',
            $toWarn->count(),
            $overdue->count(),
            $dry ? ' (dry run — nothing changed)' : '',
        ));

        return self::SUCCESS;
    }

    /** Best contact for the vendor: the owner's login email, else the shop's notification email. */
    private function vendorEmail(Shop $shop): ?string
    {
        $owner = $shop->owner_id ? \Marvel\Database\Models\User::find($shop->owner_id) : null;
        return $owner?->email
            ?: data_get($shop->settings, 'notifications.email')
            ?: null;
    }
}
