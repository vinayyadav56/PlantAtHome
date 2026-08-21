<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Shipment;
use Marvel\Services\Courier\CourierService;

/**
 * What a proposed parcel WEIGHS and MEASURES, and — when nothing can carry it — how it
 * could be broken up.
 *
 * Deliberately provider-independent. The decision "should these items travel together" is
 * PlantAtHome's; a provider only answers "can this be carried", which it already does via
 * the quote response's `ineligible` / `failed` lists. So there is no copy of anyone's
 * vehicle-capacity table in here: the capacity a split is planned against is passed IN,
 * taken from whatever the partner reported on its own quote.
 */
class ShipmentPlanner
{
    /** Couriers give up long before this; it exists so a missing capacity never means "infinite". */
    private const FALLBACK_CAPACITY_KG = 20.0;

    /**
     * The per-unit weight booking assumes for a product with none. Hardcoded to match
     * ShippingServiceClient::weightG() — this class predicts what that method sends, so the two
     * must not drift, and weightG does NOT read the configurable default_package weight.
     */
    private const BOOKING_UNIT_WEIGHT_G = 500;

    public function __construct(private ?CourierService $courier = null)
    {
        $this->courier = $courier ?: new CourierService();
    }

    /**
     * Totals for a parcel, derived EXACTLY the way booking derives them.
     *
     * This number's only job is to predict what the courier will be quoted and billed on, so it
     * mirrors ShippingServiceClient::weightG() and packageDims() term for term. Three ways it
     * used to disagree, each of which showed the operator a parcel that would never be sent:
     * it ignored the operator's own dimension override (booking prefers it), it fell back to
     * defaults PER AXIS where booking falls back all-or-nothing, and it took the per-unit weight
     * fallback from the admin setting where booking hardcodes 500 g.
     *
     * @return array{units:int, weight_g:int, length_cm:float, breadth_cm:float, height_cm:float, packages:int, estimated:bool}
     */
    public function summarize(Shipment $shipment): array
    {
        $shipment->loadMissing('items.product');

        $units = 0;
        $weight = 0;
        $anyRealWeight = false;

        foreach ($shipment->items as $item) {
            $qty = $item->shipped_qty;
            $units += $qty;

            $product = $item->product ?? null;
            $hasWeight = $product && (int) ($product->weight ?? 0) > 0;
            $anyRealWeight = $anyRealWeight || $hasWeight;
            // 500, not the configured default: this is what weightG() actually sends.
            $weight += ($hasWeight ? (int) $product->weight : self::BOOKING_UNIT_WEIGHT_G) * $qty;
        }

        $override = (int) ($shipment->weight_g ?? 0);
        $dims = $this->dimensionsOf($shipment);

        return [
            'units'      => $units,
            'weight_g'   => $override > 0 ? $override : max(1, $weight),
            'length_cm'  => $dims['length'],
            'breadth_cm' => $dims['breadth'],
            'height_cm'  => $dims['height'],
            'packages'   => max(1, $this->packageCount($shipment)),
            // TRUE when nothing here carried a real weight and nobody measured the box, so the
            // UI can say "estimated" instead of implying somebody weighed it.
            'estimated'  => $override <= 0 && !$anyRealWeight,
        ];
    }

    /**
     * The box booking will declare: the operator's override when COMPLETE, else the largest
     * product box when COMPLETE, else the configured default. All-or-nothing at each step —
     * mixing a real length with a default height describes no box that exists.
     *
     * @return array{length:float, breadth:float, height:float}
     */
    private function dimensionsOf(Shipment $shipment): array
    {
        $override = [
            'length'  => (float) ($shipment->length_cm ?? 0),
            'breadth' => (float) ($shipment->breadth_cm ?? 0),
            'height'  => (float) ($shipment->height_cm ?? 0),
        ];
        if ($override['length'] > 0 && $override['breadth'] > 0 && $override['height'] > 0) {
            return $override;
        }

        $fromProducts = ['length' => 0.0, 'breadth' => 0.0, 'height' => 0.0];
        foreach ($shipment->items as $item) {
            $product = $item->product ?? null;
            if (!$product) {
                continue;
            }
            $fromProducts['length']  = max($fromProducts['length'], (float) ($product->length ?? 0));
            $fromProducts['breadth'] = max($fromProducts['breadth'], (float) ($product->breadth ?? 0));
            $fromProducts['height']  = max($fromProducts['height'], (float) ($product->height ?? 0));
        }
        if ($fromProducts['length'] > 0 && $fromProducts['breadth'] > 0 && $fromProducts['height'] > 0) {
            return $fromProducts;
        }

        $default = $this->courier->defaultPackage();

        return [
            'length'  => (float) ($default['length'] ?? 20),
            'breadth' => (float) ($default['breadth'] ?? 15),
            'height'  => (float) ($default['height'] ?? 15),
        ];
    }

    /** Guarded: shipment_packages post-dates some deployments, and this runs on a read endpoint. */
    private function packageCount(Shipment $shipment): int
    {
        try {
            return Schema::hasTable('shipment_packages') ? $shipment->packages()->count() : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * How this parcel COULD be broken up so each piece fits $capacityKg.
     *
     * Greedy, heaviest-first bin fill. Returns proposed groups only and creates NOTHING —
     * the admin accepts, keeps together, or splits by hand. A single group back means the
     * parcel already fits and the refusal was about something other than weight.
     *
     * ponytail: greedy heaviest-first, not optimal bin packing. Revisit if operators
     * routinely override the proposal.
     *
     * @return array{capacity_kg:float, fits:bool, groups:array<int, array{items:array<int,array{order_item_id:int,name:?string,quantity:int,weight_g:int}>, weight_g:int}>}
     */
    public function proposeSplit(Shipment $shipment, ?float $capacityKg = null): array
    {
        $shipment->loadMissing('items.product');
        $capacity = $capacityKg !== null && $capacityKg > 0 ? $capacityKg : self::FALLBACK_CAPACITY_KG;
        $capacityG = (int) round($capacity * 1000);

        $lines = [];
        foreach ($shipment->items as $item) {
            $product = $item->product ?? null;
            $per = $product && (int) ($product->weight ?? 0) > 0
                ? (int) $product->weight
                : self::BOOKING_UNIT_WEIGHT_G;
            $lines[] = [
                'order_item_id' => (int) $item->id,
                'name'          => $product->name ?? null,
                'quantity'      => $item->shipped_qty,
                'weight_g'      => $per * $item->shipped_qty,
            ];
        }

        usort($lines, fn ($a, $b) => $b['weight_g'] <=> $a['weight_g']);

        $groups = [];
        foreach ($lines as $line) {
            $placed = false;
            foreach ($groups as $i => $group) {
                if ($group['weight_g'] + $line['weight_g'] <= $capacityG) {
                    $groups[$i]['items'][] = $line;
                    $groups[$i]['weight_g'] += $line['weight_g'];
                    $placed = true;
                    break;
                }
            }
            if (!$placed) {
                // A single line heavier than the vehicle still gets its own group: the split
                // cannot help, but showing it alone is what makes that obvious to the operator.
                $groups[] = ['items' => [$line], 'weight_g' => $line['weight_g']];
            }
        }

        // Compare the WEIGHT to the capacity. Deriving this from the group count alone said
        // "fits" for a single line heavier than the entire vehicle — the greedy loop always
        // opens a group for the heaviest line, so one over-capacity line still yields one group.
        $heaviest = 0;
        foreach ($groups as $group) {
            $heaviest = max($heaviest, (int) $group['weight_g']);
        }

        return [
            'capacity_kg' => $capacity,
            'fits'        => count($groups) <= 1 && $heaviest <= $capacityG,
            'groups'      => array_values($groups),
        ];
    }
}
