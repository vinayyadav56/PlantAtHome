<?php

namespace Marvel\Services\Tax;

use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\State;
use Marvel\Support\Money;

/**
 * THE authoritative GST engine. One place computes tax for the checkout preview,
 * the order charge, the snapshot, the invoice and reports — so they can never
 * disagree. Given the priced cart lines + delivery fee + the customer's shipping
 * state, it returns a full breakdown:
 *
 *   - per line: hsn, rate, category, taxable_value, cgst/sgst/igst rate+amount,
 *     tax_amount, tax_inclusive
 *   - order:    place_of_supply(+code), is_inter_state, taxable_amount,
 *     cgst/sgst/igst amounts, delivery treatment/taxable/tax, total_tax
 *   - tax_addon: the amount to ADD ON TOP of the subtotal for the payable total.
 *     It is 0 when prices are tax-inclusive (the default) — the GST is embedded
 *     in the price the customer already sees, so the total is unchanged and the
 *     tax figures are a breakdown, not a surcharge.
 *
 * CGST/SGST/IGST are DERIVED from the single GST rate + place of supply, never
 * stored per product. Money rounds through Money::round (half-up, 2dp).
 */
class GstService
{
    private BusinessTaxConfig $biz;

    public function __construct(?BusinessTaxConfig $biz = null)
    {
        $this->biz = $biz ?? new BusinessTaxConfig();
    }

    /**
     * @param array $lines each: product_id, variation_option_id?, order_quantity, unit_price, subtotal
     * @param float $deliveryFee the customer-facing delivery charge (already resolved)
     * @param array|null $shippingAddress the order's shipping_address (for place of supply)
     */
    public function compute(array $lines, float $deliveryFee, ?array $shippingAddress): array
    {
        $interState = $this->isInterState($shippingAddress);
        [$posName, $posCode] = $this->placeOfSupply($shippingAddress);
        $configs = $this->resolveConfigs($lines);

        $lineOut = [];
        $sumTaxable = 0.0;
        $sumTax = 0.0;
        $sumAddon = 0.0;
        $cgst = 0.0;
        $sgst = 0.0;
        $igst = 0.0;
        $weightedRateNum = 0.0; // Σ taxable × rate  (for the delivery principal rate)

        foreach ($lines as $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            $cfg = $configs[$pid] ?? $this->unconfigured();
            $gross = (float) ($line['subtotal'] ?? 0);
            if ($gross <= 0) {
                $gross = (float) ($line['unit_price'] ?? 0) * (int) ($line['order_quantity'] ?? 1);
            }
            $gross = Money::round($gross); // qty × unit price
            $rate = (float) $cfg['rate'];
            $inclusive = $cfg['inclusive'];

            if ($inclusive) {
                $taxable = Money::taxableFromInclusive($gross, $rate);
                $tax = Money::round($gross - $taxable);
                $addon = 0.0;
            } else {
                $taxable = $gross;
                $tax = Money::taxFromExclusive($gross, $rate);
                $addon = $tax;
            }

            [$lcg, $lsg, $lig] = $this->split($tax, $rate, $interState);
            $sumTaxable += $taxable;
            $sumTax += $tax;
            $sumAddon += $addon;
            $cgst += $lcg;
            $sgst += $lsg;
            $igst += $lig;
            $weightedRateNum += $taxable * $rate;

            $lineOut[] = [
                'product_id'          => $pid,
                'variation_option_id' => $line['variation_option_id'] ?? null,
                'hsn_code'            => $cfg['hsn'],
                'tax_category'        => $cfg['category'],
                'tax_rate'            => $rate,
                'tax_inclusive'       => $inclusive,
                'taxable_value'       => $taxable,
                'cgst_rate'           => $interState ? 0.0 : $rate / 2,
                'sgst_rate'           => $interState ? 0.0 : $rate / 2,
                'igst_rate'           => $interState ? $rate : 0.0,
                'cgst_amount'         => $lcg,
                'sgst_amount'         => $lsg,
                'igst_amount'         => $lig,
                'tax_amount'          => $tax,
            ];
        }

        // Delivery/freight tax.
        $treatment = $this->biz->deliveryTaxTreatment();
        $deliveryFee = Money::round($deliveryFee);
        $dRate = 0.0;
        if ($deliveryFee > 0 && $treatment !== 'exempt') {
            if ($treatment === 'separate') {
                $dRate = $this->biz->deliveryGstRate();
            } else { // follow_principal — weighted average of the goods' rates
                $dRate = $sumTaxable > 0 ? ($weightedRateNum / $sumTaxable) : 0.0;
            }
        }
        // Delivery follows the store's inclusive preference (the fee the customer sees).
        $deliveryInclusive = $this->biz->pricesIncludeTax();
        if ($dRate > 0) {
            if ($deliveryInclusive) {
                $dTaxable = Money::taxableFromInclusive($deliveryFee, $dRate);
                $dTax = Money::round($deliveryFee - $dTaxable);
                $sumAddon += 0.0;
            } else {
                $dTaxable = $deliveryFee;
                $dTax = Money::taxFromExclusive($deliveryFee, $dRate);
                $sumAddon += $dTax;
            }
        } else {
            $dTaxable = $deliveryFee;
            $dTax = 0.0;
        }
        [$dcg, $dsg, $dig] = $this->split($dTax, $dRate, $interState);
        $cgst += $dcg;
        $sgst += $dsg;
        $igst += $dig;

        $totalTax = Money::round($sumTax + $dTax);

        return [
            'is_inter_state'         => $interState,
            'place_of_supply'        => $posName,
            'place_of_supply_code'   => $posCode,
            'seller_state'           => $this->biz->registrationState(),
            'seller_state_code'      => $this->biz->registrationStateCode(),
            'seller_gstin'           => $this->biz->gstin(),
            'taxable_amount'         => Money::round($sumTaxable + $dTaxable),
            'cgst_amount'            => Money::round($cgst),
            'sgst_amount'            => Money::round($sgst),
            'igst_amount'            => Money::round($igst),
            'total_tax'              => $totalTax,
            'tax_addon'              => Money::round($sumAddon),
            'delivery_tax_treatment' => $treatment,
            'delivery_taxable'       => Money::round($dTaxable),
            'delivery_tax_amount'    => Money::round($dTax),
            'lines'                  => $lineOut,
        ];
    }

    /** The on-top tax add-on only — what the legacy paid_total formula adds (0 when inclusive). */
    public function taxAddon(array $lines, float $deliveryFee, ?array $shippingAddress): float
    {
        try {
            return (float) $this->compute($lines, $deliveryFee, $shippingAddress)['tax_addon'];
        } catch (\Throwable $e) {
            // Never let a tax bug break checkout — fall back to no add-on and log loudly.
            Log::error('GstService taxAddon failed; charging no add-on', ['error' => $e->getMessage()]);
            return 0.0;
        }
    }

    /**
     * Sum the immutable per-line tax snapshot across a set of order_items — the basis
     * for cancellation / refund tax reversal. Reads the stored figures verbatim; never
     * recomputes (historical tax must not move). Accepts models or plain arrays.
     *
     * @param  iterable $items rows carrying tax_amount/cgst_amount/sgst_amount/igst_amount/taxable_value
     * @return array{tax:float,cgst:float,sgst:float,igst:float,taxable:float}
     */
    public static function sumSnapshotTax(iterable $items): array
    {
        $get = function ($row, string $key): float {
            $v = is_array($row) ? ($row[$key] ?? 0) : ($row->{$key} ?? 0);
            return (float) $v;
        };
        $t = $c = $s = $i = $tv = 0.0;
        foreach ($items as $it) {
            $t  += $get($it, 'tax_amount');
            $c  += $get($it, 'cgst_amount');
            $s  += $get($it, 'sgst_amount');
            $i  += $get($it, 'igst_amount');
            $tv += $get($it, 'taxable_value');
        }
        return [
            'tax'     => round($t, 2),
            'cgst'    => round($c, 2),
            'sgst'    => round($s, 2),
            'igst'    => round($i, 2),
            'taxable' => round($tv, 2),
        ];
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** intra: cgst=sgst=tax/2 ; inter: igst=tax. Never both. */
    private function split(float $tax, float $rate, bool $interState): array
    {
        if ($tax <= 0 || $rate <= 0) {
            return [0.0, 0.0, 0.0];
        }
        if ($interState) {
            return [0.0, 0.0, Money::round($tax)];
        }
        $half = Money::round($tax / 2);
        // give any half-paisa remainder to CGST so cgst+sgst == tax exactly
        return [Money::round($tax - $half), $half, 0.0];
    }

    /** Batch-resolve each product's effective tax config (product → its tax class, else 0%/unverified). */
    private function resolveConfigs(array $lines): array
    {
        $ids = collect($lines)->pluck('product_id')->filter()->map(fn ($v) => (int) $v)->unique()->all();
        if (empty($ids)) {
            return [];
        }
        $storeInclusive = $this->biz->pricesIncludeTax();
        $products = Product::whereIn('id', $ids)->with('taxRate')->get(['id', 'hsn_code', 'tax_rate_id', 'tax_inclusive', 'is_taxable']);
        $out = [];
        foreach ($products as $p) {
            $tax = $p->taxRate; // Tax model (tax_classes row) or null
            $active = $tax && $this->isActive($tax);
            $rate = $active ? (float) $tax->rate : 0.0;
            $category = $active ? ($tax->tax_category ?: 'taxable') : 'non_taxable';
            $out[(int) $p->id] = [
                'rate'      => max(0.0, $rate),
                'hsn'       => $p->hsn_code ?: ($tax->hsn_code ?? null),
                'category'  => $category,
                'inclusive' => $p->tax_inclusive === null ? $storeInclusive : (bool) $p->tax_inclusive,
            ];
        }
        return $out;
    }

    private function unconfigured(): array
    {
        return ['rate' => 0.0, 'hsn' => null, 'category' => 'non_taxable', 'inclusive' => $this->biz->pricesIncludeTax()];
    }

    private function isActive($tax): bool
    {
        if (property_exists($tax, 'is_active') || isset($tax->is_active)) {
            if (!(bool) ($tax->is_active ?? true)) {
                return false;
            }
        }
        $today = now()->toDateString();
        if (!empty($tax->effective_from) && $today < substr((string) $tax->effective_from, 0, 10)) {
            return false;
        }
        if (!empty($tax->effective_to) && $today > substr((string) $tax->effective_to, 0, 10)) {
            return false;
        }
        return true;
    }

    /** Inter-state iff the shipping state differs from the seller's registration state. */
    private function isInterState(?array $shippingAddress): bool
    {
        $sellerCode = $this->biz->registrationStateCode();
        [, $custCode] = $this->placeOfSupply($shippingAddress);
        // Without a resolvable customer OR seller state we cannot claim inter-state;
        // default to INTRA (CGST+SGST) — the safer, more common case for a single-state seller.
        if (!$custCode || !$sellerCode) {
            return false;
        }
        return $custCode !== $sellerCode;
    }

    /** Resolve [stateName, stateCode] for the customer's shipping address. */
    private function placeOfSupply(?array $shippingAddress): array
    {
        $state = null;
        if (is_array($shippingAddress)) {
            // rg_state (server-verified) wins over the typed state when present.
            $state = $shippingAddress['rg_state'] ?? $shippingAddress['state'] ?? null;
        }
        $state = is_string($state) ? trim($state) : null;
        if (!$state) {
            return [null, null];
        }
        $norm = $this->normalizeState($state);
        $code = State::whereRaw('LOWER(name) = ?', [strtolower($norm)])->value('code');
        return [$norm, $code ? strtoupper($code) : null];
    }

    private function normalizeState(string $s): string
    {
        $s = trim($s);
        $l = strtolower($s);
        // Common aliases for the one union territory that appears many ways.
        if (in_array($l, ['nct of delhi', 'delhi ncr', 'new delhi', 'national capital territory of delhi'], true)) {
            return 'Delhi';
        }
        return $s;
    }
}
