<?php

namespace Marvel\Services\DeliveryOptimizer\Contracts;

use Marvel\Services\DeliveryOptimizer\Dto\QuoteRequest;

/**
 * The firm (live-carrier) quote path, isolated behind an interface so the estimate-first
 * policy in DefaultShippingQuoteClient is unit-testable without the network/container, and
 * so the firm path can move to a Go/Python worker unchanged.
 */
interface FirmQuoteClientInterface
{
    /**
     * @param QuoteRequest[] $reqs
     * @return array<string, \Marvel\Services\DeliveryOptimizer\Dto\QuoteResult> keyed by legKey; only firm hits.
     */
    public function quoteMany(array $reqs): array;
}
