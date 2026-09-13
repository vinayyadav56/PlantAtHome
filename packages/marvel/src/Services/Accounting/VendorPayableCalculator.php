<?php

namespace Marvel\Services\Accounting;

use App\Shared\Domain\ValueObject\Money;
use Marvel\Database\Models\OrderItem;

/**
 * THE vendor payable formula (spec §10) — every journal line, ledger row and settlement
 * figure for a vendor's share of an order line comes from here, and the result is frozen
 * onto the line at recognition (spec §11, §13) so later rule changes never move history.
 *
 *  cost_sheet (D1 default): payable = vendor_price_snapshot × qty − vendor-funded discount − deductions
 *  percentage:              payable = taxable_value − taxable_value × rate% − vendor-funded discount − deductions
 *  fixed_per_item:          commission = value × qty
 *  fixed_per_order:         commission = value once per (order, vendor), allocated across that vendor's lines by base
 *
 * The rule comes from VendorCommissionRuleResolver (product > category > vendor > shop mode).
 */
class VendorPayableCalculator
{
    public const COST_SHEET = 'cost_sheet';
    public const COMMISSION = 'commission';

    public function __construct(private readonly VendorCommissionRuleResolver $resolver = new VendorCommissionRuleResolver())
    {
    }

    /**
     * Calculate every line of an order at once (fixed_per_order needs the vendor's lines together).
     * @param iterable<OrderItem> $items
     * @param array<int, array{recognition_mode?:string, commission_mode?:string}> $shopModes keyed by shop id
     * @param array<int, array<string, Money|string>> $deductions per order_item_id
     * @return array<int, array> calc per order_item_id (see forItem)
     */
    public function forLines(iterable $items, array $shopModes = [], array $deductions = []): array
    {
        $out = [];
        $perOrderShare = []; // shop_id => [item_id => Money] for fixed_per_order rules
        $rules = [];
        foreach ($items as $it) {
            if (!$it->assigned_shop_id) {
                continue;
            }
            $rule = $this->resolver->resolve((int) $it->assigned_shop_id, $it->product_id ? (int) $it->product_id : null, $this->resolver->categoryIdsFor($it->product_id ? (int) $it->product_id : null), $shopModes[$it->assigned_shop_id]['commission_mode'] ?? null);
            $rules[$it->id] = $rule;
        }
        // allocate each vendor's fixed-per-order commission across their lines by base (largest remainder)
        $byShopRule = [];
        foreach ($items as $it) {
            $r = $rules[$it->id] ?? null;
            if ($r && $r['mode'] === VendorCommissionRuleResolver::FIXED_PER_ORDER) {
                $byShopRule[$it->assigned_shop_id . ':' . ($r['rule_id'] ?? 0)][$it->id] = MoneyBridge::toMoney($it->taxable_value ?? $it->subtotal ?? 0)->amountMinor();
            }
        }
        foreach ($byShopRule as $k => $weights) {
            $firstItem = array_key_first($weights);
            $value = MoneyBridge::toMoney($rules[$firstItem]['value']);
            foreach (MoneyBridge::allocate($value, $weights) as $itemId => $share) {
                $perOrderShare[$itemId] = $share;
            }
        }
        foreach ($items as $it) {
            if (!isset($rules[$it->id])) {
                continue;
            }
            $out[$it->id] = $this->forItem($it, $shopModes[$it->assigned_shop_id]['commission_mode'] ?? null, $deductions[$it->id] ?? [], $rules[$it->id], $perOrderShare[$it->id] ?? null);
        }
        return $out;
    }

    /** @return array{mode:string, gross:Money, base:Money, commission_rate:string, commission:Money, vendor_discount:Money, deductions:Money, payable:Money, rule_id:?int, scope:string} */
    public function forItem(OrderItem $item, ?string $shopMode = null, array $deductions = [], ?array $rule = null, ?Money $fixedShare = null): array
    {
        $qty = max(1, (int) $item->order_quantity);
        $rule = $rule ?: $this->resolver->resolve((int) $item->assigned_shop_id, $item->product_id ? (int) $item->product_id : null, $this->resolver->categoryIdsFor($item->product_id ? (int) $item->product_id : null), $shopMode);
        $vendorDiscount = ($item->discount_funded_by ?? null) === 'vendor' ? MoneyBridge::toMoney($item->discount_amount ?? 0) : MoneyBridge::zero();
        $ded = MoneyBridge::sum(array_values($deductions));
        $meta = ['rule_id' => $rule['rule_id'], 'scope' => $rule['scope'], 'vendor_discount' => $vendorDiscount, 'deductions' => $ded];

        if ($rule['mode'] === VendorCommissionRuleResolver::COST_SHEET) {
            $gross = MoneyBridge::toMoney($item->vendor_price_snapshot ?? 0)->multiply($qty);
            $payable = $gross->subtract($vendorDiscount)->subtract($ded);
            return ['mode' => self::COST_SHEET, 'gross' => $gross, 'base' => $gross, 'commission_rate' => '0.0000', 'commission' => MoneyBridge::zero(), 'payable' => $this->floor($payable)] + $meta;
        }
        $base = MoneyBridge::toMoney($item->taxable_value ?? $item->subtotal ?? 0);
        $commission = match ($rule['mode']) {
            VendorCommissionRuleResolver::PERCENTAGE      => MoneyBridge::percent($base, $rule['value']),
            VendorCommissionRuleResolver::FIXED_PER_ITEM  => MoneyBridge::toMoney($rule['value'])->multiply($qty),
            VendorCommissionRuleResolver::FIXED_PER_ORDER => $fixedShare ?? MoneyBridge::toMoney($rule['value']),
            default                                       => MoneyBridge::zero(),
        };
        $payable = $base->subtract($commission)->subtract($vendorDiscount)->subtract($ded);
        return ['mode' => $rule['mode'], 'gross' => $base, 'base' => $base, 'commission_rate' => $rule['value'], 'commission' => $commission, 'payable' => $this->floor($payable)] + $meta;
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

    private function floor(Money $m): Money
    {
        return $m->isNegative() ? MoneyBridge::zero() : $m;
    }
}
