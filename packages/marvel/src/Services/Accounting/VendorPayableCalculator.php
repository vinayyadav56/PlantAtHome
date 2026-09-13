<?php

namespace Marvel\Services\Accounting;

use App\Shared\Domain\ValueObject\Money;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\OrderItem;

/**
 * THE vendor payable formula (spec §10) — every journal line, ledger row and settlement
 * figure for a vendor's share of an order line comes from here, and the result is frozen
 * onto the line at recognition (spec §11, §13) so later rule changes never move history.
 *
 *  cost_sheet (D1 default): payable = vendor_price_snapshot × qty − vendor-funded discount − deductions
 *  commission:              payable = taxable_value − commission(rate) − vendor-funded discount − deductions
 *
 * Commission rate today = balances.admin_commission_rate for the shop (the one existing
 * store); the rules table (vendor/category/product, % / fixed) plugs into rateFor() in P5.
 */
class VendorPayableCalculator
{
    public const COST_SHEET = 'cost_sheet';
    public const COMMISSION = 'commission';

    /** @return array{mode:string, gross:Money, base:Money, commission_rate:string, commission:Money, vendor_discount:Money, deductions:Money, payable:Money} */
    public function forItem(OrderItem $item, ?string $shopMode = null, array $deductions = []): array
    {
        $qty = max(1, (int) $item->order_quantity);
        $mode = $shopMode ?: self::COST_SHEET;
        $vendorDiscount = ($item->discount_funded_by ?? null) === 'vendor'
            ? MoneyBridge::toMoney($item->discount_amount ?? 0) : MoneyBridge::zero();
        $ded = MoneyBridge::sum(array_values($deductions));

        if ($mode === self::COMMISSION) {
            $base = MoneyBridge::toMoney($item->taxable_value ?? $item->subtotal ?? 0);
            $rate = $this->rateFor((int) $item->assigned_shop_id);
            $commission = MoneyBridge::percent($base, $rate);
            $payable = $base->subtract($commission)->subtract($vendorDiscount)->subtract($ded);
            return ['mode' => $mode, 'gross' => $base, 'base' => $base, 'commission_rate' => $rate,
                'commission' => $commission, 'vendor_discount' => $vendorDiscount, 'deductions' => $ded,
                'payable' => $payable->isNegative() ? MoneyBridge::zero() : $payable];
        }

        $gross = MoneyBridge::toMoney($item->vendor_price_snapshot ?? 0)->multiply($qty);
        $payable = $gross->subtract($vendorDiscount)->subtract($ded);
        return ['mode' => self::COST_SHEET, 'gross' => $gross, 'base' => $gross, 'commission_rate' => '0.0000',
            'commission' => MoneyBridge::zero(), 'vendor_discount' => $vendorDiscount, 'deductions' => $ded,
            'payable' => $payable->isNegative() ? MoneyBridge::zero() : $payable];
    }

    /** Commission % for a shop: legacy balances.admin_commission_rate (P5: vendor_commission_rules). */
    public function rateFor(int $shopId): string
    {
        try {
            $r = DB::table('balances')->where('shop_id', $shopId)->value('admin_commission_rate');
            return number_format((float) ($r ?? 0), 4, '.', '');
        } catch (\Throwable $e) {
            return '0.0000';
        }
    }

    /** The snapshot written onto order_items at recognition. */
    public static function snapshot(array $calc): array
    {
        return [
            'commission_mode_snapshot'   => $calc['mode'],
            'commission_rate_snapshot'   => $calc['commission_rate'],
            'commission_amount_snapshot' => $calc['commission']->toDecimal(),
            'vendor_payable_snapshot'    => $calc['payable']->toDecimal(),
        ];
    }
}
