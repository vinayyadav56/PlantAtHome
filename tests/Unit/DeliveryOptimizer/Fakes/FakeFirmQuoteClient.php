<?php

namespace Tests\Unit\DeliveryOptimizer\Fakes;

use Marvel\Services\DeliveryOptimizer\Contracts\FirmQuoteClientInterface;
use Marvel\Services\DeliveryOptimizer\Dto\QuoteResult;

/**
 * A firm client that either returns a fixed firm fee, returns nothing (cache miss with no
 * carrier result), or throws — to exercise DefaultShippingQuoteClient's fallback policy.
 */
final class FakeFirmQuoteClient implements FirmQuoteClientInterface
{
    public int $calls = 0;

    /**
     * @param string $mode 'fee' (return firm fee), 'empty' (no result), 'throw' (raise)
     */
    public function __construct(private string $mode = 'fee', private float $fee = 123.0)
    {
    }

    public function quoteMany(array $reqs): array
    {
        $this->calls++;
        if ($this->mode === 'throw') {
            throw new \RuntimeException('shipping-service down');
        }
        if ($this->mode === 'empty') {
            return [];
        }
        $out = [];
        foreach ($reqs as $r) {
            $out[$r->legKey()] = new QuoteResult($this->fee, 4, QuoteResult::FIRM, 'borzo');
        }
        return $out;
    }
}
