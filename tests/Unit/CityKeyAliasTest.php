<?php

namespace Tests\Unit;

use Marvel\Services\AvailabilityService;
use Tests\TestCase;

/**
 * The projection is WRITTEN with normalizeCityKey and READ back through it, and
 * vendor_service_areas.city holds whatever the vendor typed. If those two ever
 * disagree a vendor's entire catalogue goes invisible in their own city — which
 * is exactly what happened before the write side was normalized.
 */
class CityKeyAliasTest extends TestCase
{
    private AvailabilityService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AvailabilityService();
    }

    /** @test */
    public function it_normalizes_aliases_to_one_canonical_key(): void
    {
        $this->assertSame('gurugram', $this->svc->normalizeCityKey('Gurgaon'));
        $this->assertSame('gurugram', $this->svc->normalizeCityKey('  GURUGRAM '));
        $this->assertSame('bengaluru', $this->svc->normalizeCityKey('Bangalore'));
        $this->assertSame('delhi', $this->svc->normalizeCityKey('South West Delhi'));
        // Unknown cities pass through lowercased — never dropped.
        $this->assertSame('panipat', $this->svc->normalizeCityKey('Panipat'));
    }

    /** @test */
    public function every_variant_round_trips_back_to_its_canonical_key(): void
    {
        foreach (['gurugram', 'bengaluru', 'mumbai', 'kolkata', 'chennai', 'delhi'] as $key) {
            $variants = $this->svc->cityKeyVariants($key);
            $this->assertContains($key, $variants, "$key must include itself");
            foreach ($variants as $raw) {
                $this->assertSame(
                    $key,
                    $this->svc->normalizeCityKey($raw),
                    "service area spelled '$raw' must resolve to '$key'",
                );
            }
        }
    }

    /** @test */
    public function variants_are_reachable_from_an_alias_not_just_the_canonical_key(): void
    {
        // An admin city row named "Gurgaon" must find "Gurugram" service areas
        // and vice versa — the lookup normalizes its input first.
        $this->assertEqualsCanonicalizing(
            $this->svc->cityKeyVariants('Gurgaon'),
            $this->svc->cityKeyVariants('gurugram'),
        );
        $this->assertContains('gurgaon', $this->svc->cityKeyVariants('Gurugram'));
    }

    /** @test */
    public function a_city_with_no_aliases_yields_exactly_itself(): void
    {
        $this->assertSame(['panipat'], $this->svc->cityKeyVariants('Panipat'));
    }

    /**
     * @test
     *
     * The stock gate is a raw OR inside a WHERE full of ANDs. Unparenthesised,
     * SQL precedence reassociates it into
     *   (city = X AND ... AND stock IS NULL) OR (stock > 0)
     * and every OTHER city's in-stock rows satisfy the second branch — the city
     * scope silently returns the whole catalogue. This shipped once; it asserts
     * on the generated SQL so it cannot ship again.
     */
    public function the_stock_gate_is_parenthesised_so_it_cannot_leak_other_cities(): void
    {
        $sql = $this->svc->availabilityProductIdQuery('Delhi')->toSql();

        $this->assertStringContainsString('COALESCE(stock_override, stock)', $sql);
        // The OR must sit inside its own group, ANDed onto the city predicates.
        $this->assertMatchesRegularExpression(
            '/and\s+\(COALESCE\(stock_override, stock\) IS NULL OR/i',
            $sql,
            "the stock gate lost its parentheses — the city scope now leaks:\n$sql",
        );
    }
}
