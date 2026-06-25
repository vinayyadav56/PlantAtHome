<?php

namespace Marvel\Services\DeliveryOptimizer\Quote;

use Marvel\Database\Models\Shop;
use Marvel\Services\Courier\ShippingServiceClient;
use Marvel\Services\DeliveryOptimizer\Contracts\FirmQuoteClientInterface;
use Marvel\Services\DeliveryOptimizer\Contracts\OptimizerConfigInterface;
use Marvel\Services\DeliveryOptimizer\Dto\QuoteRequest;
use Marvel\Services\DeliveryOptimizer\Dto\QuoteResult;

/**
 * The checkout-time quoter: builds a PROSPECTIVE-shipment payload from cart primitives
 * (no persisted Shipment) and asks the shipping-service for a firm carrier rate via the
 * batch endpoint (which falls back to parallel singles until the batch route ships).
 *
 * Every call rides ShippingServiceClient's never-throws contract plus the optimizer's
 * tight timeout, so a slow/absent shipping-service degrades to the estimate upstream — it
 * never blocks the user.
 */
final class FirmQuoteClient implements FirmQuoteClientInterface
{
    /** @var array<int, array<string, mixed>> vendorId => pickup address (per-request memo) */
    private array $pickupMemo = [];

    public function __construct(
        private ShippingServiceClient $client,
        private OptimizerConfigInterface $config,
    ) {
    }

    /**
     * @param QuoteRequest[] $reqs
     * @return array<string, QuoteResult> keyed by legKey "vendorId|rail" (only firm hits)
     */
    public function quoteMany(array $reqs): array
    {
        if (empty($reqs) || !$this->client->configured()) {
            return [];
        }

        $bodies = [];
        $refToLeg = [];
        foreach ($reqs as $r) {
            $ref = $r->eventId();
            $refToLeg[$ref] = $r->legKey();
            $bodies[] = ['ref' => $ref] + $this->payload($r);
        }

        $res = $this->client->quoteBatch($bodies, $this->config->firmTimeoutMs());

        $out = [];
        foreach (($res['results'] ?? []) as $row) {
            $leg = $refToLeg[$row['ref'] ?? ''] ?? null;
            if ($leg === null) {
                continue;
            }
            $cheapest = $row['cheapest'] ?? null;
            if (!empty($row['ok']) && is_array($cheapest)) {
                $out[$leg] = new QuoteResult(
                    (float) ($cheapest['cost'] ?? 0),
                    (int) ($cheapest['eta_days'] ?? 0),
                    QuoteResult::FIRM,
                    $cheapest['partner'] ?? null,
                );
            }
        }
        return $out;
    }

    /** Build the /v1/quotes body from a prospective leg (mirrors ShippingServiceClient::buildRequest). */
    private function payload(QuoteRequest $r): array
    {
        return [
            'shipment_ref' => $r->eventId(),
            'order_ref'    => $r->eventId(),
            'shop_ref'     => (string) $r->vendorId,
            'mode'         => $r->shippingMode(),
            'cod'          => $r->cod,
            'cod_amount'   => $r->cod ? round($r->codAmount, 2) : 0,
            'pickup'       => $this->pickupFor($r->vendorId),
            'drop'         => ['pincode' => (string) ($r->destPincode ?? ''), 'lat' => 0, 'lng' => 0],
            'items'        => array_map(fn ($it) => [
                'name'       => 'Item #' . $it->productId,
                'sku'        => 'SKU-' . $it->productId,
                'qty'        => $it->qty,
                'unit_price' => 0,
                'weight_g'   => $it->unitWeightG,
            ], $r->items),
            'weight_g'     => max(1, $r->chargeableWeightG),
        ];
    }

    private function pickupFor(int $vendorId): array
    {
        if (isset($this->pickupMemo[$vendorId])) {
            return $this->pickupMemo[$vendorId];
        }
        $shop = Shop::find($vendorId);
        $a = (array) ($shop->address ?? []);
        return $this->pickupMemo[$vendorId] = [
            'name'    => $shop->name ?? 'Vendor',
            'address' => $a['street_address'] ?? $a['address'] ?? '',
            'city'    => $a['city'] ?? '',
            'state'   => $a['state'] ?? '',
            'pincode' => $shop->pickup_postcode ?? $a['zip'] ?? $a['pincode'] ?? '',
            'lat'     => (float) ($a['lat'] ?? $a['latitude'] ?? 0),
            'lng'     => (float) ($a['lng'] ?? $a['longitude'] ?? 0),
        ];
    }
}
