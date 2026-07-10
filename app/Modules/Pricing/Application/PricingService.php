<?php

namespace App\Modules\Pricing\Application;

use App\Modules\Pricing\Infrastructure\Models\BasePrice;
use App\Modules\Pricing\Infrastructure\Models\TaxRule;
use App\Modules\Pricing\Infrastructure\Models\VendorOverride;
use App\Modules\Promotions\Application\PromotionService;
use App\Modules\Rules\Application\RulesEngine;
use App\Modules\Rules\Domain\ActionType;
use App\Modules\Rules\Domain\RuleContext;
use App\Modules\Rules\Domain\RuleScope;
use App\Shared\Domain\ValueObject\Money;
use Illuminate\Support\Facades\Schema;

/**
 * The Pricing Engine (Section 7). Computes the authoritative price of a
 * configured line server-side, in a fixed, deterministic order. The client's
 * posted amount is NEVER read — priceLine takes only identifiers + qty, so a
 * tampered price is structurally impossible to inject. Returns (and Phase 8
 * persists) the FULL breakdown so invoices are reproducible and GST-auditable.
 *
 * Order: vendorOverride ?? base (variant) + Σ options → subtotal×qty →
 * Rules(PRICING) discounts/free → promotions (Phase 10 stub) → taxable → GST →
 * total.
 */
class PricingService
{
    private ?bool $rulesReady = null;
    private ?bool $promoReady = null;

    public function __construct(
        private readonly RulesEngine $rules,
        private readonly PromotionService $promotions,
    ) {
    }

    /**
     * @param array{
     *   variant_uuid:string, nursery_id:string, qty?:int, currency?:string,
     *   category_uuid?:string, city?:string,
     *   options?:array<int,string>,               // selected option uuids
     *   facts?:array<string,mixed>                // extra rule facts (category slug, size, …)
     * } $req
     * @return array the full price breakdown
     */
    public function priceLine(array $req): array
    {
        $currency = $req['currency'] ?? 'INR';
        $qty = max(1, (int) ($req['qty'] ?? 1));
        $nursery = (string) $req['nursery_id'];

        // 1 + 2: resolved unit prices (vendor override falls back to base).
        $variantPrice = $this->resolvePrice('variant', $req['variant_uuid'], $nursery, $currency);
        $optionLines = [];
        $optionsPrice = Money::zero($currency);
        foreach ($req['options'] ?? [] as $optionUuid) {
            $price = $this->resolvePrice('option', $optionUuid, $nursery, $currency);
            $optionsPrice = $optionsPrice->add($price);
            $optionLines[] = ['uuid' => $optionUuid, 'price' => $this->present($price)];
        }

        // 3: line subtotal.
        $unitSubtotal = $variantPrice->add($optionsPrice);
        $subtotal = $unitSubtotal->multiply($qty);

        // 4: data-driven pricing rules (discounts / conditional-free).
        [$discountTotal, $discounts] = $this->applyPricingRules($subtotal, $req, $optionLines, $qty, $currency);

        // 5: promotions — apply a coupon against the subtotal (Section 7 step 5).
        if (! empty($req['coupon']) && $this->promoAvailable()) {
            $eval = $this->promotions->evaluate((string) $req['coupon'], $subtotal->amountMinor(), $req['customer_uuid'] ?? null);
            if ($eval['valid'] && $eval['discount_minor'] > 0) {
                $couponDiscount = Money::fromMinor($eval['discount_minor'], $currency);
                $discountTotal = $discountTotal->add($couponDiscount);
                $discounts[] = ['type' => 'coupon', 'code' => $eval['code'], 'amount' => $this->present($couponDiscount)];
            }
        }

        // 6: taxable = subtotal − discounts (never negative).
        $taxable = $subtotal->subtract($discountTotal);
        if ($taxable->isNegative()) {
            $taxable = Money::zero($currency);
        }

        // 7: GST.
        $gstRate = $this->taxRate($req['category_uuid'] ?? null);
        $gst = $taxable->multiply($gstRate / 100);

        // 8: total.
        $total = $taxable->add($gst);

        return [
            'currency' => $currency,
            'qty'      => $qty,
            'unit'     => [
                'variant_price' => $this->present($variantPrice),
                'options'       => $optionLines,
                'unit_subtotal' => $this->present($unitSubtotal),
            ],
            'subtotal'       => $this->present($subtotal),
            'discounts'      => $discounts,
            'discount_total' => $this->present($discountTotal),
            'taxable'        => $this->present($taxable),
            'gst_rate'       => $gstRate,
            'gst'            => $this->present($gst),
            'total'          => $this->present($total),
        ];
    }

    /** Price several lines and sum them (a mini-cart; real cart delivery in Phase 8). */
    public function priceLines(array $lines): array
    {
        $priced = array_map(fn ($l) => $this->priceLine($l), $lines);
        $currency = $priced[0]['currency'] ?? 'INR';

        $grand = Money::zero($currency);
        foreach ($priced as $p) {
            $grand = $grand->add(Money::fromMinor($p['total']['amount_minor'], $currency));
        }

        return ['lines' => $priced, 'grand_total' => $this->present($grand)];
    }

    /* ── admin setters ─────────────────────────────────────────────────────── */

    public function setBasePrice(string $type, string $sellableUuid, float $amount, string $currency, ?string $actor): BasePrice
    {
        return BasePrice::updateOrCreate(
            ['sellable_type' => $type, 'sellable_uuid' => $sellableUuid],
            ['amount' => $amount, 'currency' => $currency, 'updated_by' => $actor],
        );
    }

    public function setVendorOverride(string $nurseryId, string $type, string $sellableUuid, float $amount, string $currency, ?string $actor): VendorOverride
    {
        return VendorOverride::updateOrCreate(
            ['nursery_id' => $nurseryId, 'sellable_type' => $type, 'sellable_uuid' => $sellableUuid],
            ['amount' => $amount, 'currency' => $currency, 'updated_by' => $actor],
        );
    }

    public function setTaxRule(?string $categoryUuid, ?string $hsn, float $gstRate, ?string $name): TaxRule
    {
        return TaxRule::updateOrCreate(
            ['category_uuid' => $categoryUuid, 'hsn_code' => $hsn],
            ['gst_rate' => $gstRate, 'name' => $name],
        );
    }

    /* ── internals ─────────────────────────────────────────────────────────── */

    /** vendorOverride(nursery) ?? base ?? 0. */
    private function resolvePrice(string $type, string $sellableUuid, string $nurseryId, string $currency): Money
    {
        $override = VendorOverride::where('nursery_id', $nurseryId)
            ->where('sellable_type', $type)->where('sellable_uuid', $sellableUuid)->first();
        if ($override) {
            return Money::fromDecimal((float) $override->amount, $override->currency ?: $currency);
        }

        $base = BasePrice::where('sellable_type', $type)->where('sellable_uuid', $sellableUuid)->first();
        if ($base) {
            return Money::fromDecimal((float) $base->amount, $base->currency ?: $currency);
        }

        return Money::zero($currency);
    }

    /**
     * @return array{0:Money,1:array} discountTotal + itemized discounts
     */
    private function applyPricingRules(Money $subtotal, array $req, array $optionLines, int $qty, string $currency): array
    {
        $discountTotal = Money::zero($currency);
        $discounts = [];

        if (! $this->rulesAvailable()) {
            return [$discountTotal, $discounts];
        }

        $ctx = new RuleContext(array_merge($req['facts'] ?? [], [
            'cart'      => ['total' => (int) round($subtotal->amountMinor() / 100)],
            'nursery'   => ['id' => $req['nursery_id']],
            'city'      => ['id' => $req['city'] ?? null],
            'selection' => ['options' => $req['options'] ?? []],
        ]));

        $outcome = $this->rules->evaluate(RuleScope::PRICING, $ctx);

        // apply_discount — percentage or fixed off the subtotal.
        foreach ($outcome->ofType(ActionType::APPLY_DISCOUNT) as $action) {
            $p = $action['params'] ?? [];
            $kind = $p['kind'] ?? 'fixed';
            $amount = $kind === 'percentage'
                ? $subtotal->multiply(((float) ($p['value'] ?? 0)) / 100)
                : Money::fromDecimal((float) ($p['value'] ?? 0), $currency);
            $discountTotal = $discountTotal->add($amount);
            $discounts[] = ['rule_uuid' => $action['rule_uuid'], 'type' => 'apply_discount', 'amount' => $this->present($amount)];
        }

        // set_free — a matched option becomes free (subtract its line price).
        foreach ($outcome->ofType(ActionType::SET_FREE) as $action) {
            $p = $action['params'] ?? [];
            $ref = $p['option'] ?? $p['code'] ?? null;
            foreach ($optionLines as $line) {
                if ($ref !== null && $line['uuid'] === $ref) {
                    $free = Money::fromMinor($line['price']['amount_minor'], $currency)->multiply($qty);
                    $discountTotal = $discountTotal->add($free);
                    $discounts[] = ['rule_uuid' => $action['rule_uuid'], 'type' => 'set_free', 'amount' => $this->present($free)];
                }
            }
        }

        return [$discountTotal, $discounts];
    }

    private function taxRate(?string $categoryUuid): float
    {
        if ($categoryUuid) {
            $rule = TaxRule::where('category_uuid', $categoryUuid)->first();
            if ($rule) {
                return (float) $rule->gst_rate;
            }
        }
        // Fall back to a default rule (category_uuid null).
        $default = TaxRule::whereNull('category_uuid')->whereNull('hsn_code')->first();

        return $default ? (float) $default->gst_rate : 0.0;
    }

    private function present(Money $money): array
    {
        return [
            'amount_minor' => $money->amountMinor(),
            'currency'     => $money->currency(),
            'amount'       => $money->toDecimal(),
        ];
    }

    private function rulesAvailable(): bool
    {
        if ($this->rulesReady === null) {
            $this->rulesReady = Schema::hasTable('rule_definitions');
        }

        return $this->rulesReady;
    }

    private function promoAvailable(): bool
    {
        if ($this->promoReady === null) {
            $this->promoReady = Schema::hasTable('promo_coupons');
        }

        return $this->promoReady;
    }
}
