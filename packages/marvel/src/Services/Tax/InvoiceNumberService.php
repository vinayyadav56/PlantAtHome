<?php

namespace Marvel\Services\Tax;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Accounting\AccountingSequence;
use Marvel\Database\Models\Order;

/**
 * Mints the tax-invoice number for an order, once.
 *
 * Format INV-2627-000001: prefix from Settings, then the FINANCIAL year (India
 * runs April-March, so a calendar year would split every year's series in the
 * middle), then a gapless counter per year from acc_sequences — the same
 * row-locked counter behind journal entries and credit notes. Gapless matters:
 * a missing number in an invoice series is something a tax officer asks about.
 *
 * Minted when the order becomes PAYABLE (payment captured, or COD accepted),
 * never at creation: an abandoned or failed checkout that burned a number would
 * leave exactly that hole. A number, once given, is never reissued or changed.
 */
class InvoiceNumberService
{
    /** Payment states that mean "this order is real money now". */
    private const PAYABLE = [
        'payment-success',
        'payment-cash-on-delivery',
        'cash-on-delivery',
        'cash',
        'wallet',
    ];

    public static function shouldStamp(Order $order): bool
    {
        return in_array((string) $order->payment_status, self::PAYABLE, true);
    }

    /**
     * Give $order a number if it is payable and has none. Idempotent, and never
     * fatal — an order must not fail to save because a counter was busy.
     */
    public static function stamp(Order $order): void
    {
        try {
            if (!self::available() || !self::shouldStamp($order) || !empty($order->invoice_number)) {
                return;
            }

            $year = self::financialYear();
            $prefix = (new BusinessTaxConfig())->invoicePrefix() ?: 'INV';
            $number = sprintf('%s-%s-%06d', $prefix, $year, AccountingSequence::next('invoice:' . $year));

            // saveQuietly: this runs INSIDE the model's own updated hook, and a
            // normal save would re-enter it.
            $order->forceFill([
                'invoice_number' => $number,
                'invoice_date'   => $order->invoice_date ?? now(),
            ])->saveQuietly();
        } catch (\Throwable $e) {
            Log::error('invoice number not stamped', ['order' => $order->id ?? null, 'error' => $e->getMessage()]);
        }
    }

    /** Indian financial year as 2627 for 2026-27 (April start). */
    public static function financialYear(?\DateTimeInterface $at = null): string
    {
        $date = $at ? \Illuminate\Support\Carbon::instance($at) : now();
        $startYear = $date->month >= 4 ? $date->year : $date->year - 1;

        return substr((string) $startYear, -2) . substr((string) ($startYear + 1), -2);
    }

    /**
     * Columns and the counter table both arrive with migrations, which deploys
     * run AFTER the new code is already serving.
     *
     * Deliberately not memoised in a static. A worker that asked during that
     * window would cache "no" for its whole life and quietly stop numbering
     * invoices until it recycled — and a gap in an invoice series is the one
     * thing this feature exists to prevent. It is asked once per payable order
     * transition, which is nothing.
     */
    private static function available(): bool
    {
        try {
            return Schema::hasColumn('orders', 'invoice_number') && Schema::hasTable('acc_sequences');
        } catch (\Throwable $e) {
            return false;
        }
    }
}
