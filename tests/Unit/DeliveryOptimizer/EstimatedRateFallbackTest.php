<?php

namespace Tests\Unit\DeliveryOptimizer;

use Marvel\Services\DeliveryOptimizer\Dto\CartItem;
use Marvel\Services\DeliveryOptimizer\Dto\QuoteRequest;
use Marvel\Services\DeliveryOptimizer\Dto\QuoteResult;
use Marvel\Services\DeliveryOptimizer\Quote\DefaultShippingQuoteClient;
use Marvel\Services\DeliveryOptimizer\Quote\EstimatedRateQuoter;
use Marvel\Services\DeliveryOptimizer\Support\Rail;
use PHPUnit\Framework\TestCase;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeConfig;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeFirmQuoteClient;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeQuoteCache;

/**
 * The estimate-first-with-firm-at-checkout policy: browse never calls the carrier; checkout
 * does (and caches it); a firm failure degrades to the estimate; a cached firm always wins.
 */
final class EstimatedRateFallbackTest extends TestCase
{
    private function req(bool $firm): QuoteRequest
    {
        return new QuoteRequest(
            1,
            Rail::INSTANT,
            [new CartItem(1, null, 1, 500)],
            500,
            50.0,
            0.0,
            '122001',
            $firm,
        );
    }

    public function test_browse_uses_estimate_and_never_calls_carrier(): void
    {
        $firm = new FakeFirmQuoteClient('fee', 123.0);
        $client = new DefaultShippingQuoteClient(new FakeQuoteCache(), new EstimatedRateQuoter(), $firm, new FakeConfig());

        $res = $client->quote($this->req(false));

        $this->assertSame(QuoteResult::ESTIMATE, $res->source);
        $this->assertEqualsWithDelta(50.0, $res->fee, 0.001);
        $this->assertSame(0, $firm->calls, 'Browse must not hit the shipping-service');
    }

    public function test_checkout_gets_firm_quote_and_caches_it(): void
    {
        $cache = new FakeQuoteCache();
        $firm = new FakeFirmQuoteClient('fee', 123.0);
        $client = new DefaultShippingQuoteClient($cache, new EstimatedRateQuoter(), $firm, new FakeConfig());

        $res = $client->quote($this->req(true));

        $this->assertSame(QuoteResult::FIRM, $res->source);
        $this->assertEqualsWithDelta(123.0, $res->fee, 0.001);
        $this->assertNotEmpty($cache->store, 'Firm quote is written back to cache');

        // A subsequent browse now serves the cached FIRM price without re-calling the carrier.
        $res2 = $client->quote($this->req(false));
        $this->assertSame(QuoteResult::FIRM, $res2->source);
        $this->assertSame(1, $firm->calls, 'Second call served from cache');
    }

    public function test_firm_failure_falls_back_to_estimate_without_throwing(): void
    {
        $firm = new FakeFirmQuoteClient('throw');
        $client = new DefaultShippingQuoteClient(new FakeQuoteCache(), new EstimatedRateQuoter(), $firm, new FakeConfig());

        $res = $client->quote($this->req(true));

        $this->assertSame(QuoteResult::ESTIMATE, $res->source, 'Degrades to estimate, never blocks');
        $this->assertEqualsWithDelta(50.0, $res->fee, 0.001);
    }

    public function test_firm_empty_result_falls_back_to_estimate(): void
    {
        $firm = new FakeFirmQuoteClient('empty');
        $client = new DefaultShippingQuoteClient(new FakeQuoteCache(), new EstimatedRateQuoter(), $firm, new FakeConfig());

        $res = $client->quote($this->req(true));

        $this->assertSame(QuoteResult::ESTIMATE, $res->source);
    }
}
